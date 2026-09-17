<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Domain\ConversationService;
use Aicountly\Api\Domain\DispatchGuard;
use Aicountly\Api\Domain\DispatchService;
use Aicountly\Api\Domain\Idempotency;
use Aicountly\Api\Domain\MessageService;
use Aicountly\Api\Domain\MessageState;
use Aicountly\Api\Domain\Period;
use Aicountly\Api\Domain\TemplateService;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;

/**
 * The API other Aicountly products call.
 *
 * ## This implements a contract that already exists
 *
 * `appointments-aicountly/server-php/src/Clients/MessagingClient.php` already
 * calls `POST /api/v1/messages` and `GET /api/v1/messages/stats`, with
 * `X-Service-Key` and `Idempotency-Key`, and a body of
 * `{channel, to, template, variables, reference}`. That is the published shape
 * and this is written to match it rather than to a shape of our own choosing —
 * changing it would break a product that is already deployed.
 *
 * ## What a sibling product may and may not do here
 *
 * MAY: ask for a message to be sent, using an approved template, to an address,
 * with variables, against a reference.
 *
 * MAY NOT: bypass consent, bypass suppression, send an unapproved template,
 * claim a message origin, or write to a company it has no key for. Every one of
 * those goes through the same DispatchGuard as a message an agent types. A
 * service key is a way in, not a way round.
 *
 * ## Idempotency is not optional
 *
 * Appointments retries on a timeout, as it should. Without the idempotency
 * table that retry is a second reminder to a customer.
 */
final class ServiceController
{
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
        $ctx = Context::fromRequest();

        $idempotencyKey = Http::idempotencyKey();
        if ($idempotencyKey === '') {
            Http::validationFailed(
                'An Idempotency-Key header is required on this endpoint (8–200 characters of A-Za-z0-9._:-). '
                . 'Without one, a retry after a timeout would send the customer a second message.',
            );
        }

