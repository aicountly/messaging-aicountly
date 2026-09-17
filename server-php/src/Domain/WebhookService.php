<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Channels\NormalisedEvent;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * Provider webhooks: receive durably, then process.
 *
 * ## The tenant is never in the payload
 *
 * The route carries a connection uuid. That uuid is looked up SERVER-SIDE, the
 * signature is verified against THAT connection's secret, and the company comes
 * from the row. A payload field naming a company is ignored entirely.
 *
 * This is the difference between a webhook endpoint and an open door. If the
 * tenant came from the body, anyone who could reach the URL could write
 * delivery events, inbound messages and opt-outs into any company in the
 * deployment.
 *
 * ## Acknowledge after durable receipt, process afterwards
 *
 * The receipt row is committed and the provider gets its 200 immediately.
 * Providers retry aggressively when they do not get one, and a provider
 * retrying while we are still thinking sends the same event five times. The
 * unique index on (provider, provider_event_id) makes those retries free.
 *
 * ## Out-of-order and duplicate events
 *
 * Both are normal. Deduplication is the unique index; ordering is
 * MessageState::advance(), which only ever moves a message forward. A `read`
 * followed by a late `delivered` records both events and leaves the message at
 * `read`.
 */
final class WebhookService
{
    /**
     * Record one inbound webhook.
     *
     * @param array<string, string> $headers
     * @return array{accepted:bool, status:int, disposition:string, receipts:list<int>}
     */
    public static function receive(string $provider, string $connectionUuid, string $rawBody, array $headers): array
    {
        $adapter = ChannelRegistry::adapter($provider);
        if ($adapter === null) {
            self::recordRejection($provider, 'unknown_provider', $rawBody);

            return ['accepted' => false, 'status' => 404, 'disposition' => 'unknown_provider', 'receipts' => []];
        }

        $row = Db::first(
            'SELECT * FROM messaging_channel_connections WHERE connection_uuid = :uuid',
            ['uuid' => $connectionUuid],
        );
        if ($row === null) {
            // Recorded, because an event for a connection we do not have is
            // exactly what an administrator needs to see — a stale webhook
            // registration, or somebody probing.
            self::recordRejection($provider, 'unknown_connection', $rawBody);

            return ['accepted' => false, 'status' => 404, 'disposition' => 'unknown_connection', 'receipts' => []];
        }

        $connection = ChannelConnection::fromRow($row);

        if (!$adapter->verifyWebhook($connection, $rawBody, $headers)) {
            // 403 and nothing else. No detail about why, because the detail
            // would help somebody get the signature right next time.
            self::recordRejection($provider, 'bad_signature', $rawBody, $connection);

            return ['accepted' => false, 'status' => 403, 'disposition' => 'bad_signature', 'receipts' => []];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            // Some providers post form-encoded rather than JSON.
            parse_str($rawBody, $parsed);
            $payload = is_array($parsed) ? $parsed : [];
        }

        $events = $adapter->normaliseWebhook($payload);
        if ($events === []) {
            self::recordReceipt($connection, $provider, 'ignored', 'unsupported_event', $payload, 'noop:' . hash('sha256', $rawBody));

            return ['accepted' => true, 'status' => 200, 'disposition' => 'unsupported_event', 'receipts' => []];
        }

        $receipts = [];
        foreach ($events as $event) {
            $receiptId = self::recordReceipt($connection, $provider, 'received', '', $payload, $event->providerEventId);
            if ($receiptId !== null) {
                $receipts[] = $receiptId;
            }
        }

        Db::run(
            'UPDATE messaging_channel_connections SET last_webhook_at = :now WHERE connection_uuid = :uuid',
            ['now' => Clock::nowSql(), 'uuid' => $connectionUuid],
        );

        // Processed synchronously but AFTER the receipts are committed, so a
        // failure here leaves a durable row the worker can retry rather than an
        // event that is simply gone.
        foreach ($events as $event) {
            try {
                self::process($connection, $event);
            } catch (\Throwable $e) {
                error_log('[webhook] processing failed for ' . $event->providerEventId . ': ' . $e->getMessage());
                Db::run(
                    'UPDATE messaging_webhook_receipts SET status = :failed, error_detail = :detail
                     WHERE provider = :provider AND provider_event_id = :eid',
                    ['failed' => 'failed', 'detail' => 'Processing error, see server log.', 'provider' => $provider, 'eid' => $event->providerEventId],
                );
            }
        }

        return ['accepted' => true, 'status' => 200, 'disposition' => 'processed', 'receipts' => $receipts];
    }

