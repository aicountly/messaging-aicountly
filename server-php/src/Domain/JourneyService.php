<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Journeys: list, edit the draft, publish an immutable version.
 *
 * ## Draft and published are different things, structurally
 *
 * A draft version can be edited. A published version cannot — `publish()`
 * refuses a version that already has `published_at` set, and the definition's
 * sha-256 is stored alongside it. A run references a published version by
 * number, so "what did this journey do last Tuesday" has an answer that no
 * subsequent editing can change.
 *
 * That is also why `execute()` will not run a draft: a run whose definition can
 * still change is a run whose history is fiction.
 */
final class JourneyService
{
    /**
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public static function index(Context $ctx, array $filters, int $limit, int $offset): array
    {
        [$scope, $params] = $ctx->scopeClause('j');
        $where = [$scope];

        if (($filters['status'] ?? '') !== '') {
            $where[] = 'j.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['kind'] ?? '') !== '') {
            $where[] = 'j.kind = :kind';
            $params['kind'] = $filters['kind'];
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM messaging_journeys j WHERE ' . $whereSql, $params) ?? 0);

        $rows = Db::all(
            'SELECT j.*,
                    (SELECT COUNT(*) FROM messaging_journey_runs r
                      WHERE r.journey_uuid = j.journey_uuid AND r.mode = \'live\') AS run_count,
                    (SELECT MAX(started_at) FROM messaging_journey_runs r
                      WHERE r.journey_uuid = j.journey_uuid AND r.mode = \'live\') AS last_run_at
             FROM messaging_journeys j WHERE ' . $whereSql . '
             ORDER BY j.updated_at DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return ['rows' => array_map([self::class, 'shape'], $rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, string $journeyUuid, ?int $version = null): ?array
    {
        if (!Uuid::isValid($journeyUuid)) {
            return null;
        }

        $row = Db::first(
            'SELECT * FROM messaging_journeys WHERE cmp_id = :cmp AND journey_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $journeyUuid],
        );
        if ($row === null) {
            return null;
        }

        $shaped = self::shape($row);
        $wanted = $version ?? (int) ($row['draft_version'] ?? 1);

        $versionRow = Db::first(
            'SELECT * FROM messaging_journey_versions
             WHERE journey_uuid = :uuid AND version = :ver',
            ['uuid' => $journeyUuid, 'ver' => $wanted],
        );

        $shaped['version'] = $versionRow === null ? null : [
            'version'         => (int) $versionRow['version'],
            'definition'      => Db::jsonColumn($versionRow['definition'] ?? null),
            'validation'      => Db::jsonColumn($versionRow['validation'] ?? null),
            'published_at'    => $versionRow['published_at'] ?? null,
            'published_by'    => $versionRow['published_by'] ?? null,
            'definition_hash' => (string) ($versionRow['definition_hash'] ?? ''),
            'editable'        => $versionRow['published_at'] === null,
        ];

        $shaped['versions'] = Db::all(
            'SELECT version, published_at, published_by, definition_hash
             FROM messaging_journey_versions WHERE journey_uuid = :uuid ORDER BY version DESC',
            ['uuid' => $journeyUuid],
        );

        return $shaped;
    }

    /**
     * Create a journey, or save its draft version.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool, code:string, detail:string, journey_uuid:?string, version:?int, validation:array<string,mixed>}
     */
    public static function save(Context $ctx, Auth $auth, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return self::fail('validation_failed', 'A journey needs a name.');
        }

        $definition = is_array($input['definition'] ?? null) ? $input['definition'] : [];
        $validation = JourneyDefinition::validate($ctx, $definition);
        $journeyUuid = ($input['journey_uuid'] ?? null) ?: null;