        $replay = Idempotency::replay($ctx, $auth, 'v1.messages.send', $idempotencyKey, $body);
        if ($replay !== null) {
            Http::json($replay['status'], $replay['body'] + [
                'replayed' => true,
                'replay_note' => 'This is the original answer to this idempotency key. Nothing was sent again.',
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

        Idempotency::complete($ctx, $auth, $idempotencyKey, $result['status'], $result['payload']);

        Http::json($result['status'], $result['payload']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status:int, payload:array<string, mixed>}
     */
    private static function dispatch(Context $ctx, Auth $auth, array $body): array
    {
        $channel = strtolower(trim((string) ($body['channel'] ?? '')));
        $to = trim((string) ($body['to'] ?? ''));
        $templateName = trim((string) ($body['template'] ?? ''));
        $variables = is_array($body['variables'] ?? null) ? $body['variables'] : [];
        $reference = trim((string) ($body['reference'] ?? ''));
        $language = (string) ($body['language'] ?? 'en') ?: 'en';

        // Voice is not Messaging's. The published MessagingClient already
        // refuses it on its side; refusing it here too means the boundary
        // holds even if a future caller forgets.
        if ($channel === 'voice') {
            return self::refusal(422, 'voice_belongs_to_receptionist',
                'Voice calls are not Messaging\'s. Aicountly Voice owns telephony; this product does not implement '
                . 'a telephony engine.');
        }
        if ($channel === 'email') {
            return self::refusal(422, 'email_not_enabled',
                'Email is not a channel this deployment of Messaging sends on. Aicountly Email owns email delivery.');
        }
        if (!in_array($channel, ['whatsapp', 'rcs', 'sms'], true)) {
            return self::refusal(422, 'unknown_channel', 'Unknown channel "' . $channel . '".');
        }
        if ($to === '') {
            return self::refusal(422, 'validation_failed', 'A destination address is required.');
        }
        if ($templateName === '') {
            return self::refusal(422, 'validation_failed',
                'A template name is required. A business-initiated message needs a provider-approved template.');
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
                'No ' . $channel . ' channel is connected for this company, so nothing can be sent.');
        }
        $connection = ChannelConnection::fromRow($connectionRow);

        $adapter = ChannelRegistry::adapterFor($connection);
        $gap = $adapter?->configurationGap($connection);
        if ($adapter === null || $gap !== null) {
            return self::refusal(422, 'channel_not_configured',
                $gap ?? 'No adapter is installed for provider "' . $connection->provider . '".');
        }

        // The template, by name, and only an APPROVED version of it.
        $template = Db::first(
            'SELECT template_uuid FROM messaging_templates
             WHERE cmp_id = :cmp AND channel = :channel AND name = :name AND is_active = TRUE',
            ['cmp' => $ctx->cmpId, 'channel' => $channel, 'name' => $templateName],
        );
        if ($template === null) {
            return self::refusal(404, 'template_missing',
                'No template named "' . $templateName . '" exists on the ' . $channel . ' channel for this company.');
        }

        $version = TemplateService::sendableVersion($ctx, (string) $template['template_uuid'], $language);
        if ($version === null) {
            return self::refusal(422, 'template_not_approved',
                'The template "' . $templateName . '" has no provider-approved version in ' . $language
                . '. Templates cannot be dispatched until the provider approves them.');
        }

        // CONSENT, before anything is even drafted. A sibling product asking
        // for a reminder does not override a customer's opt-out.
        $purpose = self::purposeFor($auth->sourceApp);
        $consent = ConsentService::evaluate($ctx, $channel, $to, $purpose);
        if (!$consent['allowed']) {
            return self::refusal(
                422,
                $consent['reason'] === 'suppressed' ? 'suppressed' : 'no_consent',
                $consent['detail'],
                ['customer_decision' => true, 'purpose' => $purpose],
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

        // Bind the declared variables, in schema order.
        $bound = [];
        $missing = [];
        foreach (Db::jsonColumn($version['variable_schema'] ?? null) as $declared) {
            $name = (string) ($declared['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $value = $variables[$name] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $bound[$name] = (string) $value;
            } elseif ((bool) ($declared['required'] ?? true)) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            return self::refusal(422, 'variables_missing',
                'These template variables have no value: ' . implode(', ', $missing) . '.',
                ['missing' => $missing]);
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
            return self::refusal(422, $saved['code'], $saved['detail']);
        }

        $messageUuid = (string) $saved['message_uuid'];

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
                        'product' => $ownerProduct, 'ext' => $reference, 'label' => $reference,
                        'rel' => 'triggered_by', 'now' => Clock::nowSql(),
                    ],
                );
                ConversationService::linkExternal($ctx, $conversationUuid, $ownerProduct, $reference, 'subject', $reference);
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
            return self::refusal(500, 'server_error', 'The message could not be prepared.');
        }

        // THE SAME GATES as an agent's message. No shortcut for a service key.
        $verdict = DispatchGuard::evaluate($ctx, $message, $connection);
        if (!$verdict['allowed']) {
            DispatchService::cancel($ctx, $messageUuid, $verdict['detail']);

            return self::refusal(422, $verdict['code'], $verdict['detail'], [
                'retryable' => $verdict['retryable'],
                'checks'    => $verdict['checks'],
            ]);
        }

        $queued = DispatchService::enqueue($ctx, $auth, $messageUuid);
        if (!$queued['ok']) {
            return self::refusal(422, $queued['code'], $queued['detail']);
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

        $final = MessageService::find($ctx, $messageUuid);

        Audit::record($ctx, $auth, 'message.sent_via_service_api', 'message', $messageUuid, null, [
            'source_app' => $auth->sourceApp,
            'channel'    => $channel,
            'template'   => $templateName,
            'reference'  => $reference,
            'outcome'    => $outcome['outcome'],
        ]);

        return [
            'status'  => $outcome['outcome'] === 'accepted' ? 202 : 200,
            'payload' => [
                'data' => [
                    'message_uuid'      => $messageUuid,
                    'conversation_uuid' => $conversationUuid,
                    'status'            => $final !== null ? (string) $final['status'] : 'unknown',
                    'status_label'      => $final !== null
                        ? MessageState::describe((string) $final['status']) : 'Unknown',
                    'outcome'           => $outcome['outcome'],
                    'detail'            => $outcome['detail'],
                    'provider_message_id' => $final !== null ? ($final['provider_message_id'] ?? null) : null,
                    // The honest word. A caller that wants to report "delivered"
                    // to its own user must wait for a receipt.
                    'delivered'         => $final !== null
                        && in_array((string) $final['status'], [MessageState::DELIVERED, MessageState::READ], true),
                    'delivery_note'     => 'Provider acceptance is not delivery. Ask again, or read '
                        . '/api/v1/messages/stats, once the provider has reported.',
                ],
                'message' => $outcome['detail'],
            ],
        ];
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
        $period = Period::fromRequest($ctx, '30d');

        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        // Narrowed to what the CALLING product asked for, so Appointments sees
        // its own reminders and not another product's traffic.
        $originClause = '';
        $origin = self::originFor($auth->sourceApp);
        if ($origin !== null) {
            $originClause = ' AND m.origin = :origin';
            $params['origin'] = $origin;
        }

        $reference = trim((string) (Http::param('reference') ?? ''));
        if ($reference !== '') {
            $originClause .= ' AND EXISTS (SELECT 1 FROM messaging_external_references r
                                            WHERE r.entity_type = \'message\' AND r.entity_uuid = m.message_uuid
                                              AND r.external_id = :reference)';
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
            'scope_note' => $origin !== null
                ? 'Narrowed to messages this API sent on behalf of ' . $auth->sourceApp . '.'
                : 'All outbound messages for this company.',
        ]);
    }

    /**
     * GET /api/v1/messages/{uuid}
     *
     * So a calling product can ask what happened to one message it asked for.
     */
    public static function show(string $messageUuid): void
    {
        $auth = Auth::require();
        if (!$auth->isService()) {
            Http::forbidden('This endpoint is for Aicountly product backends presenting a service key.');
        }

        $ctx = Context::fromRequest();
        $message = MessageService::find($ctx, $messageUuid);

        if ($message === null) {
            Http::notFound('That message could not be found.');
        }

        // A product may only read a message it caused. Otherwise a service key
        // for one product would read another product's traffic.
        $origin = self::originFor($auth->sourceApp);
        if ($origin !== null && (string) $message['origin'] !== $origin) {
            Http::forbidden('That message was not sent on behalf of ' . $auth->sourceApp . '.');
        }

        $events = Db::all(
            'SELECT event_type, error_code, error_detail, occurred_at, received_at
             FROM messaging_delivery_events WHERE message_uuid = :uuid ORDER BY received_at',
            ['uuid' => $messageUuid],
        );

        Http::data([
            'message_uuid'  => $messageUuid,
            'status'        => (string) $message['status'],
            'status_label'  => MessageState::describe((string) $message['status']),
            'delivered'     => in_array((string) $message['status'], [MessageState::DELIVERED, MessageState::READ], true),
            'failure_code'  => $message['failure_code'] ?? null,
            'failure_detail' => $message['failure_detail'] ?? null,
            'events'        => $events,
        ]);
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
     * @param array<string, mixed> $details
     * @return array{status:int, payload:array<string, mixed>}
     */
    private static function refusal(int $status, string $code, string $message, array $details = []): array
    {
        return [
            'status'  => $status,
            'payload' => [
                'error'   => ['code' => $code, 'message' => $message, 'details' => $details],
                'message' => $message,
            ],
        ];
    }
}