    /**
     * Apply one normalised event.
     *
     * Idempotent: a re-delivered event finds its receipt already processed and
     * does nothing.
     */
    public static function process(ChannelConnection $connection, NormalisedEvent $event): void
    {
        $ctx = Context::forCompany($connection->cmpId, $connection->boId);

        $receipt = Db::first(
            'SELECT receipt_id, status FROM messaging_webhook_receipts
             WHERE provider = :provider AND provider_event_id = :eid',
            ['provider' => $connection->provider, 'eid' => $event->providerEventId],
        );
        if ($receipt !== null && (string) $receipt['status'] === 'processed') {
            return;
        }

        match ($event->kind) {
            'inbound_message' => self::handleInbound($ctx, $connection, $event),
            'status', 'error' => self::handleStatus($ctx, $connection, $event),
            'optout'          => self::handleOptOut($ctx, $connection, $event),
            default           => null,
        };

        Db::run(
            'UPDATE messaging_webhook_receipts
             SET status = :processed, processed_at = :now
             WHERE provider = :provider AND provider_event_id = :eid',
            ['processed' => 'processed', 'now' => Clock::nowSql(), 'provider' => $connection->provider, 'eid' => $event->providerEventId],
        );
    }

    // -----------------------------------------------------------------------

    private static function handleInbound(Context $ctx, ChannelConnection $connection, NormalisedEvent $event): void
    {
        if ($event->fromAddress === '') {
            return;
        }

        $conversationUuid = ConversationService::findOrOpenForInbound(
            $ctx,
            $connection->connectionUuid,
            $connection->channel,
            $event->fromAddress,
            $event->profileName,
        );

        $recorded = MessageService::recordInbound($ctx, $conversationUuid, [
            'connection_uuid'     => $connection->connectionUuid,
            'channel'             => $connection->channel,
            'body'                => $event->body,
            'provider_message_id' => $event->providerMessageId,
            'occurred_at'         => $event->occurredAt,
        ]);

        if ($recorded['duplicate']) {
            return;
        }

        foreach ($event->media as $media) {
            AttachmentService::recordProviderMedia($ctx, $recorded['message_uuid'], $media);
        }

        ConversationService::touchForMessage($conversationUuid, 'inbound', 'CUSTOMER', false);
        MetricsService::recordInbound($ctx, $connection->channel);

        // An opt-out in the body is honoured even when the provider did not
        // flag it. A customer who typed STOP has withdrawn consent whether or
        // not their carrier noticed.
        if (ConsentService::detectOptOut($event->body)) {
            self::withdrawConsent($ctx, $connection, $event->fromAddress, $recorded['message_uuid']);
        }
    }

