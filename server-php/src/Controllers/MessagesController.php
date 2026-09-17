<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Domain\AttachmentService;
use Aicountly\Api\Domain\ConversationService;
use Aicountly\Api\Domain\DispatchGuard;
use Aicountly\Api\Domain\DispatchService;
use Aicountly\Api\Domain\MessageService;
use Aicountly\Api\Domain\MessageState;
use Aicountly\Api\Domain\TemplateService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Drafts, approval, dispatch and delivery investigation.
 */
final class MessagesController extends Controller
{
    public static function index(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $conversation = ConversationService::find($ctx, $conversationUuid);
        if ($conversation === null) {
            Http::notFound('That conversation could not be found.');
        }
        if (!ConversationService::mayView($ctx, $auth, $conversation)) {
            Http::forbidden('That conversation is assigned to somebody else.');
        }

        $messages = MessageService::thread($ctx, $conversationUuid);

        Http::list($messages, count($messages), count($messages), 0);
    }

    /** Save a draft. Does not send. */
    public static function saveDraft(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.reply');

        $body = Http::body();
        $result = MessageService::saveDraft($ctx, $auth, $conversationUuid, $body);

        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        $message = MessageService::find($ctx, (string) $result['message_uuid']);

        Audit::record($ctx, $auth, 'message.draft_saved', 'message', $result['message_uuid'], null, [
            'conversation_uuid' => $conversationUuid,
            'ai_generated'      => (bool) ($body['ai_generated'] ?? false),
        ]);

        // If the model wrote it, record whether a human kept it.
        if (isset($body['ai_run_uuid']) && is_string($body['ai_run_uuid']) && $body['ai_run_uuid'] !== '') {
            \Aicountly\Api\Ai\AiClient::markAccepted($body['ai_run_uuid'], true);
        }

        Http::data([
            'message'      => $message !== null ? MessageService::shape($message) : null,
            'detail'       => $result['detail'],
            // Said plainly, because a Save button that might have sent
            // something is a Save button nobody trusts.
            'sent'         => false,
            'sent_note'    => 'Saved as a draft. Nothing has been sent to the customer.',
        ], 201);
    }

    /**
     * Approve a draft.
     *
     * Binds the approval to the content hash. See MessageService::approve.
     */
    public static function approve(string $messageUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.drafts.approve');

        $result = MessageService::approve($ctx, $auth, $messageUuid, self::expectedVersion());
        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'message.approved', 'message', $messageUuid);

        $message = MessageService::find($ctx, $messageUuid);

        // An approval may resume a journey run that was waiting for one.
        if ($message !== null && $message['journey_run_uuid'] !== null) {
            \Aicountly\Api\Domain\JourneyEngine::resume($ctx, $auth, (string) $message['journey_run_uuid']);
            $message = MessageService::find($ctx, $messageUuid);
        }

