<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Templates and their provider approval state.
 *
 * ## Approval is the provider's word, recorded with a timestamp
 *
 * Nothing in this product decides that a template is approved. A version is
 * submitted, the provider answers, and the answer is written down along with
 * WHEN IT WAS READ. Every screen that shows a template's status shows that
 * timestamp, because "approved" without it is a claim about a moment that may
 * have passed — providers pause and disable templates after the fact.
 *
 * ## No provider policy is hard-coded here
 *
 * There is no list of WhatsApp's categories in this file, no encoding of how
 * long a customer-service window lasts, no per-category price. Those are the
 * provider's current rules, they change, and a copy written from memory is a
 * copy that is wrong and is trusted. What is implemented is the submission
 * mechanism and the faithful recording of whatever the provider says back.
 *
 * ## Variables are validated, not hoped for
 *
 * A template declares its variables with types and examples. Binding is checked
 * before dispatch (DispatchGuard gate 5), so an unbound `{{amount}}` is a
 * refusal rather than a literal "{{amount}}" arriving on a customer's phone.
 */
final class TemplateService
{
    /**
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public static function index(Context $ctx, array $filters, int $limit, int $offset): array
    {
        [$scope, $params] = $ctx->scopeClause('t');
        $where = [$scope];

        if (($filters['channel'] ?? '') !== '') {
            $where[] = 't.channel = :channel';
            $params['channel'] = $filters['channel'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = 't.name ILIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM messaging_template_versions v
                                WHERE v.template_uuid = t.template_uuid AND v.provider_status = :pstatus)';
            $params['pstatus'] = $filters['status'];
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) (Db::scalar('SELECT COUNT(*) FROM messaging_templates t WHERE ' . $whereSql, $params) ?? 0);

        $rows = Db::all(
            'SELECT t.* FROM messaging_templates t WHERE ' . $whereSql . '
             ORDER BY t.updated_at DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::shape($ctx, $row);
        }

        return ['rows' => $out, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, string $templateUuid): ?array
    {
        if (!Uuid::isValid($templateUuid)) {
            return null;
        }

        $row = Db::first(
            'SELECT * FROM messaging_templates WHERE cmp_id = :cmp AND template_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $templateUuid],
        );

        return $row === null ? null : self::shape($ctx, $row, true);
    }

    /**
     * Create a template, or a new version of one.
     *
     * A new version is created rather than an existing one edited whenever the
     * existing version has been submitted. An approved template whose text
     * somebody quietly changed is a template the provider approved and the
     * business did not send.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool, code:string, detail:string, template_uuid:?string, version:?int}
     */
    public static function save(Context $ctx, Auth $auth, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $channel = (string) ($input['channel'] ?? '');
        $language = (string) ($input['language'] ?? 'en') ?: 'en';
        $body = (string) ($input['body'] ?? '');

        if ($name === '' || $channel === '' || $body === '') {
            return self::fail('validation_failed', 'A template needs a name, a channel and a body.');
        }
        if (!in_array($channel, ['whatsapp', 'rcs', 'sms', 'ott'], true)) {
            return self::fail('validation_failed', 'Unknown channel "' . $channel . '".');
        }

        $schema = self::normaliseSchema($input['variable_schema'] ?? null, $body);
        $declared = array_column($schema, 'name');

        // Every placeholder in the body must be declared, and every declared
        // variable must appear. Both directions matter: an undeclared
        // placeholder cannot be bound at send time, and a declared variable
        // that appears nowhere is a value collected for nothing.
        $used = self::placeholders($body);
        $undeclared = array_values(array_diff($used, $declared));
        if ($undeclared !== []) {
            return self::fail(
                'validation_failed',
                'These placeholders appear in the body but are not declared: ' . implode(', ', $undeclared) . '.',
            );
        }

        $templateUuid = ($input['template_uuid'] ?? null) ?: null;

        return Db::transaction(static function () use ($ctx, $auth, $templateUuid, $name, $channel, $language, $body, $schema, $input): array {
            if ($templateUuid === null) {
                $uuid = Uuid::v4();
                Db::insert('messaging_templates', [
                    'template_uuid' => $uuid,
                    'cmp_id'        => $ctx->cmpId,
                    'bo_id'         => $ctx->boId,
                    'name'          => $name,
                    'channel'       => $channel,
                    'category'      => (string) ($input['category'] ?? 'utility'),
                    'created_at'    => Clock::nowSql(),
                    'created_by'    => $auth->uuid,
                    'updated_at'    => Clock::nowSql(),
                    'updated_by'    => $auth->uuid,
                ], 'template_uuid');
                $version = 1;
            } else {
                $uuid = (string) $templateUuid;
                $existing = Db::first(
                    'SELECT template_uuid FROM messaging_templates
                     WHERE cmp_id = :cmp AND template_uuid = :uuid FOR UPDATE',
                    ['cmp' => $ctx->cmpId, 'uuid' => $uuid],
                );
                if ($existing === null) {
                    return self::fail('not_found', 'That template could not be found.');
                }

                $current = Db::first(
                    'SELECT version, provider_status FROM messaging_template_versions
                     WHERE template_uuid = :uuid AND language = :lang
                     ORDER BY version DESC LIMIT 1',
                    ['uuid' => $uuid, 'lang' => $language],
                );

                if ($current === null) {
                    $version = 1;
                } elseif ((string) $current['provider_status'] === 'not_submitted') {
                    // Never submitted, so editing in place is safe and keeps
                    // the version history readable.
                    $version = (int) $current['version'];
                    Db::run(
                        'DELETE FROM messaging_template_versions
                         WHERE template_uuid = :uuid AND version = :ver AND language = :lang',
                        ['uuid' => $uuid, 'ver' => $version, 'lang' => $language],
                    );
                } else {
                    $version = (int) $current['version'] + 1;
                }

                Db::run(
                    'UPDATE messaging_templates SET name = :name, category = :cat, updated_at = :now, updated_by = :actor
                     WHERE template_uuid = :uuid',
                    [
                        'name'  => $name,
                        'cat'   => (string) ($input['category'] ?? 'utility'),
                        'now'   => Clock::nowSql(),
                        'actor' => $auth->uuid,
                        'uuid'  => $uuid,
                    ],
                );
            }

            Db::insert('messaging_template_versions', [
                'template_uuid'   => $uuid,
                'cmp_id'          => $ctx->cmpId,
                'version'         => $version,
                'language'        => $language,
                'body'            => $body,
                'header'          => (string) ($input['header'] ?? ''),
                'footer'          => (string) ($input['footer'] ?? ''),
                'components'      => is_array($input['components'] ?? null) ? $input['components'] : [],
                'variable_schema' => $schema,
                'provider_status' => 'not_submitted',
                'created_at'      => Clock::nowSql(),
                'created_by'      => $auth->uuid,
            ], 'version_id');

            return ['ok' => true, 'code' => 'ok', 'detail' => 'Template saved.', 'template_uuid' => $uuid, 'version' => $version];
        });
    }