        $result = Db::transaction(static function () use ($ctx, $auth, $journeyUuid, $name, $input, $definition, $validation): array {
            if ($journeyUuid === null) {
                $uuid = Uuid::v4();
                Db::insert('messaging_journeys', [
                    'journey_uuid'  => $uuid,
                    'cmp_id'        => $ctx->cmpId,
                    'bo_id'         => $ctx->boId,
                    'name'          => $name,
                    'description'   => (string) ($input['description'] ?? ''),
                    'kind'          => self::kindOf($input),
                    'status'        => 'draft',
                    'draft_version' => 1,
                    'created_at'    => Clock::nowSql(),
                    'created_by'    => $auth->uuid,
                    'updated_at'    => Clock::nowSql(),
                    'updated_by'    => $auth->uuid,
                ], 'journey_uuid');
                $version = 1;
            } else {
                $uuid = (string) $journeyUuid;
                $existing = Db::first(
                    'SELECT draft_version, published_version FROM messaging_journeys
                     WHERE cmp_id = :cmp AND journey_uuid = :uuid FOR UPDATE',
                    ['cmp' => $ctx->cmpId, 'uuid' => $uuid],
                );
                if ($existing === null) {
                    return self::fail('not_found', 'That journey could not be found.');
                }

                $version = (int) $existing['draft_version'];
                $versionRow = Db::first(
                    'SELECT published_at FROM messaging_journey_versions WHERE journey_uuid = :uuid AND version = :ver',
                    ['uuid' => $uuid, 'ver' => $version],
                );

                // Editing a PUBLISHED version starts a new draft version
                // instead. The published bytes are what runs reference and they
                // are never touched.
                if ($versionRow !== null && $versionRow['published_at'] !== null) {
                    $version++;
                    Db::run(
                        'UPDATE messaging_journeys SET draft_version = :ver WHERE journey_uuid = :uuid',
                        ['ver' => $version, 'uuid' => $uuid],
                    );
                }

                Db::run(
                    'UPDATE messaging_journeys SET name = :name, description = :desc, updated_at = :now, updated_by = :actor
                     WHERE journey_uuid = :uuid',
                    [
                        'name'  => $name,
                        'desc'  => (string) ($input['description'] ?? ''),
                        'now'   => Clock::nowSql(),
                        'actor' => $auth->uuid,
                        'uuid'  => $uuid,
                    ],
                );
            }

            Db::run(
                'INSERT INTO messaging_journey_versions
                    (journey_uuid, cmp_id, version, definition, validation, created_at, created_by)
                 VALUES (:uuid, :cmp, :ver, :def, :val, :now, :actor)
                 ON CONFLICT (journey_uuid, version) DO UPDATE
                    SET definition = :def, validation = :val
                    WHERE messaging_journey_versions.published_at IS NULL',
                [
                    'uuid'  => $uuid,
                    'cmp'   => $ctx->cmpId,
                    'ver'   => $version,
                    'def'   => json_encode($definition, JSON_UNESCAPED_UNICODE),
                    'val'   => json_encode($validation, JSON_UNESCAPED_UNICODE),
                    'now'   => Clock::nowSql(),
                    'actor' => $auth->uuid,
                ],
            );

            return [
                'ok'           => true,
                'code'         => 'ok',
                'detail'       => $validation['valid']
                    ? 'Draft saved. It is ready to review and publish.'
                    : 'Draft saved. It cannot be published yet — see the validation messages.',
                'journey_uuid' => $uuid,
                'version'      => $version,
                'validation'   => $validation,
            ];
        });

