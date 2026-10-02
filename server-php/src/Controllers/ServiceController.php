<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Domain\AppointmentTemplates;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Domain\ConversationService;
use Aicountly\Api\Domain\DispatchGuard;
use Aicountly\Api\Domain\DispatchService;
use Aicountly\Api\Domain\Idempotency;
use Aicountly\Api\Domain\MessageService;
use Aicountly\Api\Domain\MessageState;
use Aicountly\Api\Domain\Period;
use Aicountly\Api\Domain\ServiceConsent;
use Aicountly\Api\Domain\ServiceDelivery;
use Aicountly\Api\Domain\TemplateService;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;

/**
 * The API other Aicountly products call.
 *
 * ## The contract
 *
 * The wire contract for Appointments' client notices is written down once, in
 * `docs/APPOINTMENTS_MESSAGING_CONTRACT.md` (the same file in both
 * repositories): company context, E.164 recipient, per-kind templates, consent
 * basis, idempotency, `not_after`, and the delivery states reported back.
 * This class implements it; it is not a second source of truth, and a change
 * here that the document does not say is a bug in one of them.
 *
 * ## What a 2xx means
 *
 * ACCEPTED, never delivered. 202 says "Messaging has this and owns it from here"
 * and `data.delivery_state` says how far it got (queued, sent, unknown). A
 * message that did not go and will not is NOT a 2xx: failed is 502,
 * suppressed/expired is 422, and the body still carries `data.message_uuid` when
 * a row exists so the caller can show what happened. A caller that wants to say
 * "delivered" must read `delivery_state` on a later GET.
 *
 * ## What a sibling product may and may not do here
 *
 * MAY: ask for a message to be sent, using an approved template, to an E.164
 * address, with variables, against a reference — and tell us the consent it
 * captured (see Domain/ServiceConsent).
 *
 * MAY NOT: bypass consent, bypass suppression, send an unapproved template,
 * claim a message origin, read another product's messages, or write to a company
 * it has no key for. Every one of those goes through the same DispatchGuard as a
 * message an agent types. A service key is a way in, not a way round.
 *
 * ## Idempotency is not optional
 *
 * Appointments retries on a timeout, as it should. Without the idempotency
 * table that retry is a second reminder to a customer. A refusal that happened
 * BEFORE any message existed (no template yet, no consent yet) is not stored: an
 * administrator may fix it within minutes, and the same key must then be able to
 * succeed. Only an answer about a message that exists is replayed — and it is
 * replayed with the message's CURRENT state, not the stale one.
 */
final class ServiceController
{
    /** The kinds of notice a caller may name. Free text would make the stored kind meaningless. */
    private const KIND_PATTERN = '/^[a-z][a-z_]{0,23}$/';

    /** What a phone-shaped address must be: E.164, a plus and 8 to 15 digits, no spaces, no guessing. */
    private const E164_PATTERN = '/^\+[1-9][0-9]{7,14}$/';