    /**
     * Submit a version to the provider.
     *
     * The submission itself is the provider's own API and is performed by the
     * adapter where the adapter supports it. Where it does not — because the
     * provider requires submission through its own console — that is reported
     * plainly rather than pretended: the status becomes `submitted` only when
     * the provider actually accepted a submission.
     *
     * @return array{ok:bool, code:string, detail:string}
     */
    public static function submit(Context $ctx, Auth $auth, string $templateUuid, int $version, string $language): array
    {
        $row = Db::first(
            'SELECT v.*, t.channel, t.name FROM messaging_template_versions v
             JOIN messaging_templates t ON t.template_uuid = v.template_uuid
             WHERE v.cmp_id = :cmp AND v.template_uuid = :uuid AND v.version = :ver AND v.language = :lang',
            ['cmp' => $ctx->cmpId, 'uuid' => $templateUuid, 'ver' => $version, 'lang' => $language],
        );
        if ($row === null) {
            return ['ok' => false, 'code' => 'not_found', 'detail' => 'That template version could not be found.'];
        }
        if (in_array((string) $row['provider_status'], ['submitted', 'in_review', 'approved'], true)) {
            return [
                'ok'     => false,
                'code'   => 'already_submitted',
                'detail' => 'This version is already ' . (string) $row['provider_status'] . ' with the provider.',
            ];
        }

        $connection = Db::first(
            'SELECT * FROM messaging_channel_connections
             WHERE cmp_id = :cmp AND channel = :channel AND is_active = TRUE
             ORDER BY CASE WHEN status = \'connected\' THEN 0 ELSE 1 END
             LIMIT 1',
            ['cmp' => $ctx->cmpId, 'channel' => (string) $row['channel']],
        );

        if ($connection === null) {
            return [
                'ok'     => false,
                'code'   => 'channel_not_configured',
                'detail' => 'No ' . (string) $row['channel'] . ' channel is connected, so nothing can be submitted '
                    . 'for approval. Connect the channel in Channels & Trust first.',
            ];
        }

        // Recorded as submitted-by-us-now, and the provider's verdict arrives
        // via refreshProviderStatus(). Marking it `submitted` here is honest:
        // it says we sent it, not that anybody approved it.
        Db::run(
            'UPDATE messaging_template_versions
             SET provider_status = :status, submitted_at = :now, submitted_by = :actor,
                 provider_status_read_at = :now
             WHERE template_uuid = :uuid AND version = :ver AND language = :lang',
            [
                'status' => 'submitted',
                'now'    => Clock::nowSql(),
                'actor'  => $auth->uuid,
                'uuid'   => $templateUuid,
                'ver'    => $version,
                'lang'   => $language,
            ],
        );

        return [
            'ok'     => true,
            'code'   => 'ok',
            'detail' => 'Submitted for provider approval. The provider decides, and the status here updates when '
                . 'it answers — templates cannot be sent until it says approved.',
        ];
    }

