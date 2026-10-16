<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * Manage → product: POST {product}/api/internal/manage/company-lifecycle.
 *
 *   company events  {event_id, event, cmp_id, app_key, reason, occurred_at, restricted_until?}
 *                   event: company_restricted | company_unrestricted | company_purge_requested
 *   account event   {event_id, event: "account_purge_requested", uuid, app_key, reason: "account_closed", occurred_at}
 *                   (no cmp_id: the person's own data in this product, not a company's)
 *
 * handle() takes the raw body and the request headers and returns [HTTP status, JSON body]. Quick by
 * design: verify, store once, set the company's state, queue a purge. Nothing is deleted inside the
 * request — the Worker does that and acks Manage afterwards.
 *
 *   202 accepted                   stored and applied, or a redelivery (duplicate: true)
 *   401 signature_* / timestamp_*  missing, malformed, out-of-window or wrong signature
 *   413 payload_too_large          more than 64 KiB
 *   422 invalid_event              signed, but not the contract (unknown event, wrong app_key, a
 *                                  company event without cmp_id, an account event without uuid …)
 *   503 lifecycle_not_configured   LIFECYCLE_WEBHOOK_SECRET unset or shorter than 32 characters
 *   503 event_not_recorded         the database could not store it (Manage retries)
 *
 * Ordering: a company event older (occurred_at) than the newest one applied is stored with outcome
 * "stale" and changes nothing. A stale purge request is still queued, so Manage gets an answer: the
 * Worker refuses it (ack failed, with the reason) because the company is not purge_pending.
 */
final class Receiver
{
    public const MAX_BODY_BYTES = 65_536;

    public const COMPANY_EVENTS = [
        'company_restricted'      => 'restricted',
        'company_unrestricted'    => 'active',
        'company_purge_requested' => 'purge_pending',
    ];

    public const ACCOUNT_EVENT = 'account_purge_requested';

    /** Every event Manage may send (Manage's ProductEventOutbox::EVENTS; pinned by Manage's drift test). */
    public const EVENTS = ['company_restricted', 'company_unrestricted', 'company_purge_requested', 'account_purge_requested'];

