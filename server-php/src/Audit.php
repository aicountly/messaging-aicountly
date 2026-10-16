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

    /** Rows an export will write before it stops and says so. */
    public const EXPORT_CAP = 5000;

    /** Actions whose rows are `security` (access, credentials, who may do what). */
    private const SECURITY_PREFIXES = ['access.', 'channel.', 'suppression.released', 'audit.exported'];

    /** Actions whose rows are `material` (a customer was messaged, consent or policy changed, data left). */
    private const MATERIAL_PREFIXES = [
        'message.approved', 'message.sent', 'message.queued', 'message.cancelled', 'message.reconciled',
        'message.sent_via_service_api', 'message.cancelled_via_service_api', 'settings.', 'consent.',
        'address.suppressed', 'journey.published', 'journey.status_changed', 'journey.run_', 'template.submitted',
        'outcomes.exported', 'attachment.downloaded',
    ];

    /** Keys whose value is message or note CONTENT: the trail records that it changed, never the text. */
    private const CONTENT_KEYS = ['body', 'text', 'body_text', 'content', 'note', 'notes', 'subject', 'message',
        'caption', 'preview', 'draft', 'reply', 'translation', 'prompt'];

    /** Keys whose value is a customer's contact detail: masked, never stored whole. */
    private const ADDRESS_KEYS = ['to', 'address', 'recipient', 'phone', 'email', 'msisdn', 'wa_id',
        'contact_address', 'external_address', 'from_address'];

    private static ?string $requestId = null;

    /**
     * Best-effort. Used for everything that is not a decision about money, access or what a customer
     * receives: a failure is logged loudly and the request carries on.
     *
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
        ?string $entityLabel = null,
        ?string $summary = null,
        ?string $severity = null,
    ): void {
        try {
            self::insertRow(self::row(
                $ctx, $auth->uuid, $auth->kind, $auth->sourceApp, self::actorLabel($auth),
                $action, $entityType, $entityId, $before, $after, $reason, $entityLabel, $summary, $severity,
            ));
        } catch (\Throwable $e) {
            // An audit write must never be the reason a message fails to send.
            // It is logged loudly instead, because a silently missing audit row
            // is worse than a noisy one.
            error_log('[audit] failed to record ' . $action . ' on ' . $entityType . ': ' . $e->getMessage());
        }
    }

    /**
     * Fail-closed. Call it INSIDE the transaction that makes the change (Db::transaction), so the change
     * and its audit row commit together and the action fails if the row cannot be written. Used for
     * settings, access and credential changes, consent and suppression decisions, approvals and
     * cancellations, journey publication.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @throws \Throwable
     */
    public static function material(
        Context $ctx,
        Auth $auth,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        string $reason = '',
        ?string $entityLabel = null,
        ?string $summary = null,
        ?string $severity = null,
    ): void {
        self::insertRow(self::row(
            $ctx, $auth->uuid, $auth->kind, $auth->sourceApp, self::actorLabel($auth),
            $action, $entityType, $entityId, $before, $after, $reason, $entityLabel, $summary,
            $severity ?? (self::severityFor($action) === 'info' ? 'material' : self::severityFor($action)),
        ));
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
        ?string $entityLabel = null,
    ): void {
        try {
            self::insertRow(self::row(
                $ctx, 'provider:' . $provider, 'provider', $provider, null,
                $action, $entityType, $entityId, null, $after, '', $entityLabel, null, null,
            ));
        } catch (\Throwable $e) {
            error_log('[audit] failed to record provider ' . $action . ': ' . $e->getMessage());
        }
    }

    /**
     * The field-by-field difference between two states, as [{field, label, old, new}].
     *
     * Unchanged fields are skipped. Secret-named fields are skipped entirely (not even "changed"):
     * their existence in a diff is a leak. Content fields (a message or note body) are reported as
     * changed without the text. Contact details are masked.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, string> $labels field => human label
     * @return list<array{field: string, label: string, old: mixed, new: mixed}>
     */
    public static function diff(array $before, array $after, array $labels = []): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $field = (string) $key;
            // Bookkeeping columns say nothing about what a person changed.
            if (self::isSecretKey($field) || in_array($field, ['updated_at', 'updated_by', 'row_version'], true)) {
                continue;
            }
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (self::comparable($old) === self::comparable($new)) {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'label' => $labels[$field] ?? ucfirst(str_replace('_', ' ', $field)),
                'old'   => self::readable($field, $old),
                'new'   => self::readable($field, $new),
            ];
        }

        return $changes;
    }

    /** `info` | `material` | `security`, from the action name. */
    public static function severityFor(string $action): string
    {
        foreach (self::SECURITY_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return 'security';
            }
        }
        foreach (self::MATERIAL_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return 'material';
            }
        }

        return 'info';
    }

    /** A contact detail, shortened so the trail can identify it without being a copy of it. */
    public static function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1) . '***@' . $domain;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $tail = strlen($digits) >= 4 ? substr($digits, -4) : mb_substr($value, -2);

        return '***' . $tail;
    }

    /** One id per request: the caller's X-Request-Id when it is well-formed, otherwise generated. */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            $given = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
            self::$requestId = preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $given) === 1
                ? $given
                : bin2hex(random_bytes(8));
        }

        return self::$requestId;
    }

    /** For the test suite, which runs many "requests" in one process. */
    public static function resetRequestForTesting(): void
    {
        self::$requestId = null;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return array<string, mixed>
     */
    private static function row(
        Context $ctx,
        string $actorUuid,
        string $actorKind,
        string $sourceApp,
        ?string $actorLabel,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before,
        ?array $after,
        string $reason,
        ?string $entityLabel,
        ?string $summary,
        ?string $severity,
    ): array {
        $label = $entityLabel ?? self::labelFrom($after) ?? self::labelFrom($before);
        $meta = [];
        if ($before !== null && $after !== null) {
            $changes = self::diff($before, $after);
            if ($changes !== []) {
                $meta['changes'] = $changes;
            }
        }
        if ($reason !== '') {
            $meta['reason'] = $reason;
        }

        return [
            'cmp_id'       => $ctx->cmpId,
            'bo_id'        => $ctx->boId,
            'actor_uuid'   => $actorUuid,
            'actor_kind'   => $actorKind,
            'source_app'   => $sourceApp,
            'actor_label'  => $actorLabel !== null ? mb_substr($actorLabel, 0, 200) : null,
            'action'       => $action,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId === null ? null : (string) $entityId,
            'entity_label' => $label !== null ? mb_substr($label, 0, 200) : null,
            'summary'      => mb_substr($summary ?? self::summarise($action, $entityType, $label, $entityId), 0, 500),
            'before_state' => $before === null ? null : self::redact($before),
            'after_state'  => $after === null ? null : self::redact($after),
            'meta_json'    => $meta === [] ? null : $meta,
            'reason'       => $reason !== '' ? $reason : null,
            'severity'     => $severity ?? self::severityFor($action),
            'request_id'   => self::requestId(),
            'user_agent'   => self::userAgent(),
            'ip_address'   => self::clientIp(),
            'created_at'   => Clock::nowSql(),
        ];
    }

    /**
     * Inside a transaction a failed INSERT would poison it. The savepoint keeps a best-effort
     * audit failure from taking the caller's work with it; a fail-closed caller wants exactly that
     * poisoning, so material() lets the exception through after the savepoint is rolled back.
     *
     * @param array<string, mixed> $row
     */
    private static function insertRow(array $row): void
    {
        $pdo = Db::connect();
        if (!$pdo->inTransaction()) {
            Db::insert(self::TABLE, $row, 'audit_id');

            return;
        }
        $pdo->exec('SAVEPOINT audit_row');
        try {
            Db::insert(self::TABLE, $row, 'audit_id');
            $pdo->exec('RELEASE SAVEPOINT audit_row');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT audit_row');
            throw $e;
        }
    }

    private static function actorLabel(Auth $auth): ?string
    {
        $name = $auth->displayName();

        return $name !== '' && $name !== $auth->uuid ? $name : null;
    }

    /** @param array<string, mixed>|null $state */
    private static function labelFrom(?array $state): ?string
    {
        foreach (['name', 'title', 'label', 'profile_name', 'template', 'reference', 'display_name'] as $key) {
            if (isset($state[$key]) && is_string($state[$key]) && $state[$key] !== '') {
                return $state[$key];
            }
        }

        return null;
    }

    private static function summarise(string $action, string $entityType, ?string $label, int|string|null $entityId): string
    {
        $what = str_replace('_', ' ', $entityType);
        $ref = $label ?? ($entityId !== null && $entityId !== '' ? self::shortId((string) $entityId) : '');
        $verb = ucfirst(str_replace(['.', '_'], ' ', substr(strstr($action, '.') ?: '.' . $action, 1)));

        return trim($verb . ' — ' . $what . ($ref !== '' ? ' ' . $ref : ''));
    }

    private static function shortId(string $id): string
    {
        // A uuid is unreadable in a sentence; the first group identifies it well enough.
        return preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27}$/i', $id) === 1 ? substr($id, 0, 8) : $id;
    }

    private static function isSecretKey(string $name): bool
    {
        return preg_match('/(secret|token|password|passwd|otp|api[_-]?key|private[_-]?key|credential|signature|authorization|bearer|\bpin\b|cvv)/i', $name) === 1;
    }

    private static function comparable(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
    }

    /** A value as it should read in a diff: content hidden, contact details masked, long text cut. */
    private static function readable(string $field, mixed $value): mixed
    {
        $lower = strtolower($field);
        if ($value === null || $value === '') {
            return $value;
        }
        if (in_array($lower, self::CONTENT_KEYS, true)) {
            return '[content not logged]';
        }
        if (in_array($lower, self::ADDRESS_KEYS, true) && is_scalar($value)) {
            return self::mask((string) $value);
        }
        if (is_array($value)) {
            return self::redact($value);
        }

        return is_string($value) && mb_strlen($value) > 500 ? mb_substr($value, 0, 500) . '…' : $value;
    }

    /**
     * Strip anything that must not come to rest in an audit row.
     *
     * Credentials first — a channel connection's before/after state would
     * otherwise carry the token somebody just rotated. Then message and note
     * content (recorded as "not logged"), then customers' contact details
     * (masked). Then the foreign payloads: a key called `books_response` or
     * `contact` is a mirror waiting to happen, and it is dropped rather than
     * truncated so nobody can later "just read it from the audit log".
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function redact(array $state): array
    {
        $foreign = ['books_response', 'contacts_response', 'pay_response', 'sales_response',
            'appointments_response', 'contact', 'invoice', 'order', 'payment', 'appointment',
            'foreign_payload', 'source_payload'];

        $out = [];
        foreach ($state as $key => $value) {
            $name = (string) $key;
            $lower = strtolower($name);

            if (self::isSecretKey($name)) {
                $out[$name] = '[redacted]';
                continue;
            }
            if (in_array($lower, $foreign, true)) {
                $out[$name] = '[foreign data not stored — read live from the owning product]';
                continue;
            }
            if (in_array($lower, self::CONTENT_KEYS, true) && $value !== null && $value !== '') {
                $out[$name] = '[content not logged]';
                continue;
            }
            if (in_array($lower, self::ADDRESS_KEYS, true) && is_scalar($value) && $value !== '') {
                $out[$name] = self::mask((string) $value);
                continue;
            }
            if (is_array($value)) {
                $out[$name] = self::redact($value);
                continue;
            }
            $out[$name] = is_string($value) && strlen($value) > 4000
                ? substr($value, 0, 4000) . '…[truncated]'
                : $value;
        }

        return $out;
    }

    private static function userAgent(): ?string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        return $ua === '' ? null : mb_substr($ua, 0, 255);
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