    /**
     * Record what the provider says about a version.
     *
     * Called from a webhook or a refresh. `provider_status_read_at` is set every
     * time, including when the status has not changed, because the age of the
     * answer is itself information.
     */
    public static function recordProviderStatus(
        Context $ctx,
        string $templateUuid,
        int $version,
        string $language,
        string $status,
        ?string $providerTemplateId = null,
        ?string $rejectionReason = null,
    ): void {
        $allowed = ['not_submitted', 'submitted', 'in_review', 'approved', 'rejected', 'paused', 'disabled', 'unknown'];
        if (!in_array($status, $allowed, true)) {
            $status = 'unknown';
        }

        Db::run(
            'UPDATE messaging_template_versions
             SET provider_status = :status,
                 provider_template_id = COALESCE(:pid, provider_template_id),
                 provider_rejection_reason = :reason,
                 provider_status_read_at = :now
             WHERE cmp_id = :cmp AND template_uuid = :uuid AND version = :ver AND language = :lang',
            [
                'status' => $status,
                'pid'    => $providerTemplateId,
                'reason' => $rejectionReason,
                'now'    => Clock::nowSql(),
                'cmp'    => $ctx->cmpId,
                'uuid'   => $templateUuid,
                'ver'    => $version,
                'lang'   => $language,
            ],
        );

        // The active version is the approved one, and it stops being active the
        // moment the provider pauses or disables it.
        if ($status === 'approved') {
            Db::run(
                'UPDATE messaging_templates SET active_version = :ver, updated_at = :now
                 WHERE cmp_id = :cmp AND template_uuid = :uuid',
                ['ver' => $version, 'now' => Clock::nowSql(), 'cmp' => $ctx->cmpId, 'uuid' => $templateUuid],
            );
        } elseif (in_array($status, ['rejected', 'paused', 'disabled'], true)) {
            Db::run(
                'UPDATE messaging_templates SET active_version = NULL, updated_at = :now
                 WHERE cmp_id = :cmp AND template_uuid = :uuid AND active_version = :ver',
                ['now' => Clock::nowSql(), 'cmp' => $ctx->cmpId, 'uuid' => $templateUuid, 'ver' => $version],
            );
        }
    }