        Http::data([
            'message' => $message !== null ? MessageService::shape($message) : null,
            'detail'  => 'Approved. It will not send until somebody dispatches it, or until the journey that '
                . 'prepared it continues.',
        ]);
    }

    /**
     * Send an approved message.
     *
     * Runs the pre-flight gates, queues one job, and reports what happened. The
     * gates run AGAIN in the worker, on the same message, immediately before
     * the provider call — this is a courtesy so the agent sees a refusal while
     * they are still looking at the screen.
     */
    public static function send(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.messages.send');

        $messageUuid = trim((string) (Http::param('message_uuid') ?? ''));
        if ($messageUuid === '') {
            Http::validationFailed('Which message should be sent? (message_uuid is required.)');
        }

        $message = MessageService::find($ctx, $messageUuid);
        if ($message === null || (string) $message['conversation_uuid'] !== $conversationUuid) {
            Http::notFound('That message could not be found on this conversation.');
        }

        // A draft can be approved and sent in one action by somebody who holds
        // both permissions. Two clicks is the default; one is allowed when the
        // same person is entitled to do both, which is the common case for an
        // agent answering a customer.
        if ((string) $message['status'] === MessageState::DRAFT) {
            Permissions::assert($ctx, $auth, 'messaging.drafts.approve');
            $approved = MessageService::approve($ctx, $auth, $messageUuid, self::expectedVersion());
            if (!$approved['ok']) {
                self::fail($approved['code'], $approved['detail']);
            }
            $message = MessageService::find($ctx, $messageUuid);
        }

        $connection = ChannelConnection::find($ctx->cmpId, (string) $message['connection_uuid']);
        if ($connection === null) {
            self::fail('channel_not_configured', 'The channel this conversation arrived on is no longer configured.');
        }

        $verdict = DispatchGuard::evaluate($ctx, $message, $connection);
        if (!$verdict['allowed']) {
            // The refusal, with every gate's result, so the UI can show WHICH
            // check failed rather than a generic error.
            Http::json(422, [
                'error' => [
                    'code'    => $verdict['code'],
                    'message' => $verdict['detail'],
                    'details' => ['retryable' => $verdict['retryable'], 'checks' => $verdict['checks']],
                ],
                'message' => $verdict['detail'],
            ]);
        }

        $queued = DispatchService::enqueue($ctx, $auth, $messageUuid);
        if (!$queued['ok']) {
            self::fail($queued['code'], $queued['detail']);
        }

        Audit::record($ctx, $auth, 'message.sent', 'message', $messageUuid, null, [
            'conversation_uuid' => $conversationUuid,
            'job_uuid'          => $queued['job_uuid'],
            'gates'             => array_map(
                static fn (array $c) => ['gate' => $c['gate'], 'passed' => $c['passed']],
                $verdict['checks'],
            ),
        ]);

        // Processed inline so the agent sees the outcome rather than a spinner
        // that resolves somewhere else. The queue row is still the source of
        // truth and a worker would pick it up if this request died.
        $job = \Aicountly\Api\Db::first(
            'SELECT * FROM messaging_dispatch_jobs WHERE job_uuid = :uuid',
            ['uuid' => $queued['job_uuid']],
        );

        $outcome = ['outcome' => 'queued', 'detail' => 'Queued to send.'];
        if ($job !== null && (string) $job['status'] === 'queued') {
            $claimed = \Aicountly\Api\Db::first(
                'UPDATE messaging_dispatch_jobs
                 SET status = :claimed, claimed_at = NOW(), claimed_by = :worker, attempts = attempts + 1
                 WHERE job_uuid = :uuid AND status = :queued
                 RETURNING *',
                ['claimed' => 'claimed', 'worker' => 'web:' . substr($auth->fingerprint(), 0, 8),
                 'uuid' => $queued['job_uuid'], 'queued' => 'queued'],
            );
            if ($claimed !== null) {
                $outcome = DispatchService::process($claimed);
            }
        }

        $final = MessageService::find($ctx, $messageUuid);

        Http::data([
            'message'  => $final !== null ? MessageService::shape($final) : null,
            'outcome'  => $outcome['outcome'],
            'detail'   => $outcome['detail'],
            'checks'   => $verdict['checks'],
            // The honest phrasing. "Accepted by the provider" is what happened;
            // "delivered" is something only a receipt can tell us.
            'note'     => match ($outcome['outcome']) {
                'accepted' => 'The provider has accepted this message. Delivery is confirmed separately, when the '
                    . 'provider reports it.',
                'unknown'  => 'The provider did not confirm whether it took this message. It has NOT been resent, '
                    . 'because the customer may already have received it. It needs investigating.',
                'blocked'  => $outcome['detail'],
                'deferred' => $outcome['detail'],
                default    => null,
            },
        ]);
    }

    /** Cancel a queued message. */
    public static function cancel(string $messageUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.messages.send');

        $reason = trim((string) (Http::param('reason') ?? 'Cancelled by an agent.'));
        $cancelled = DispatchService::cancel($ctx, $messageUuid, $reason);

        if (!$cancelled) {
            self::fail(
                'not_editable',
                'This message has already gone to the provider and cannot be unsent. Nothing about it has changed.',
            );
        }

        Audit::record($ctx, $auth, 'message.cancelled', 'message', $messageUuid, null, ['reason' => $reason]);

        $message = MessageService::find($ctx, $messageUuid);

        Http::data(['message' => $message !== null ? MessageService::shape($message) : null]);
    }

    /**
     * Delivery investigation: the provider's own event trail for a message.
     */
    public static function deliveryEvents(string $messageUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.dispatch.manage');

        $message = MessageService::find($ctx, $messageUuid);
        if ($message === null) {
            Http::notFound('That message could not be found.');
        }

        $events = \Aicountly\Api\Db::all(
            'SELECT provider_event_id, provider_message_id, event_type, state_rank,
                    error_code, error_detail, occurred_at, received_at
             FROM messaging_delivery_events
             WHERE cmp_id = :cmp AND message_uuid = :uuid
             ORDER BY received_at ASC',
            ['cmp' => $ctx->cmpId, 'uuid' => $messageUuid],
        );

        $job = \Aicountly\Api\Db::first(
            'SELECT job_uuid, status, attempts, max_attempts, available_at, claimed_at, claimed_by,
                    finished_at, last_error_code, last_error_detail, idempotency_key
             FROM messaging_dispatch_jobs WHERE message_uuid = :uuid',
            ['uuid' => $messageUuid],
        );

        Http::data([
            'message' => MessageService::shape($message),
            'events'  => $events,
            'job'     => $job,
            'timing_note' => 'Each event has the time the PROVIDER says it happened and the time this product '
                . 'received it. The gap between them is the provider\'s own delay, not ours.',
            'ordering_note' => 'Providers deliver receipts out of order and more than once. Status only ever moves '
                . 'forward, so a late "delivered" after a "read" is recorded here and changes nothing.',
            'can_reconcile' => (string) $message['status'] === MessageState::SUBMISSION_UNKNOWN,
        ]);
    }

    /**
     * Reconcile an ambiguous submission by ASKING the provider.
     *
     * Never a resend. See DispatchService::reconcile.
     */
    public static function reconcile(string $messageUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.dispatch.manage');

        $result = DispatchService::reconcile($ctx, $messageUuid);

        Audit::record($ctx, $auth, 'message.reconciled', 'message', $messageUuid, null, [
            'resolved' => $result['resolved'],
            'status'   => $result['status'],
        ]);

        $message = MessageService::find($ctx, $messageUuid);

        Http::data([
            'resolved' => $result['resolved'],
            'detail'   => $result['detail'],
            'message'  => $message !== null ? MessageService::shape($message) : null,
        ]);
    }

    /**
     * The failure queue for the Delivery Investigation screen.
     */
    public static function failures(): void
    {
        [$auth, $ctx] = self::enter('messaging.dispatch.manage');

        $params = Http::listParams(['failed_at', 'created_at'], 'failed_at');
        $status = (string) (Http::param('status') ?? 'failed');
        if (!in_array($status, [MessageState::FAILED, MessageState::SUBMISSION_UNKNOWN], true)) {
            $status = MessageState::FAILED;
        }

        [$scope, $scopeParams] = $ctx->scopeClause('m');

        $total = (int) (\Aicountly\Api\Db::scalar(
            'SELECT COUNT(*) FROM messaging_messages m WHERE ' . $scope . ' AND m.status = :status',
            $scopeParams + ['status' => $status],
        ) ?? 0);

        $rows = \Aicountly\Api\Db::all(
            'SELECT m.message_uuid, m.conversation_uuid, m.channel, m.status, m.body,
                    m.failure_code, m.failure_detail, m.created_at, m.failed_at, m.provider_message_id,
                    c.customer_address, conn.provider
             FROM messaging_messages m
             JOIN messaging_conversations c ON c.conversation_uuid = m.conversation_uuid
             JOIN messaging_channel_connections conn ON conn.connection_uuid = m.connection_uuid
             WHERE ' . $scope . ' AND m.status = :status
             ORDER BY COALESCE(m.failed_at, m.created_at) DESC
             LIMIT :limit OFFSET :offset',
            $scopeParams + ['status' => $status, 'limit' => $params['limit'], 'offset' => $params['offset']],
        );

        // Grouped failure reasons, so a run of the same provider error is one
        // line rather than fifty.
        $grouped = \Aicountly\Api\Db::all(
            "SELECT m.failure_code, COUNT(*) AS n, MAX(m.failed_at) AS latest
             FROM messaging_messages m
             WHERE " . $scope . " AND m.status = :status
               AND COALESCE(m.failed_at, m.created_at) >= NOW() - INTERVAL '7 days'
             GROUP BY m.failure_code ORDER BY n DESC",
            $scopeParams + ['status' => $status],
        );

        Http::list($rows, $total, $params['limit'], $params['offset'], [
            'status'   => $status,
            'grouped'  => $grouped,
            'guidance' => $status === MessageState::SUBMISSION_UNKNOWN
                ? 'These sends timed out after the provider may already have accepted them. Reconcile each one by '
                    . 'asking the provider. Do NOT resend without checking — the customer may already have it.'
                : 'These were refused by the provider. Fix the cause before resending; the failure codes are the '
                    . 'provider\'s own.',
        ]);
    }

    /** Upload an attachment onto a draft. */
    public static function attach(string $messageUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.reply');

        $message = MessageService::find($ctx, $messageUuid);
        if ($message === null) {
            Http::notFound('That message could not be found.');
        }
        if (!MessageState::isEditable((string) $message['status'])) {
            self::fail('not_editable', 'This message can no longer be changed, so nothing can be attached to it.');
        }

        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload)) {
            Http::validationFailed('No file was uploaded.');
        }

        $result = AttachmentService::store($ctx, $messageUuid, $upload, $auth->sesKey());
        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'message.attachment_added', 'message', $messageUuid, null, [
            'attachment_uuid' => $result['attachment_uuid'],
        ]);

        $refreshed = MessageService::find($ctx, $messageUuid);

        Http::data([
            'attachment_uuid' => $result['attachment_uuid'],
            'message'         => $refreshed !== null ? MessageService::shape($refreshed) : null,
            'detail'          => $result['detail'],
        ], 201);
    }

    /**
     * Serve an attachment.
     *
     * Two checks, both required: the signature proves we issued the URL, and
     * the tenant check proves this caller may see it. A signature alone would
     * make a leaked URL a permanent grant.
     */
    public static function downloadAttachment(string $attachmentUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $attachment = AttachmentService::find($ctx, $attachmentUuid);
        if ($attachment === null) {
            Http::notFound('That attachment could not be found.');
        }

        $conversation = ConversationService::find($ctx, (string) $attachment['conversation_uuid']);
        if ($conversation === null || !ConversationService::mayView($ctx, $auth, $conversation)) {
            Http::forbidden('That attachment belongs to a conversation you cannot open.');
        }

        if ((string) $attachment['scan_status'] === 'infected') {
            Http::forbidden('That attachment failed a malware scan and cannot be downloaded.');
        }

        $path = (string) $attachment['storage'] === 'local'
            ? AttachmentService::localPath((string) $attachment['storage_ref'])
            : null;

        if ($path === null || !is_readable($path)) {
            // Drive and provider storage are fetched through their own
            // authorised paths, not streamed from here.
            $url = AttachmentService::authorisedUrl($ctx, $attachment);
            if ($url === null) {
                Http::error(503, 'attachment_unavailable', 'That attachment cannot be fetched right now.');
            }
            Http::data(['url' => $url, 'expires_in_seconds' => 900]);
        }

        header('Content-Type: ' . (string) $attachment['media_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . addslashes((string) $attachment['filename']) . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** Preview a template with variables bound, before drafting from it. */
    public static function previewTemplate(): void
    {
        [$auth, $ctx] = self::enter('messaging.templates.view');

        $templateUuid = trim((string) (Http::param('template_uuid') ?? ''));
        $language = (string) (Http::param('language') ?? 'en') ?: 'en';
        $variables = Http::body()['variables'] ?? [];

        $version = TemplateService::sendableVersion($ctx, $templateUuid, $language);
        if ($version === null) {
            self::fail(
                'template_not_approved',
                'This template has no provider-approved version in ' . $language . ', so it cannot be previewed for '
                . 'sending. Approved templates are the only ones that can be dispatched.',
            );
        }

        $bound = [];
        foreach ((array) $variables as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $bound[$name] = (string) $value;
            }
        }

        $schema = \Aicountly\Api\Db::jsonColumn($version['variable_schema'] ?? null);
        $missing = [];
        foreach ($schema as $variable) {
            $name = (string) ($variable['name'] ?? '');
            if ($name !== '' && (bool) ($variable['required'] ?? true) && trim((string) ($bound[$name] ?? '')) === '') {
                $missing[] = $name;
            }
        }

        Http::data([
            'body'            => TemplateService::render((string) $version['body'], $bound),
            'header'          => (string) $version['header'],
            'footer'          => (string) $version['footer'],
            'language'        => (string) $version['language'],
            'version'         => (int) $version['version'],
            'variable_schema' => $schema,
            'missing_variables' => $missing,
            // Stated, because a preview that looks sendable and is not wastes
            // somebody's time at the worst moment.
            'sendable'        => $missing === [],
            'sendable_note'   => $missing === []
                ? null
                : 'These variables have no value and the send will be refused until they do: ' . implode(', ', $missing) . '.',
        ]);
    }
}