    private static function handleStatus(Context $ctx, ChannelConnection $connection, NormalisedEvent $event): void
    {
        $providerMessageId = (string) $event->providerMessageId;
        if ($providerMessageId === '') {
            return;
        }

        $message = Db::first(
            'SELECT message_uuid, status, conversation_uuid FROM messaging_messages
             WHERE connection_uuid = :conn AND provider_message_id = :pid',
            ['conn' => $connection->connectionUuid, 'pid' => $providerMessageId],
        );

        // The event is still recorded when the message is unknown to us: a
        // receipt for something we have no record of sending is a real signal,
        // not noise to discard.
        $messageUuid = $message !== null ? (string) $message['message_uuid'] : null;

        try {
            Db::insert('messaging_delivery_events', [
                'cmp_id'              => $ctx->cmpId,
                'message_uuid'        => $messageUuid,
                'connection_uuid'     => $connection->connectionUuid,
                'provider_event_id'   => $event->providerEventId,
                'provider_message_id' => $providerMessageId,
                'event_type'          => (string) ($event->status ?? $event->kind),
                'state_rank'          => $event->stateRank(),
                'error_code'          => $event->errorCode,
                'error_detail'        => $event->errorDetail,
                'occurred_at'         => $event->occurredAt,
                'received_at'         => Clock::nowSql(),
            ], 'event_id');
        } catch (\PDOException) {
            // Duplicate provider event id. The index did its job.
            return;
        }

        if ($message === null || $event->status === null) {
            return;
        }

        // FORWARD ONLY. See MessageState::advance().
        $next = MessageState::advance((string) $message['status'], $event->status);
        if ($next === null) {
            return;
        }

        $columns = 'status = :status, row_version = row_version + 1';
        $params = ['status' => $next, 'uuid' => $messageUuid];

        if ($next === MessageState::DELIVERED) {
            $columns .= ', delivered_at = COALESCE(delivered_at, :now)';
            $params['now'] = $event->occurredAt ?? Clock::nowSql();
        } elseif ($next === MessageState::READ) {
            $columns .= ', read_at = COALESCE(read_at, :now), delivered_at = COALESCE(delivered_at, :now)';
            $params['now'] = $event->occurredAt ?? Clock::nowSql();
        } elseif ($next === MessageState::FAILED) {
            $columns .= ', failed_at = COALESCE(failed_at, :now), failure_code = :code, failure_detail = :detail';
            $params['now'] = $event->occurredAt ?? Clock::nowSql();
            $params['code'] = $event->errorCode;
            $params['detail'] = $event->errorDetail;
        }

        if ($event->costMinor !== null) {
            $columns .= ', provider_cost_minor = :cost, cost_currency = COALESCE(:currency, cost_currency)';
            $params['cost'] = $event->costMinor;
            $params['currency'] = $event->costCurrency;
        }

        Db::run('UPDATE messaging_messages SET ' . $columns . ' WHERE message_uuid = :uuid', $params);

        MetricsService::recordStatusChange($ctx, $connection->channel, $next, $event->costMinor, $event->costCurrency);

        // A hard bounce suppresses the address. The provider is telling us this
        // destination does not work, and continuing to send to it damages the
        // sender's reputation as well as achieving nothing.
        if ($next === MessageState::FAILED && self::isHardFailure((string) $event->errorCode)) {
            $conversation = Db::first(
                'SELECT customer_address FROM messaging_conversations WHERE conversation_uuid = :uuid',
                ['uuid' => $message['conversation_uuid']],
            );
            if ($conversation !== null) {
                self::suppressForProvider(
                    $ctx,
                    $connection,
                    (string) $conversation['customer_address'],
                    'hard_bounce',
                    (string) ($event->errorDetail ?? 'The provider reported a permanent failure.'),
                );
            }
        }
    }

    private static function handleOptOut(Context $ctx, ChannelConnection $connection, NormalisedEvent $event): void
    {
        if ($event->fromAddress === '') {
            return;
        }
        self::withdrawConsent($ctx, $connection, $event->fromAddress, null);
    }

    private static function withdrawConsent(
        Context $ctx,
        ChannelConnection $connection,
        string $address,
        ?string $messageUuid,
    ): void {
        // A synthetic actor: this is the customer's own decision arriving over
        // the provider, not an administrator's action. Recording it as a named
        // user would misattribute it in the consent history.
        $actor = Auth::forProvider($connection->provider);

        ConsentService::record(
            $ctx,
            $actor,
            $connection->channel,
            $address,
            'all',
            'withdrawn',
            'customer_message',
            'The customer sent an opt-out keyword on ' . $connection->channel . '.',
            null,
            null,
            $messageUuid,
            'provider',
        );

        ConsentService::suppress(
            $ctx,
            $actor,
            $connection->channel,
            $address,
            'customer_optout',
            'The customer opted out over ' . $connection->channel . '.',
        );

        Audit::recordProvider($ctx, $connection->provider, 'consent.withdrawn', 'consent', $address, [
            'channel' => $connection->channel,
            'source'  => 'customer_message',
        ]);
    }

    private static function suppressForProvider(
        Context $ctx,
        ChannelConnection $connection,
        string $address,
        string $reason,
        string $detail,
    ): void {
        $actor = Auth::forProvider($connection->provider);
        ConsentService::suppress($ctx, $actor, $connection->channel, $address, $reason, $detail);

        Audit::recordProvider($ctx, $connection->provider, 'address.suppressed', 'suppression', $address, [
            'channel' => $connection->channel,
            'reason'  => $reason,
        ]);
    }