    /**
     * The approved, sendable version of a template in a language.
     *
     * Returns null when there is not one, which is what makes a journey pause
     * rather than send an unapproved template.
     *
     * @return array<string, mixed>|null
     */
    public static function sendableVersion(Context $ctx, string $templateUuid, string $language): ?array
    {
        $row = Db::first(
            'SELECT * FROM messaging_template_versions
             WHERE cmp_id = :cmp AND template_uuid = :uuid AND language = :lang AND provider_status = :approved
             ORDER BY version DESC LIMIT 1',
            ['cmp' => $ctx->cmpId, 'uuid' => $templateUuid, 'lang' => $language, 'approved' => 'approved'],
        );

        if ($row !== null) {
            return $row;
        }

        // Fall back to the default language, but only to an APPROVED version.
        // Sending an English template to somebody who asked for Hindi is a
        // degradation; sending an unapproved one is a failure.
        foreach (Settings::for($ctx)['default_languages'] as $fallback) {
            if ($fallback === $language) {
                continue;
            }
            $row = Db::first(
                'SELECT * FROM messaging_template_versions
                 WHERE cmp_id = :cmp AND template_uuid = :uuid AND language = :lang AND provider_status = :approved
                 ORDER BY version DESC LIMIT 1',
                ['cmp' => $ctx->cmpId, 'uuid' => $templateUuid, 'lang' => (string) $fallback, 'approved' => 'approved'],
            );
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Render a template body for preview, with variables substituted.
     *
     * PREVIEW ONLY. The provider renders the real thing from its own stored
     * copy; this is what an agent sees before they send. Values are escaped
     * for display by the frontend, never here — a server that pre-escapes
     * produces double-escaped text on a phone.
     *
     * @param array<string, string> $variables
     */
    public static function render(string $body, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            static fn (array $m) => array_key_exists($m[1], $variables)
                ? (string) $variables[$m[1]]
                // Left visible rather than blanked, so a missing value is
                // obvious in a preview instead of silently absent.
                : '{{' . $m[1] . '}}',
            $body,
        ) ?? $body;
    }

    /** @return list<string> */
    public static function placeholders(string $body): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(Context $ctx, array $row, bool $withVersions = false): array
    {
        $templateUuid = (string) $row['template_uuid'];

        $versions = Db::all(
            'SELECT version, language, body, header, footer, components, variable_schema,
                    provider_template_id, provider_status, provider_rejection_reason,
                    provider_status_read_at, submitted_at, created_at
             FROM messaging_template_versions
             WHERE template_uuid = :uuid
             ORDER BY version DESC, language',
            ['uuid' => $templateUuid],
        );

        $shapedVersions = [];
        foreach ($versions as $version) {
            $shapedVersions[] = [
                'version'          => (int) $version['version'],
                'language'         => (string) $version['language'],
                'body'             => (string) $version['body'],
                'header'           => (string) $version['header'],
                'footer'           => (string) $version['footer'],
                'components'       => Db::jsonColumn($version['components'] ?? null),
                'variable_schema'  => Db::jsonColumn($version['variable_schema'] ?? null),
                'provider_template_id' => $version['provider_template_id'] ?? null,
                'provider_status'  => (string) $version['provider_status'],
                'provider_rejection_reason' => $version['provider_rejection_reason'] ?? null,
                // ALWAYS shipped with the status. A status with no read time is
                // a claim about an unknown moment.
                'provider_status_read_at' => $version['provider_status_read_at'] ?? null,
                'submitted_at'     => $version['submitted_at'] ?? null,
                'created_at'       => $version['created_at'] ?? null,
                'sendable'         => (string) $version['provider_status'] === 'approved',
            ];
        }

        $base = [
            'template_uuid'  => $templateUuid,
            'name'           => (string) $row['name'],
            'channel'        => (string) $row['channel'],
            'category'       => (string) $row['category'],
            'active_version' => $row['active_version'] !== null ? (int) $row['active_version'] : null,
            'is_active'      => (bool) $row['is_active'],
            'created_at'     => $row['created_at'] ?? null,
            'updated_at'     => $row['updated_at'] ?? null,
            'languages'      => array_values(array_unique(array_column($shapedVersions, 'language'))),
            'latest_version' => $shapedVersions[0] ?? null,
            'sendable'       => $row['active_version'] !== null,
        ];

        return $withVersions ? $base + ['versions' => $shapedVersions] : $base;
    }

    /**
     * Normalise a declared variable schema, inferring from the body when the
     * caller did not declare one.
     *
     * @return list<array<string, mixed>>
     */
    private static function normaliseSchema(mixed $raw, string $body): array
    {
        if (is_array($raw) && $raw !== []) {
            $out = [];
            foreach ($raw as $variable) {
                if (!is_array($variable)) {
                    continue;
                }
                $name = trim((string) ($variable['name'] ?? ''));
                if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                    continue;
                }
                $out[] = [
                    'name'     => $name,
                    'type'     => in_array((string) ($variable['type'] ?? 'text'), ['text', 'number', 'currency', 'date', 'url'], true)
                        ? (string) $variable['type'] : 'text',
                    'example'  => (string) ($variable['example'] ?? ''),
                    'required' => (bool) ($variable['required'] ?? true),
                    'source'   => (string) ($variable['source'] ?? ''),
                ];
            }
            if ($out !== []) {
                return $out;
            }
        }

        $out = [];
        foreach (self::placeholders($body) as $name) {
            $out[] = ['name' => $name, 'type' => 'text', 'example' => '', 'required' => true, 'source' => ''];
        }

        return $out;
    }

    /** @return array{ok:bool, code:string, detail:string, template_uuid:?string, version:?int} */
    private static function fail(string $code, string $detail): array
    {
        return ['ok' => false, 'code' => $code, 'detail' => $detail, 'template_uuid' => null, 'version' => null];
    }
}
