<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Support\Clock;

/**
 * Append-only audit of THIS product's own actions.
 *
 * Books audits its vouchers and Pay audits its transactions; neither is copied
 * here. What this records is what only Messaging knows: who approved the draft
 * that went to a customer, who edited it after approval, who overrode a
 * suppression and why, who published the journey version that sent four hundred
 * reminders, who changed what the AI is allowed to do, who exported the
 * conversation history.
 *
 * WHAT IT MUST NOT CONTAIN. `before_state` and `after_state` are for Messaging
 * rows. A foreign API response — an invoice document, a contact record — is
 * never written here, because an audit table is exactly the kind of place a
 * "temporary" copy of another product's data goes to live forever. Where an
 * action turned on a foreign fact, what is recorded is the reference and the
 * decision: "invoice INV-2048 read from Books, outstanding confirmed, reminder
 * approved" — not the invoice.
 */
final class Audit
{
    public const TABLE = 'messaging_audit_events';

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(
        Context $ctx,
        Auth $auth,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        string $reason = '',
    ): void {
        try {
            Db::insert(self::TABLE, [
                'cmp_id'       => $ctx->cmpId,
                'bo_id'        => $ctx->boId,
                'actor_uuid'   => $auth->uuid,
                'actor_kind'   => $auth->kind,
                'source_app'   => $auth->sourceApp,
                'action'       => $action,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId === null ? null : (string) $entityId,
                'before_state' => $before === null ? null : self::redact($before),
                'after_state'  => $after === null ? null : self::redact($after),
                'reason'       => $reason !== '' ? $reason : null,
                'ip_address'   => self::clientIp(),
                'created_at'   => Clock::nowSql(),
            ], 'audit_id');
        } catch (\Throwable $e) {
            // An audit write must never be the reason a message fails to send.
            // It is logged loudly instead, because a silently missing audit row
            // is worse than a noisy one.
            error_log('[audit] failed to record ' . $action . ' on ' . $entityType . ': ' . $e->getMessage());
        }
    }

    /**
     * The same record for something a provider webhook caused.
     *
     * There is no Auth there by design: a delivery receipt is not a user. The
     * actor is the provider, named from the server-side connection the
     * signature resolved to — never from the payload.
     */
    public static function recordProvider(
        Context $ctx,
        string $provider,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $after = null,
    ): void {
        try {
            Db::insert(self::TABLE, [
                'cmp_id'       => $ctx->cmpId,
                'bo_id'        => $ctx->boId,
                'actor_uuid'   => 'provider:' . $provider,
                'actor_kind'   => 'provider',
                'source_app'   => $provider,
                'action'       => $action,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId === null ? null : (string) $entityId,
                'before_state' => null,
                'after_state'  => $after === null ? null : self::redact($after),
                'reason'       => null,
                'ip_address'   => self::clientIp(),
                'created_at'   => Clock::nowSql(),
            ], 'audit_id');
        } catch (\Throwable $e) {
            error_log('[audit] failed to record provider ' . $action . ': ' . $e->getMessage());
        }
    }

    /**
     * Strip anything that must not come to rest in an audit row.
     *
     * Credentials first — a channel connection's before/after state would
     * otherwise carry the token somebody just rotated. Then the foreign
     * payloads: a key called `books_response` or `contact` is a mirror waiting
     * to happen, and it is dropped rather than truncated so nobody can later
     * "just read it from the audit log".
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function redact(array $state): array
    {
        $secretish = '/(secret|token|password|api[_-]?key|credential|signature|authorization|bearer)/i';
        $foreign = ['books_response', 'contacts_response', 'pay_response', 'sales_response',
            'appointments_response', 'contact', 'invoice', 'order', 'payment', 'appointment',
            'foreign_payload', 'source_payload'];

        $out = [];
        foreach ($state as $key => $value) {
            $name = (string) $key;

            if (preg_match($secretish, $name) === 1) {
                $out[$name] = '[redacted]';
                continue;
            }
            if (in_array(strtolower($name), $foreign, true)) {
                $out[$name] = '[foreign data not stored — read live from the owning product]';
                continue;
            }
            if (is_array($value)) {
                $out[$name] = self::redact($value);
                continue;
            }
            // A message body belongs here (it is a Messaging record) but an
            // unbounded one does not.
            $out[$name] = is_string($value) && strlen($value) > 4000
                ? substr($value, 0, 4000) . '…[truncated]'
                : $value;
        }

        return $out;
    }

    private static function clientIp(): ?string
    {
        $candidates = [$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $first = trim(explode(',', $candidate)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return null;
    }
}