    /**
     * Whether a provider error means "never again" rather than "not now".
     *
     * Conservative: only codes whose meaning is unambiguous suppress an
     * address. Suppressing on a transient error would silence a customer
     * because of a carrier hiccup.
     */
    private static function isHardFailure(string $errorCode): bool
    {
        $code = strtolower($errorCode);

        return str_contains($code, 'invalid_number')
            || str_contains($code, 'not_a_whatsapp_user')
            || str_contains($code, 'unsubscribed')
            || str_contains($code, 'blacklist')
            || in_array($code, ['sms_21211', 'sms_21610', 'sms_21614', 'whatsapp_131026'], true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return int|null receipt id
     */
    private static function recordReceipt(
        ChannelConnection $connection,
        string $provider,
        string $status,
        string $disposition,
        array $payload,
        string $providerEventId,
    ): ?int {
        try {
            return (int) Db::insert('messaging_webhook_receipts', [
                'cmp_id'             => $connection->cmpId,
                'connection_uuid'    => $connection->connectionUuid,
                'provider'           => $provider,
                'provider_event_id'  => $providerEventId,
                'signature_verified' => true,
                'status'             => $status,
                'disposition'        => $disposition,
                'raw_event'          => self::redact($payload),
                'received_at'        => Clock::nowSql(),
            ], 'receipt_id');
        } catch (\PDOException) {
            // Duplicate. A provider retry, which is the normal case.
            return null;
        }
    }

    private static function recordRejection(
        string $provider,
        string $disposition,
        string $rawBody,
        ?ChannelConnection $connection = null,
    ): void {
        try {
            Db::insert('messaging_webhook_receipts', [
                'cmp_id'             => $connection?->cmpId,
                'connection_uuid'    => $connection?->connectionUuid,
                'provider'           => $provider,
                'provider_event_id'  => 'rejected:' . hash('sha256', $rawBody . $disposition . Clock::nowSql()),
                'signature_verified' => false,
                'status'             => 'rejected',
                'disposition'        => $disposition,
                // A rejected body is NOT stored. It failed a signature check,
                // which means it is unauthenticated input from an unknown
                // source, and keeping it would mean storing whatever a stranger
                // chose to post at this URL.
                'raw_event'          => ['note' => 'Body not stored: signature was not verified.'],
                'received_at'        => Clock::nowSql(),
            ], 'receipt_id');
        } catch (\Throwable $e) {
            error_log('[webhook] could not record rejection: ' . $e->getMessage());
        }
    }

    /**
     * Strip secrets from an event before it is stored.
     *
     * Providers do include tokens in payloads — a verify token on a handshake,
     * an auth field on some aggregators — and a webhook receipt table is a
     * durable, queryable, backed-up place for one to end up.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array
    {
        $pattern = '/(token|secret|signature|password|api[_-]?key|authorization|credential)/i';

        $out = [];
        foreach ($payload as $key => $value) {
            if (preg_match($pattern, (string) $key) === 1) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $out;
    }

    /**
     * Health of the webhook pipeline, for Channels & Trust.
     *
     * @return array<string, mixed>
     */
    public static function health(Context $ctx): array
    {
        $rows = Db::all(
            "SELECT status, COUNT(*) AS n FROM messaging_webhook_receipts
             WHERE cmp_id = :cmp AND received_at >= NOW() - INTERVAL '24 hours'
             GROUP BY status",
            ['cmp' => $ctx->cmpId],
        );
        $counts = ['received' => 0, 'processed' => 0, 'ignored' => 0, 'failed' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        $last = Db::scalar(
            'SELECT MAX(received_at) FROM messaging_webhook_receipts WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        );

        // Rejections are surfaced separately and prominently: a run of them is
        // either a misconfigured secret or somebody probing the endpoint, and
        // both are worth an administrator's attention.
        $rejected = (int) (Db::scalar(
            "SELECT COUNT(*) FROM messaging_webhook_receipts
             WHERE cmp_id IS NULL AND received_at >= NOW() - INTERVAL '24 hours' AND status = 'rejected'",
        ) ?? 0);

        return [
            'counts_24h'          => $counts,
            'last_event_at'       => $last,
            'unattributed_rejected_24h' => $rejected,
        ];
    }
}