        return $result;
    }

    /**
     * Publish a draft version.
     *
     * Refuses an invalid definition, and refuses to re-publish. After this the
     * version's bytes are fixed and its hash is recorded.
     *
     * @return array{ok:bool, code:string, detail:string, validation:array<string,mixed>}
     */
    /**
     * The journey kind, narrowed to the vocabulary the schema enforces.
     *
     * Anything unrecognised — including nothing at all — is 'custom'. The
     * column has a CHECK on it, so a value that slipped through here would be
     * a 500 at insert rather than a validation message.
     *
     * @param array<string, mixed> $input
     */
    private static function kindOf(array $input): string
    {
        $kind = (string) ($input['kind'] ?? 'custom');

        return in_array($kind, [
            'overdue_invoice_reminder', 'appointment_reminder', 'order_update',
            'unanswered_enquiry_followup', 'custom',
        ], true) ? $kind : 'custom';
    }

    public static function publish(Context $ctx, Auth $auth, string $journeyUuid, int $version): array
    {
        return Db::transaction(static function () use ($ctx, $auth, $journeyUuid, $version): array {
            $journey = Db::first(
                'SELECT * FROM messaging_journeys WHERE cmp_id = :cmp AND journey_uuid = :uuid FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'uuid' => $journeyUuid],
            );
            if ($journey === null) {
                return ['ok' => false, 'code' => 'not_found', 'detail' => 'That journey could not be found.', 'validation' => []];
            }

            $versionRow = Db::first(
                'SELECT * FROM messaging_journey_versions WHERE journey_uuid = :uuid AND version = :ver FOR UPDATE',
                ['uuid' => $journeyUuid, 'ver' => $version],
            );
            if ($versionRow === null) {
                return ['ok' => false, 'code' => 'not_found', 'detail' => 'That version could not be found.', 'validation' => []];
            }
            if ($versionRow['published_at'] !== null) {
                return [
                    'ok'     => false,
                    'code'   => 'already_published',
                    'detail' => 'Version ' . $version . ' is already published. Save a new draft to make changes.',
                    'validation' => [],
                ];
            }

            $definition = Db::jsonColumn($versionRow['definition'] ?? null);

            // Re-validated at publish time against CURRENT configuration. A
            // definition that validated last week may not now: a template can
            // have been paused by the provider, a channel disconnected.
            $validation = JourneyDefinition::validate($ctx, $definition);
            if (!$validation['valid']) {
                return [
                    'ok'     => false,
                    'code'   => 'validation_failed',
                    'detail' => 'This journey cannot be published yet. ' . count($validation['errors'])
                        . ' problem(s) need fixing first.',
                    'validation' => $validation,
                ];
            }

            $hash = hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE) ?: '');

            Db::run(
                'UPDATE messaging_journey_versions
                 SET published_at = :now, published_by = :actor, definition_hash = :hash, validation = :val
                 WHERE journey_uuid = :uuid AND version = :ver',
                [
                    'now'   => Clock::nowSql(),
                    'actor' => $auth->uuid,
                    'hash'  => $hash,
                    'val'   => json_encode($validation, JSON_UNESCAPED_UNICODE),
                    'uuid'  => $journeyUuid,
                    'ver'   => $version,
                ],
            );

            Db::run(
                'UPDATE messaging_journeys
                 SET status = :published, published_version = :ver, updated_at = :now, updated_by = :actor
                 WHERE journey_uuid = :uuid',
                [
                    'published' => 'published',
                    'ver'       => $version,
                    'now'       => Clock::nowSql(),
                    'actor'     => $auth->uuid,
                    'uuid'      => $journeyUuid,
                ],
            );

            Db::insert('messaging_approvals', [
                'approval_uuid'   => Uuid::v4(),
                'cmp_id'          => $ctx->cmpId,
                'subject_type'    => 'journey_version',
                'subject_uuid'    => $journeyUuid,
                'subject_id'      => $version,
                'content_hash'    => $hash,
                'decision'        => 'approved',
                'decided_by_uuid' => $auth->uuid,
                'decided_at'      => Clock::nowSql(),
            ], 'approval_uuid');

            return [
                'ok'     => true,
                'code'   => 'ok',
                'detail' => 'Version ' . $version . ' published. Runs will reference exactly these steps.',
                'validation' => $validation,
            ];
        });
    }

    /** Pause or resume a published journey. */
    public static function setStatus(Context $ctx, Auth $auth, string $journeyUuid, string $status): bool
    {
        if (!in_array($status, ['published', 'paused', 'archived'], true)) {
            return false;
        }

        // Only a journey that HAS a published version can be published. A
        // draft cannot be flipped into a runnable state by setting a status.
        $affected = Db::run(
            'UPDATE messaging_journeys SET status = :status, updated_at = :now, updated_by = :actor
             WHERE cmp_id = :cmp AND journey_uuid = :uuid
               AND (:status <> :published OR published_version IS NOT NULL)',
            [
                'status'    => $status,
                'now'       => Clock::nowSql(),
                'actor'     => $auth->uuid,
                'cmp'       => $ctx->cmpId,
                'uuid'      => $journeyUuid,
                'published' => 'published',
            ],
        )->rowCount();

        return $affected > 0;
    }

    /**
     * The published, runnable definition — or null.
     *
     * @return array{version:int, definition:array<string,mixed>, hash:string}|null
     */
    public static function runnableVersion(Context $ctx, string $journeyUuid): ?array
    {
        $journey = Db::first(
            'SELECT status, published_version FROM messaging_journeys
             WHERE cmp_id = :cmp AND journey_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $journeyUuid],
        );
        if ($journey === null || $journey['published_version'] === null || (string) $journey['status'] !== 'published') {
            return null;
        }

        $version = (int) $journey['published_version'];
        $row = Db::first(
            'SELECT definition, definition_hash FROM messaging_journey_versions
             WHERE journey_uuid = :uuid AND version = :ver AND published_at IS NOT NULL',
            ['uuid' => $journeyUuid, 'ver' => $version],
        );
        if ($row === null) {
            return null;
        }

        return [
            'version'    => $version,
            'definition' => Db::jsonColumn($row['definition'] ?? null),
            'hash'       => (string) $row['definition_hash'],
        ];
    }

    /**
     * Run history.
     *
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public static function runs(Context $ctx, array $filters, int $limit, int $offset): array
    {
        [$scope, $params] = $ctx->scopeClause('r');
        $where = [$scope];

        if (($filters['journey_uuid'] ?? '') !== '') {
            $where[] = 'r.journey_uuid = :journey';
            $params['journey'] = $filters['journey_uuid'];
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['mode'] ?? '') !== '') {
            $where[] = 'r.mode = :mode';
            $params['mode'] = $filters['mode'];
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM messaging_journey_runs r WHERE ' . $whereSql, $params) ?? 0);

        $rows = Db::all(
            'SELECT r.*, j.name AS journey_name, j.kind
             FROM messaging_journey_runs r
             JOIN messaging_journeys j ON j.journey_uuid = r.journey_uuid
             WHERE ' . $whereSql . '
             ORDER BY r.started_at DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public static function run(Context $ctx, string $runUuid): ?array
    {
        if (!Uuid::isValid($runUuid)) {
            return null;
        }

        $run = Db::first(
            'SELECT r.*, j.name AS journey_name, j.kind
             FROM messaging_journey_runs r
             JOIN messaging_journeys j ON j.journey_uuid = r.journey_uuid
             WHERE r.cmp_id = :cmp AND r.run_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $runUuid],
        );
        if ($run === null) {
            return null;
        }

        $run['steps'] = Db::all(
            'SELECT node_id, node_type, sequence, status, branch, explanation,
                    source_product, source_fetched_at, message_uuid, started_at, finished_at
             FROM messaging_journey_step_runs
             WHERE run_uuid = :uuid ORDER BY sequence',
            ['uuid' => $runUuid],
        );

        // The version this run actually executed, by number and hash. That is
        // what makes the run inspectable as it was.
        $version = Db::first(
            'SELECT definition, definition_hash, published_at FROM messaging_journey_versions
             WHERE journey_uuid = :uuid AND version = :ver',
            ['uuid' => $run['journey_uuid'], 'ver' => $run['journey_version']],
        );
        $run['executed_definition'] = $version === null ? null : Db::jsonColumn($version['definition'] ?? null);
        $run['executed_definition_hash'] = (string) ($version['definition_hash'] ?? '');

        return $run;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shape(array $row): array
    {
        return [
            'journey_uuid'      => (string) $row['journey_uuid'],
            'name'              => (string) $row['name'],
            'description'       => (string) ($row['description'] ?? ''),
            'kind'              => (string) $row['kind'],
            'status'            => (string) $row['status'],
            'published_version' => $row['published_version'] !== null ? (int) $row['published_version'] : null,
            'draft_version'     => (int) ($row['draft_version'] ?? 1),
            'run_count'         => isset($row['run_count']) ? (int) $row['run_count'] : null,
            'last_run_at'       => $row['last_run_at'] ?? null,
            'created_at'        => $row['created_at'] ?? null,
            'updated_at'        => $row['updated_at'] ?? null,
            // Explicit, because "can this actually run?" is the first question
            // anybody has about a journey and a status of 'published' alone
            // does not answer it.
            'runnable'          => (string) $row['status'] === 'published' && $row['published_version'] !== null,
        ];
    }

    /** @return array{ok:bool, code:string, detail:string, journey_uuid:?string, version:?int, validation:array<string,mixed>} */
    private static function fail(string $code, string $detail): array
    {
        return ['ok' => false, 'code' => $code, 'detail' => $detail, 'journey_uuid' => null, 'version' => null, 'validation' => []];
    }
}