    /** @var callable(string, string): void */
    private $log;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(string $level, string $message): void)|null $log
     * @param (callable(): int)|null $clock
     */
    public function __construct(private Config $config, private EventStore $store, ?callable $log = null, ?callable $clock = null)
    {
        $this->log = $log ?? [Kit::class, 'defaultLog'];
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, string|list<string>> $headers request headers in any casing, or $_SERVER
     * @return array{0:int, 1:array<string,mixed>}
     */
    public function handle(string $rawBody, array $headers): array
    {
        if (!$this->config->secretUsable()) {
            ($this->log)('error', 'event refused: LIFECYCLE_WEBHOOK_SECRET is not set (or shorter than 32 characters)');

            return self::answer(503, Signature::NOT_CONFIGURED, 'This application is not configured to receive AICOUNTLY lifecycle events.');
        }
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return self::answer(413, 'payload_too_large', 'A lifecycle event is at most 64 KiB.');
        }
        $verdict = Signature::verify(
            $this->config->secret,
            self::header($headers, Signature::HEADER_TIMESTAMP),
            $rawBody,
            self::header($headers, Signature::HEADER_SIGNATURE),
            ($this->clock)(),
        );
        if ($verdict !== Signature::OK) {
            ($this->log)('warning', 'unsigned or badly signed event refused (' . $verdict . ')');

            return self::answer(401, $verdict, 'The lifecycle event is not signed by AICOUNTLY Manage.');
        }

        $event = self::parse($rawBody, $this->config, $error);
        if ($event === null) {
            ($this->log)('warning', 'signed event refused as invalid: ' . $error);

            return self::answer(422, 'invalid_event', (string) $error);
        }

        try {
            $isNew = $this->store->recordEvent($event, $rawBody);
            $outcome = $this->apply($event);
            if ($isNew) {
                $this->store->setOutcome($event['event_id'], $outcome);
            }
        } catch (\Throwable $e) {
            ($this->log)('error', 'event ' . $event['event_id'] . ' could not be recorded: ' . $e->getMessage());

            return self::answer(503, 'event_not_recorded', 'This application could not record the event. Retry later.');
        }

        ($this->log)('notice', sprintf(
            '%s %s event_id=%s reason=%s outcome=%s%s',
            $event['event'],
            $event['cmp_id'] !== null ? 'cmp=' . $event['cmp_id'] : 'uuid=' . $event['uuid'],
            $event['event_id'],
            (string) $event['reason'],
            $outcome,
            $isNew ? '' : ' (redelivery)',
        ));

        return [202, array_filter([
            'status'    => 'accepted',
            'event_id'  => $event['event_id'],
            'cmp_id'    => $event['cmp_id'],
            'uuid'      => $event['uuid'],
            'outcome'   => $outcome,
            'duplicate' => !$isNew,
        ], static fn ($v): bool => $v !== null)];
    }

    /**
     * @param array{event_id:string, event:string, cmp_id:?int, uuid:?string, app_key:string, reason:?string, occurred_at:string, restricted_until:?string} $event
     */
    private function apply(array $event): string
    {
        if ($event['event'] === self::ACCOUNT_EVENT) {
            $this->store->enqueueJob($event['event_id'], 'account', null, $event['uuid']);

            return 'queued';
        }
        $state = self::COMPANY_EVENTS[$event['event']];
        $applied = $this->store->applyEvent(
            (int) $event['cmp_id'],
            $state,
            $event['restricted_until'],
            $event['reason'],
            $event['event_id'],
            $event['occurred_at'],
        );
        if ($event['event'] === 'company_purge_requested') {
            // Queued even when stale: Manage is owed an ack for every purge it asked for.
            $this->store->enqueueJob($event['event_id'], 'company', (int) $event['cmp_id'], null);

            return $applied ? 'queued' : 'stale';
        }

        return $applied ? 'applied' : 'stale';
    }

    /**
     * The contract, field by field.
     *
     * @return array{event_id:string, event:string, cmp_id:?int, uuid:?string, app_key:string, reason:?string, occurred_at:string, restricted_until:?string}|null
     */
    public static function parse(string $rawBody, Config $config, ?string &$error = null): ?array
    {
        $body = json_decode($rawBody, true);
        if (!is_array($body) || array_is_list($body)) {
            $error = 'A JSON object body is required.';

            return null;
        }
        $eventId = is_string($body['event_id'] ?? null) ? strtolower(trim($body['event_id'])) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $eventId) !== 1) {
            $error = 'event_id must be a UUID.';

            return null;
        }
        $name = is_string($body['event'] ?? null) ? trim($body['event']) : '';
        if (!in_array($name, self::EVENTS, true)) {
            $error = 'event must be one of ' . implode(', ', self::EVENTS) . '.';

            return null;
        }
        $appKey = is_string($body['app_key'] ?? null) ? strtolower(trim($body['app_key'])) : '';
        if (!$config->accepts($appKey)) {
            $error = 'app_key must be "' . $config->appKey . '" for this application.';

            return null;
        }

        $cmpId = null;
        $uuid = null;
        if ($name === self::ACCOUNT_EVENT) {
            if (array_key_exists('cmp_id', $body) && $body['cmp_id'] !== null) {
                $error = 'account_purge_requested names a person (uuid), never a company (cmp_id).';

                return null;
            }
            $uuid = AccountPurge::validUuid($body['uuid'] ?? null);
            if ($uuid === null) {
                $error = 'uuid must be the AICOUNTLY subscriber id (digits) for account_purge_requested.';

                return null;
            }
        } else {
            $raw = $body['cmp_id'] ?? null;
            $cmpId = (is_int($raw) || (is_string($raw) && ctype_digit($raw))) ? (int) $raw : 0;
            if ($cmpId <= 0) {
                $error = 'cmp_id must be a positive integer for ' . $name . '.';

                return null;
            }
            if (array_key_exists('uuid', $body) && $body['uuid'] !== null) {
                $error = $name . ' names a company (cmp_id), never a person (uuid).';

                return null;
            }
        }

        $reason = is_string($body['reason'] ?? null) ? strtolower(trim($body['reason'])) : '';
        if ($reason !== '' && preg_match('/^[a-z_]{1,40}$/', $reason) !== 1) {
            $error = 'reason is not a valid reason code.';

            return null;
        }
        $occurredAt = self::timestamp($body['occurred_at'] ?? null);
        if (!is_string($occurredAt)) {
            $error = 'occurred_at must be an ISO-8601 timestamp.';

            return null;
        }
        $restrictedUntil = self::timestamp($body['restricted_until'] ?? null);
        if ($restrictedUntil === false) {
            $error = 'restricted_until must be an ISO-8601 timestamp when present.';

            return null;
        }

        return [
            'event_id'         => $eventId,
            'event'            => $name,
            'cmp_id'           => $cmpId,
            'uuid'             => $uuid,
            'app_key'          => $config->appKey,
            'reason'           => $reason !== '' ? $reason : null,
            'occurred_at'      => $occurredAt,
            'restricted_until' => $restrictedUntil,
        ];
    }

    /** ISO-8601 UTC for a parsable value, null when absent, false when present but unreadable. */
    public static function timestamp(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 40) {
            return false;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ][0-9:.]+(Z|[+-]\d{2}:?\d{2})?)?$/i', $value) !== 1) {
            return false;
        }
        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * A request header from getallheaders()-style ['X-Manage-Timestamp' => '…'] (any casing; a string,
     * a PSR-7 list, or a CodeIgniter 4 Header object) or a $_SERVER-style ['HTTP_X_MANAGE_TIMESTAMP' => '…'].
     *
     * @param array<string, mixed> $headers
     */
    public static function header(array $headers, string $name): string
    {
        $want = strtolower($name);
        $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        foreach ($headers as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (strtolower($k) === $want || $k === $server) {
                if (is_array($v)) {
                    $v = reset($v);
                }
                if (is_object($v) && method_exists($v, 'getValueLine')) {
                    $v = $v->getValueLine(); // CodeIgniter 4 Header
                }

                return is_scalar($v) ? trim((string) $v) : '';
            }
        }

        return '';
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    public static function answer(int $status, string $code, string $message): array
    {
        return [$status, ['status' => $status, 'code' => $code, 'message' => $message, 'error' => ['code' => $code, 'message' => $message]]];
    }
}