    /**
     * POST /api/v1/messages
     *
     * @return void
     */
    public static function send(): void
    {
        $auth = Auth::require();

        // Service callers only. A browser session reaching this would be a
        // browser asking to send as a product, which is exactly the origin
        // laundering Auth::provenOrigin exists to prevent.
        if (!$auth->isService()) {
            Http::forbidden(
                'This endpoint is for Aicountly product backends presenting a service key. '
                . 'Use /api/v1/conversations/{uuid}/send for a signed-in user.',
            );
        }

        $body = Http::body();
        // cmp_id is read from the JSON body (or the query string). A service
        // call without it is a 400, not a guess: there is no company a key maps to.
        $ctx = Context::fromRequest();
        // The company must be bound to this product, or be one the forwarded
        // person belongs to per Manage — never just the cmp_id it names (G19#7).
        $ctx->assertAllowed($auth);

        $idempotencyKey = Http::idempotencyKey();
        if ($idempotencyKey === '') {
            Http::validationFailed(
                'An Idempotency-Key header is required on this endpoint (8–200 characters of A-Za-z0-9._:-). '
                . 'Without one, a retry after a timeout would send the customer a second message.',
            );
        }

        $replay = Idempotency::replay($ctx, $auth, 'v1.messages.send', $idempotencyKey, $body);
        if ($replay !== null) {
            $answer = self::refreshReplay($ctx, $replay);
            Http::json($answer['status'], $answer['body'] + [
                'replayed' => true,
                'replay_note' => 'This is the original answer to this idempotency key, with the message\'s current '
                    . 'state. Nothing was sent again.',
            ]);
        }

        if (!Idempotency::claim($ctx, $auth, 'v1.messages.send', $idempotencyKey, $body)) {
            Http::conflict('An identical request is being processed. Retry in a moment.', ['retryable' => true]);
        }

        try {
            $result = self::dispatch($ctx, $auth, $body);
        } catch (\Throwable $e) {
            // Release the key so a genuine retry is not poisoned by our own
            // failure.
            Idempotency::release($ctx, $auth, $idempotencyKey);
            throw $e;
        }

        if ($result['store']) {
            Idempotency::complete($ctx, $auth, $idempotencyKey, $result['status'], $result['payload']);
        } else {
            // Nothing was created, so there is nothing to replay. The caller may
            // fix the cause (approve a template, connect a channel, record
            // consent) and send the SAME key again.
            Idempotency::release($ctx, $auth, $idempotencyKey);
        }

        $retryAfter = (int) ($result['payload']['error']['details']['retry_after'] ?? 0);
        if ($retryAfter > 0 && PHP_SAPI !== 'cli') {
            header('Retry-After: ' . $retryAfter);
        }

        Http::json($result['status'], $result['payload']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status:int, payload:array<string, mixed>, store:bool}
     */
    private static function dispatch(Context $ctx, Auth $auth, array $body): array
    {
        $channel = strtolower(trim((string) ($body['channel'] ?? '')));
        $to = trim((string) ($body['to'] ?? ''));
        $templateName = trim((string) ($body['template'] ?? ''));
        $variables = is_array($body['variables'] ?? null) ? $body['variables'] : [];
        $language = (string) ($body['language'] ?? 'en') ?: 'en';

        // The reference the caller gave: a string (the booking uuid), or, for
        // callers written against the old documentation, {product, id, label}.
        $reference = '';
        $referenceLabel = trim((string) ($body['reference_label'] ?? ''));
        if (is_array($body['reference'] ?? null)) {
            $reference = trim((string) ($body['reference']['id'] ?? ''));
            $referenceLabel = $referenceLabel !== '' ? $referenceLabel : trim((string) ($body['reference']['label'] ?? ''));
        } else {
            $reference = trim((string) ($body['reference'] ?? ''));
        }

        // Voice is not Messaging's. The published MessagingClient already
        // refuses it on its side; refusing it here too means the boundary
        // holds even if a future caller forgets.
        if ($channel === 'voice') {
            return self::refusal(422, 'voice_belongs_to_receptionist',
                'Voice calls are not Messaging\'s. Aicountly Lobby (formerly Receptionist) and Aicountly Voice own '
                . 'telephony; this product does not implement a telephony engine.', ['retryable' => false]);
        }
        if ($channel === 'email') {
            return self::refusal(422, 'email_not_enabled',
                'Email is not a channel this deployment of Messaging sends on. Aicountly Email owns email delivery.',
                ['retryable' => false]);
        }
        if (!in_array($channel, ['whatsapp', 'rcs', 'sms'], true)) {
            return self::refusal(422, 'unknown_channel', 'Unknown channel "' . $channel . '".', ['retryable' => false]);
        }
        if ($to === '') {
            return self::refusal(422, 'validation_failed', 'A destination address is required.',
                ['retryable' => false, 'field' => 'to']);
        }
        // A phone number is E.164 or it is refused. Guessing a country code
        // from a local number sends a customer's name and appointment to a
        // stranger; the caller owns normalising what it was typed.
        if (preg_match(self::E164_PATTERN, $to) !== 1) {
            return self::refusal(422, 'invalid_address',
                'The destination must be an E.164 phone number: a plus sign and 8–15 digits, no spaces '
                . '(for example +919876543210). Messaging does not guess a country code.',
                ['retryable' => false, 'field' => 'to']);
        }
        if ($templateName === '') {
            return self::refusal(422, 'validation_failed',
                'A template name is required. A business-initiated message needs a provider-approved template.',
                ['retryable' => false, 'field' => 'template']);
        }

        $kind = strtolower(trim((string) ($body['kind'] ?? '')));
        if ($kind !== '' && preg_match(self::KIND_PATTERN, $kind) !== 1) {
            return self::refusal(422, 'validation_failed', 'kind must be lower-case words, for example "reminder".',
                ['retryable' => false, 'field' => 'kind']);
        }

        $scheduledFor = null;
        if (trim((string) ($body['scheduled_for'] ?? '')) !== '') {
            $scheduledFor = Clock::parse((string) $body['scheduled_for']);
            if ($scheduledFor === null) {
                return self::refusal(422, 'validation_failed', 'scheduled_for must be an ISO 8601 instant.',
                    ['retryable' => false, 'field' => 'scheduled_for']);
            }
        }

        $notAfter = null;
        $notAfterRaw = trim((string) ($body['not_after'] ?? ''));
        if ($notAfterRaw !== '') {
            $notAfter = Clock::parse($notAfterRaw);
            if ($notAfter === null || preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $notAfterRaw) !== 1) {
                return self::refusal(422, 'validation_failed',
                    'not_after must be an ISO 8601 instant with an offset (or Z).',
                    ['retryable' => false, 'field' => 'not_after']);
            }
        }

        $parsedConsent = ServiceConsent::parse($body['consent'] ?? null);
        if (!$parsedConsent['ok']) {
            return self::refusal(422, 'validation_failed', (string) $parsedConsent['error'],
                ['retryable' => false, 'field' => $parsedConsent['field']]);
        }
        $consentObject = $parsedConsent['consent'];

        // EXPIRY, before anything is created. A reminder whose appointment has
        // started is never worth sending, and a late retry is the usual way
        // one gets here.
        if ($notAfter !== null && Clock::now() >= $notAfter) {
            return self::refusal(422, 'expired',
                'not_after has passed, so this message is no longer worth delivering and was not sent.',
                ['retryable' => false, 'delivery_state' => ServiceDelivery::EXPIRED, 'reason_code' => 'expired']);
        }

        // RATE LIMITS. A caller bug (a loop) or a hostile booking form must not
        // turn Messaging into a way to message one person repeatedly.
        $limited = self::rateLimited($ctx, $auth, $to);
        if ($limited !== null) {
            return $limited;
        }

        // The connection to send on.
        $connectionRow = Db::first(
            'SELECT * FROM messaging_channel_connections
             WHERE cmp_id = :cmp AND channel = :channel AND is_active = TRUE
             ORDER BY CASE WHEN status = \'connected\' THEN 0 ELSE 1 END, created_at
             LIMIT 1',
            ['cmp' => $ctx->cmpId, 'channel' => $channel],
        );
        if ($connectionRow === null) {
            return self::refusal(422, 'channel_not_configured',
                'No ' . $channel . ' channel is connected for this company, so nothing can be sent.',
                ['retryable' => true]);
        }
        $connection = ChannelConnection::fromRow($connectionRow);

        $adapter = ChannelRegistry::adapterFor($connection);
        $gap = $adapter?->configurationGap($connection);
        if ($adapter === null || $gap !== null) {
            return self::refusal(422, 'channel_not_configured',
                $gap ?? 'No adapter is installed for provider "' . $connection->provider . '".',
                ['retryable' => true]);
        }

        // The template, by name, and only an APPROVED version of it.
        $template = Db::first(
            'SELECT template_uuid FROM messaging_templates
             WHERE cmp_id = :cmp AND channel = :channel AND name = :name AND is_active = TRUE',
            ['cmp' => $ctx->cmpId, 'channel' => $channel, 'name' => $templateName],
        );
        if ($template === null) {
            return self::refusal(404, 'template_missing',
                'No template named "' . $templateName . '" exists on the ' . $channel . ' channel for this company.',
                ['retryable' => true]);
        }

        $version = TemplateService::sendableVersion($ctx, (string) $template['template_uuid'], $language);
        if ($version === null) {
            return self::refusal(422, 'template_not_approved',
                'The template "' . $templateName . '" has no provider-approved version in ' . $language
                . '. Templates cannot be dispatched until the provider approves them.',
                ['retryable' => true]);
        }

        // CONSENT, before anything is even drafted. First what the caller
        // captured (when it is allowed to say so), then the check that decides.
        // A sibling product asking for a reminder does not override a
        // customer's opt-out, and the check below reads the records the line
        // above may just have written — it never takes the caller's word.
        $purpose = self::purposeFor($auth->sourceApp);
        $consentApplied = null;
        if ($consentObject !== null && $purpose === 'transactional' && ServiceConsent::callerMayRecord($auth->sourceApp)) {
            $consentApplied = ServiceConsent::apply($ctx, $auth, $channel, $to, $consentObject);
        }

        $consent = ConsentService::evaluate($ctx, $channel, $to, $purpose);
        if (!$consent['allowed']) {
            return self::refusal(
                422,
                $consent['reason'] === 'suppressed' ? 'suppressed' : 'no_consent',
                $consent['detail'],
                [
                    'retryable'      => false,
                    'customer_decision' => true,
                    'purpose'        => $purpose,
                    'delivery_state' => ServiceDelivery::forConsentRefusal(),
                    // The finer word: no_consent | consent_pending | withdrawn | suppressed | no_address.
                    'reason_code'    => $consent['reason'],
                    'consent_recorded' => $consentApplied['action'] ?? null,
                ],
            );
        }

        $conversationUuid = ConversationService::findOrOpenForInbound(
            $ctx,
            $connection->connectionUuid,
            $channel,
            $to,
        );

        if (isset($body['contact_uuid']) && is_string($body['contact_uuid']) && $body['contact_uuid'] !== '') {
            Db::run(
                'UPDATE messaging_conversations SET contact_uuid = COALESCE(contact_uuid, :contact)
                 WHERE conversation_uuid = :uuid',
                ['contact' => (string) $body['contact_uuid'], 'uuid' => $conversationUuid],
            );
        }

        // Bind the declared variables, in schema order. `sender_name` is the one
        // Messaging fills in itself: the sending company's identity is the
        // display name of its connected sender, and a caller has no business
        // claiming it.
        $bound = [];
        $missing = [];
        foreach (Db::jsonColumn($version['variable_schema'] ?? null) as $declared) {
            $name = (string) ($declared['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $value = $variables[$name] ?? null;
            if (in_array($name, AppointmentTemplates::BUILT_IN, true)) {
                $value = $connection->displayName;
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                $bound[$name] = (string) $value;
            } elseif ((bool) ($declared['required'] ?? true)) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            return self::refusal(422, 'variables_missing',
                'These template variables have no value: ' . implode(', ', $missing) . '.',
                ['missing' => $missing, 'retryable' => false]);
        }

        $saved = MessageService::saveDraft($ctx, $auth, $conversationUuid, [
            'body'               => TemplateService::render((string) $version['body'], $bound),
            'content_type'       => 'template',
            'language'           => (string) $version['language'],
            'template_uuid'      => (string) $template['template_uuid'],
            'template_version'   => (int) $version['version'],
            'template_variables' => $bound,
        ]);
        if (!$saved['ok']) {
            return self::refusal(422, $saved['code'], $saved['detail'], ['retryable' => false]);
        }

        $messageUuid = (string) $saved['message_uuid'];

        // What the caller said this message is, kept with it. service_app is
        // proven by the key and is what a later status read is scoped to.
        Db::run(
            'UPDATE messaging_messages
             SET service_app = :app, service_kind = :kind, service_reference = :ref,
                 service_reference_label = :label, scheduled_for = :scheduled, not_after = :not_after
             WHERE message_uuid = :uuid',
            [
                'app'       => $auth->sourceApp,
                'kind'      => $kind !== '' ? $kind : null,
                'ref'       => $reference !== '' ? $reference : null,
                'label'     => $referenceLabel !== '' ? $referenceLabel : null,
                'scheduled' => $scheduledFor?->format('Y-m-d H:i:sP'),
                'not_after' => $notAfter?->format('Y-m-d H:i:sP'),
                'uuid'      => $messageUuid,
            ],
        );

        // The reference the calling product gave us, stored as a reference.
        if ($reference !== '') {
            $ownerProduct = self::productFor($auth->sourceApp);
            if ($ownerProduct !== null) {
                Db::run(
                    'INSERT INTO messaging_external_references
                        (cmp_id, entity_type, entity_uuid, owner_product, external_id, external_label, relationship, created_at)
                     VALUES (:cmp, :type, :entity, :product, :ext, :label, :rel, :now)
                     ON CONFLICT DO NOTHING',
                    [
                        'cmp' => $ctx->cmpId, 'type' => 'message', 'entity' => $messageUuid,
                        'product' => $ownerProduct, 'ext' => $reference,
                        'label' => $referenceLabel !== '' ? $referenceLabel : $reference,
                        'rel' => 'triggered_by', 'now' => Clock::nowSql(),
                    ],
                );
                ConversationService::linkExternal(
                    $ctx, $conversationUuid, $ownerProduct, $reference, 'subject',
                    $referenceLabel !== '' ? $referenceLabel : $reference,
                );
            }
        }

        // A service request IS the approval for its own content: the calling
        // product has already decided to send it, and its own product enforces
        // whatever review that needed. The hash is still recorded so the
        // dispatcher's comparison means something and a later edit would break
        // it.
        Db::run(
            'UPDATE messaging_messages
             SET status = :approved, approved_at = :now, approved_by_uuid = :actor,
                 approved_content_hash = content_hash
             WHERE message_uuid = :uuid',
            [
                'approved' => MessageState::APPROVED,
                'now'      => Clock::nowSql(),
                'actor'    => $auth->uuid,
                'uuid'     => $messageUuid,
            ],
        );

        $message = MessageService::find($ctx, $messageUuid);
        if ($message === null) {
            return self::refusal(500, 'server_error', 'The message could not be prepared.', ['retryable' => true]);
        }

        // THE SAME GATES as an agent's message. No shortcut for a service key.
        $verdict = DispatchGuard::evaluate($ctx, $message, $connection);
        if (!$verdict['allowed']) {
            DispatchService::cancel($ctx, $messageUuid, $verdict['detail'], $verdict['code']);

            return self::messageAnswer($ctx, $messageUuid, $verdict['code'], $verdict['detail'], $consentApplied);
        }

        $queued = DispatchService::enqueue($ctx, $auth, $messageUuid);
        if (!$queued['ok']) {
            return self::refusal(422, $queued['code'], $queued['detail'], ['retryable' => false]);
        }

        // Dispatched inline so the calling product gets a real answer rather
        // than a promise.
        $job = Db::first(
            'UPDATE messaging_dispatch_jobs
             SET status = :claimed, claimed_at = NOW(), claimed_by = :worker, attempts = attempts + 1
             WHERE job_uuid = :uuid AND status = :queued
             RETURNING *',
            ['claimed' => 'claimed', 'worker' => 'svc:' . $auth->sourceApp, 'uuid' => $queued['job_uuid'], 'queued' => 'queued'],
        );

        $outcome = ['outcome' => 'queued', 'detail' => 'Queued to send.'];
        if ($job !== null) {
            $outcome = DispatchService::process($job);
        }

        Audit::record($ctx, $auth, 'message.sent_via_service_api', 'message', $messageUuid, null, [
            'source_app' => $auth->sourceApp,
            'channel'    => $channel,
            'template'   => $templateName,
            'kind'       => $kind,
            'reference'  => $reference,
            'outcome'    => $outcome['outcome'],
            'consent'    => $consentApplied['action'] ?? null,
        ]);

        return self::messageAnswer($ctx, $messageUuid, $outcome['outcome'], $outcome['detail'], $consentApplied);
    }

    /**
     * The answer about a message that EXISTS, built from its stored state.
     *
     * Used for the send response and for a replay, so the two cannot disagree.
     *
     * @param array{action:string, state:?string, consent_uuid:?string}|null $consentApplied
     * @return array{status:int, payload:array<string, mixed>, store:bool}
     */
    private static function messageAnswer(
        Context $ctx,
        string $messageUuid,
        string $outcome,
        string $detail,
        ?array $consentApplied = null,
    ): array {
        $final = MessageService::find($ctx, $messageUuid);
        if ($final === null) {
            return self::refusal(500, 'server_error', 'The message could not be read back.', ['retryable' => true]);
        }

        $state = ServiceDelivery::describe($final);
        $data = self::shapeMessage($final, $state) + [
            'outcome' => $outcome,
            'detail'  => $detail,
            // The honest word. A caller that wants to report "delivered" to its
            // own user must wait for a receipt.
            'delivery_note' => 'Provider acceptance is not delivery. Read GET /api/v1/messages/{message_uuid} '
                . 'until delivery_state is delivered, failed, suppressed, expired or cancelled.',
        ];
        if ($consentApplied !== null) {
            $data['consent'] = ['action' => $consentApplied['action'], 'state' => $consentApplied['state']];
        }

        $status = self::httpStatusFor($state['delivery_state']);
        $payload = ['data' => $data, 'message' => $detail];

        if ($status >= 400) {
            $code = match ($state['delivery_state']) {
                ServiceDelivery::FAILED     => 'send_failed',
                ServiceDelivery::EXPIRED    => 'expired',
                ServiceDelivery::SUPPRESSED => ($state['reason_code'] === 'suppressed' ? 'suppressed' : 'no_consent'),
                default                     => (string) ($state['reason_code'] ?? 'cancelled'),
            };
            $payload['error'] = [
                'code'    => $code,
                'message' => $state['reason'] ?? $detail,
                'details' => [
                    'retryable'      => false,
                    'delivery_state' => $state['delivery_state'],
                    'reason_code'    => $state['reason_code'],
                    'message_uuid'   => $messageUuid,
                ],
            ];
            $payload['message'] = (string) ($state['reason'] ?? $detail);
        }

        // A message row exists, so the answer is worth replaying (with its
        // current state — see refreshReplay()).
        return ['status' => $status, 'payload' => $payload, 'store' => true];
    }

    /**
     * Re-derive a stored answer from the message's CURRENT state.
     *
     * The stored response is from the moment of the first call ("queued"); the
     * same key, minutes later, should say what happened since. A stored answer
     * with no message in it (there should be none — those are released) is
     * replayed as it was.
     *
     * @param array{status:int, body:array<string, mixed>} $replay
     * @return array{status:int, body:array<string, mixed>}
     */
    private static function refreshReplay(Context $ctx, array $replay): array
    {
        $uuid = (string) ($replay['body']['data']['message_uuid'] ?? '');
        if ($uuid === '') {
            return $replay;
        }

        $fresh = self::messageAnswer(
            $ctx,
            $uuid,
            (string) ($replay['body']['data']['outcome'] ?? 'replayed'),
            (string) ($replay['body']['data']['detail'] ?? ''),
            isset($replay['body']['data']['consent']) && is_array($replay['body']['data']['consent'])
                ? ['action' => (string) ($replay['body']['data']['consent']['action'] ?? ''),
                   'state' => $replay['body']['data']['consent']['state'] ?? null, 'consent_uuid' => null]
                : null,
        );

        return ['status' => $fresh['status'], 'body' => $fresh['payload']];
    }

    /**
     * What a 2xx / 4xx / 5xx is, from where the message ended up.
     *
     *   queued, sent, unknown   202   accepted; keep reading for the end state
     *   delivered               200
     *   failed                  502   terminal; Messaging has retried what it could
     *   suppressed, expired     422   terminal; never retry
     *   cancelled               409   withdrawn before it went
     */
    private static function httpStatusFor(string $deliveryState): int
    {
        return match ($deliveryState) {
            ServiceDelivery::DELIVERED  => 200,
            ServiceDelivery::FAILED     => 502,
            ServiceDelivery::SUPPRESSED, ServiceDelivery::EXPIRED => 422,
            ServiceDelivery::CANCELLED  => 409,
            default                     => 202,
        };
    }

    /**
     * The message as a calling product sees it.
     *
     * @param array<string, mixed> $message
     * @param array{delivery_state:string, reason_code:?string, reason:?string} $state
     * @return array<string, mixed>
     */
    private static function shapeMessage(array $message, array $state): array
    {
        $delivered = $state['delivery_state'] === ServiceDelivery::DELIVERED;

        return [
            'message_uuid'      => (string) $message['message_uuid'],
            // An alias, so a client that reads `message_id` / `id` is not
            // looking at nothing.
            'message_id'        => (string) $message['message_uuid'],
            'conversation_uuid' => (string) $message['conversation_uuid'],
            'status'            => (string) $message['status'],
            'status_label'      => MessageState::describe((string) $message['status']),
            'delivery_state'    => $state['delivery_state'],
            'reason_code'       => $state['reason_code'],
            'reason'            => $state['reason'],
            'delivered'         => $delivered,
            'terminal'          => ServiceDelivery::isTerminal($state['delivery_state']),
            'channel'           => (string) $message['channel'],
            'kind'              => $message['service_kind'] ?? null,
            'reference'         => $message['service_reference'] ?? null,
            'reference_label'   => $message['service_reference_label'] ?? null,
            'scheduled_for'     => self::iso($message['scheduled_for'] ?? null),
            'not_after'         => self::iso($message['not_after'] ?? null),
            'queued_at'         => self::iso($message['queued_at'] ?? null),
            'provider_accepted_at' => self::iso($message['provider_accepted_at'] ?? null),
            'delivered_at'      => self::iso($message['delivered_at'] ?? null),
            'failed_at'         => self::iso($message['failed_at'] ?? null),
            'provider_message_id' => $message['provider_message_id'] ?? null,
            'failure_code'      => $message['failure_code'] ?? null,
            'failure_detail'    => $message['failure_detail'] ?? null,
        ];
    }

    private static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $at = Clock::parse((string) $value);

        return $at === null ? null : $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * GET /api/v1/messages/stats
     *
     * The published contract Appointments' ClientExperienceDashboard reads.
     * Counts of OUR OWN delivery records — Messaging is the product that knows
     * what happened to a message it sent.
     */
    public static function stats(): void
    {
        $auth = Auth::require();
        if (!$auth->isService()) {
            Http::forbidden('This endpoint is for Aicountly product backends presenting a service key.');
        }

        $ctx = Context::fromRequest();
        // The company must be bound to this product, or be one the forwarded
        // person belongs to per Manage — never just the cmp_id it names (G19#7).
        $ctx->assertAllowed($auth);
        $period = Period::fromRequest($ctx, '30d');

        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        // Narrowed to what the CALLING product sent, for EVERY product: a key
        // with no mapped origin used to read the whole company's traffic.
        [$ownClause, $ownParams] = self::ownedBy($auth->sourceApp, 'm');
        $originClause = ' AND ' . $ownClause;
        $params += $ownParams;

        $reference = trim((string) (Http::param('reference') ?? ''));
        if ($reference !== '') {
            $originClause .= ' AND (m.service_reference = :reference OR EXISTS (
                                      SELECT 1 FROM messaging_external_references r
                                       WHERE r.entity_type = \'message\' AND r.entity_uuid = m.message_uuid
                                         AND r.external_id = :reference))';
            $params['reference'] = $reference;
        }

        $rows = Db::all(
            'SELECT m.channel,
                    COUNT(*) FILTER (WHERE m.status IN (\'provider_accepted\',\'delivered\',\'read\')) AS accepted,
                    COUNT(*) FILTER (WHERE m.status IN (\'delivered\',\'read\')) AS delivered,
                    COUNT(*) FILTER (WHERE m.status = \'read\') AS read_count,
                    COUNT(*) FILTER (WHERE m.status = \'failed\') AS failed,
                    COUNT(*) FILTER (WHERE m.status = \'provider_accepted\') AS unconfirmed,
                    COUNT(*) FILTER (WHERE m.status = \'submission_unknown\') AS submission_unknown,
                    COUNT(*) FILTER (WHERE m.status = \'cancelled\') AS cancelled
             FROM messaging_messages m
             WHERE ' . $scope . ' AND m.direction = \'outbound\'
               AND m.created_at >= :from AND m.created_at < :to' . $originClause . '
             GROUP BY m.channel',
            $params,
        );

        $byChannel = [];
        $totals = ['accepted' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0,
            'unconfirmed' => 0, 'submission_unknown' => 0, 'cancelled' => 0];

        foreach ($rows as $row) {
            $accepted = (int) $row['accepted'];
            $delivered = (int) $row['delivered'];

            $byChannel[] = [
                'channel'            => (string) $row['channel'],
                'accepted'           => $accepted,
                'delivered'          => $delivered,
                'read'               => (int) $row['read_count'],
                'failed'             => (int) $row['failed'],
                'unconfirmed'        => (int) $row['unconfirmed'],
                'submission_unknown' => (int) $row['submission_unknown'],
                'cancelled'          => (int) $row['cancelled'],
                'delivery_rate'      => $accepted > 0 ? round($delivered / $accepted, 4) : null,
            ];

            $totals['accepted'] += $accepted;
            $totals['delivered'] += $delivered;
            $totals['read'] += (int) $row['read_count'];
            $totals['failed'] += (int) $row['failed'];
            $totals['unconfirmed'] += (int) $row['unconfirmed'];
            $totals['submission_unknown'] += (int) $row['submission_unknown'];
            $totals['cancelled'] += (int) $row['cancelled'];
        }

        Http::data([
            'period'     => $period->describe(),
            'totals'     => $totals + [
                'delivery_rate' => $totals['accepted'] > 0
                    ? round($totals['delivered'] / $totals['accepted'], 4) : null,
            ],
            'by_channel' => $byChannel,
            // The caveat travels with the numbers, because the calling product
            // will put them on a dashboard of its own.
            'definitions' => [
                'accepted'    => 'A provider took responsibility for the message. NOT delivery.',
                'delivered'   => 'A provider confirmed the message arrived.',
                'unconfirmed' => 'Accepted with no terminal receipt yet. Counted as neither delivered nor failed.',
                'submission_unknown' => 'The send timed out after the provider may have accepted it. Not resent.',
                'delivery_rate' => 'delivered / accepted. Unconfirmed messages are in the denominator, so this rate '
                    . 'does not flatter itself.',
            ],
            'scope_note' => 'Narrowed to messages this API sent on behalf of ' . $auth->sourceApp . '.',
        ]);
    }

    /**
     * GET /api/v1/messages/{uuid}
     *
     * So a calling product can ask what happened to one message it asked for.
     * This is the delivery-state return path: Messaging does not call the
     * product back, the product reads (see the contract for why that is the
     * choice), and `delivery_state` is the answer.
     */
    public static function show(string $messageUuid): void
    {
        $auth = Auth::require();
        if (!$auth->isService()) {
            Http::forbidden('This endpoint is for Aicountly product backends presenting a service key.');
        }

        $ctx = Context::fromRequest();
        // The company must be bound to this product, or be one the forwarded
        // person belongs to per Manage — never just the cmp_id it names (G19#7).
        $ctx->assertAllowed($auth);
        $message = MessageService::find($ctx, $messageUuid);

        // Not-yours and not-there are the same answer, so a key cannot probe
        // for another product's message ids.
        if ($message === null || !self::isOwnedBy($message, $auth->sourceApp)) {
            Http::notFound('That message could not be found.');
        }

        $events = Db::all(
            'SELECT event_type, error_code, error_detail, occurred_at, received_at
             FROM messaging_delivery_events WHERE message_uuid = :uuid ORDER BY received_at',
            ['uuid' => $messageUuid],
        );

        $state = ServiceDelivery::describe($message);

        Http::data(self::shapeMessage($message, $state) + [
            'events' => $events,
        ]);
    }

    /**
     * POST /api/v1/messages/{uuid}/cancel
     *
     * Withdraw a message that has not gone yet — a reminder for an appointment
     * that was cancelled, a notice that was queued for retry behind a provider
     * incident. A message the provider already holds cannot be unsent, and the
     * answer says so (409) rather than pretending.
     */
    public static function cancel(string $messageUuid): void
    {
        $auth = Auth::require();
        if (!$auth->isService()) {
            Http::forbidden('This endpoint is for Aicountly product backends presenting a service key.');
        }

        $ctx = Context::fromRequest();
        $message = MessageService::find($ctx, $messageUuid);
        if ($message === null || !self::isOwnedBy($message, $auth->sourceApp)) {
            Http::notFound('That message could not be found.');
        }

        $reason = trim((string) (Http::param('reason') ?? '')) ?: 'Cancelled by ' . $auth->sourceApp . '.';
        $cancelled = DispatchService::cancel($ctx, $messageUuid, substr($reason, 0, 300));

        $fresh = MessageService::find($ctx, $messageUuid) ?? $message;
        $data = self::shapeMessage($fresh, ServiceDelivery::describe($fresh)) + ['cancelled' => $cancelled];

        if (!$cancelled) {
            Http::json(409, [
                'error' => [
                    'code'    => 'already_dispatched',
                    'message' => 'The provider already holds this message (or it is already finished), so it cannot be withdrawn.',
                    'details' => ['retryable' => false, 'delivery_state' => $data['delivery_state']],
                ],
                'data'    => $data,
                'message' => 'The message could not be withdrawn.',
            ]);
        }

        Audit::record($ctx, $auth, 'message.cancelled_via_service_api', 'message', $messageUuid, null, [
            'source_app' => $auth->sourceApp,
            'reason'     => $reason,
        ]);

        Http::data($data);
    }

    // -----------------------------------------------------------------------

    /**
     * What a message from this product is for.
     *
     * Decided by the credential. A caller cannot declare its own purpose and
     * thereby satisfy a narrower consent grant than it should.
     */
    private static function purposeFor(string $sourceApp): string
    {
        return match ($sourceApp) {
            'reach'  => 'promotional',
            default  => 'transactional',
        };
    }

    private static function originFor(string $sourceApp): ?string
    {
        return match ($sourceApp) {
            'appointments' => 'APPOINTMENTS',
            'billing'      => 'BILLING',
            'books'        => 'BOOKS',
            'sales'        => 'SALES',
            'pos'          => 'POS',
            'reach'        => 'REACH',
            default        => null,
        };
    }

    private static function productFor(string $sourceApp): ?string
    {
        return in_array($sourceApp, [
            'contacts', 'books', 'sales', 'purchases', 'billing', 'pay',
            'appointments', 'calendar', 'inventory', 'drive', 'reach', 'pos', 'manage',
        ], true) ? $sourceApp : null;
    }

    /**
     * Did THIS product cause this message?
     *
     * `service_app` is written from the key on every service send. Rows from
     * before it existed have none, and are matched by origin where the product
     * has one — never for a product with no mapped origin, which therefore
     * reads nothing it did not create.
     *
     * @param array<string, mixed> $message
     */
    private static function isOwnedBy(array $message, string $sourceApp): bool
    {
        $app = $message['service_app'] ?? null;
        if ($app !== null) {
            return (string) $app === $sourceApp;
        }
        $origin = self::originFor($sourceApp);

        return $origin !== null && (string) ($message['origin'] ?? '') === $origin;
    }

    /**
     * The same rule as isOwnedBy(), as SQL.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    private static function ownedBy(string $sourceApp, string $alias): array
    {
        $origin = self::originFor($sourceApp);
        if ($origin === null) {
            return ['(' . $alias . '.service_app = :own_app)', ['own_app' => $sourceApp]];
        }

        return [
            '(' . $alias . '.service_app = :own_app OR (' . $alias . '.service_app IS NULL AND ' . $alias . '.origin = :own_origin))',
            ['own_app' => $sourceApp, 'own_origin' => $origin],
        ];
    }

    /**
     * Per-address and per-company caps on service sends.
     *
     * A loop in a calling product, or a public form that anybody can submit with
     * somebody else's number, must not turn this endpoint into a way to message
     * one person repeatedly or to flood a company's sender. Both caps are
     * deployment settings; the defaults are far above what a booking produces
     * (a confirmation, a couple of reminders, a notice) and far below a flood.
     *
     * @return array{status:int, payload:array<string, mixed>, store:bool}|null
     */
    private static function rateLimited(Context $ctx, Auth $auth, string $to): ?array
    {
        $perAddress = (int) (Env::get('SERVICE_ADDRESS_DAILY_CAP') ?: 20);
        $perMinute = (int) (Env::get('SERVICE_COMPANY_PER_MINUTE') ?: 120);
        $address = ConsentService::normaliseAddress($to);
        $now = Clock::now();

        if ($perAddress > 0) {
            $count = (int) (Db::scalar(
                'SELECT COUNT(*) FROM messaging_messages m
                 JOIN messaging_conversations c ON c.conversation_uuid = m.conversation_uuid
                 WHERE m.cmp_id = :cmp AND m.service_app = :app AND c.customer_address = :addr
                   AND m.direction = \'outbound\' AND m.status <> \'cancelled\'
                   AND m.created_at > :since',
                [
                    'cmp' => $ctx->cmpId, 'app' => $auth->sourceApp, 'addr' => $address,
                    'since' => $now->modify('-24 hours')->format('Y-m-d H:i:sP'),
                ],
            ) ?? 0);
            if ($count >= $perAddress) {
                return self::refusal(429, 'rate_limited',
                    'This address has already been sent ' . $count . ' messages by ' . $auth->sourceApp
                    . ' in the last 24 hours, which is the limit. Nothing was sent.',
                    ['retryable' => true, 'retry_after' => 3600, 'scope' => 'address']);
            }
        }

        if ($perMinute > 0) {
            $count = (int) (Db::scalar(
                'SELECT COUNT(*) FROM messaging_messages
                 WHERE cmp_id = :cmp AND service_app = :app AND created_at > :since',
                [
                    'cmp' => $ctx->cmpId, 'app' => $auth->sourceApp,
                    'since' => $now->modify('-60 seconds')->format('Y-m-d H:i:sP'),
                ],
            ) ?? 0);
            if ($count >= $perMinute) {
                return self::refusal(429, 'rate_limited',
                    'This company has reached the limit of ' . $perMinute . ' service messages a minute. '
                    . 'Nothing was sent; retry shortly.',
                    ['retryable' => true, 'retry_after' => 60, 'scope' => 'company']);
            }
        }

        return null;
    }

    /**
     * A refusal BEFORE any message exists: nothing is stored under the
     * idempotency key, so the caller may fix the cause and retry the same key.
     *
     * @param array<string, mixed> $details
     * @return array{status:int, payload:array<string, mixed>, store:bool}
     */
    private static function refusal(int $status, string $code, string $message, array $details = []): array
    {
        $payload = [
            'error'   => ['code' => $code, 'message' => $message, 'details' => $details],
            'message' => $message,
        ];

        // A consent or expiry refusal is a terminal fact about the message the
        // caller asked for; say it in `data` as well, in the same words a
        // status read would use.
        if (isset($details['delivery_state'])) {
            $payload['data'] = [
                'message_uuid'   => null,
                'delivery_state' => $details['delivery_state'],
                'reason_code'    => $details['reason_code'] ?? $code,
                'reason'         => $message,
                'terminal'       => true,
            ];
        }

        return ['status' => $status, 'payload' => $payload, 'store' => false];
    }
}
