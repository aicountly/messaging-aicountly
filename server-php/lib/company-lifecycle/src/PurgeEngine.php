<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

use PDO;

/**
 * Archive-first purge of one company's (scope "company") or one person's (scope "user") rows, driven
 * only by the reviewed PurgeManifest.
 *
 * Before anything is written, plan() checks the manifest against the live database and REFUSES when:
 *   - a listed table or column does not exist;
 *   - (strict) a table carrying one of the scope's key columns is neither in that scope's lists nor in
 *     ignore — it was added after the review, and purging around it would leave the data behind;
 *   - a table outside the scope's purge list has a foreign key into a purged table with ON DELETE
 *     CASCADE / SET NULL / SET DEFAULT — deleting would change its rows without archiving them
 *     (retained tables included: a cascade would destroy what the law says to keep);
 *   - two purged tables are linked by a foreign key but the child is not listed before its parent —
 *     unless that foreign key is DEFERRABLE (the purge then runs SET CONSTRAINTS ALL DEFERRED) or the
 *     manifest breaks the cycle (break_cycle: the column is set to NULL on the purged rows first);
 *   - a 1.1 option does not fit the database (retain_referenced without a reference, scrub of a
 *     column that cannot take the value, break_cycle on a NOT NULL column, …).
 * It never touches a table that is not in the manifest, nor the kit's own aic_lifecycle_* tables.
 *
 * purge() then, inside ONE transaction:
 *   1. computes which rows of retain_referenced tables a remaining row still references (one
 *      recursive query over the foreign keys; kept rows stay, the rest are purged);
 *   2. creates schema aic_lifecycle_archive_<c{cmp_id}|u{uuid}>_<UTC YmdHis> with a _aic_archive index;
 *   3. for each table in manifest order: copies the target's rows into the archive (scrubbed),
 *      deletes them, and checks deleted = archived (a concurrent writer makes it roll back and fail,
 *      never half-purge);
 *   4. scrubs the retained rows (scrub rules);
 * commits, then reads the rows back (none live, all archived). Any error rolls everything back.
 * Retained tables are only counted (and scrubbed), never copied or deleted; they are reported with
 * their legal reason. Dry run (purge(…, true)) reports the same counts and changes nothing.
 *
 * expire() (bin/worker.php --expire-retained) deletes, archive-first, the retained rows of a purged
 * company / person whose retain_from + retain_years / retain_days has passed (and retain_where rows
 * likewise), then the retain_referenced rows (and their via children) no longer referenced, and
 * records it in aic_lifecycle_expiries (schema-1.1.sql). Nothing else of the target is touched.
 *
 * restore() puts an archive back (parents first; rows a hook archived are put back over the changed
 * ones); archives() / dropArchive() manage the archive schemas, which hold personal data and must be
 * dropped after the product's archive window.
 */
final class PurgeEngine
{
    public const ARCHIVE_PREFIX = 'aic_lifecycle_archive';

    public const REDACTED = '[redacted]';

    /** Archive index seq ranges (restore runs seq DESC: purged rows, then hook rows, then expired rows). */
    public const SEQ_HOOK_AFTER = -100000;

    public const SEQ_HOOK_BEFORE = -200000;

    public const SEQ_EXPIRED = -300000;

    private const ARCHIVE_RE = '/^aic_lifecycle_archive_([cu])([0-9]{1,19})_([0-9]{14})$/';

    private const KEEP = 'pg_temp.aic_lifecycle_keep';

    private const TEXT_TYPES = ['text', 'varchar', 'bpchar', 'citext', 'name'];

    private const DATE_TYPES = ['date', 'timestamp', 'timestamptz'];

    /** @var callable(): int */
    private $clock;

    /** @var array<string, array<string, array{type:string, udt:string, nullable:bool}>> columns of the last check */
    private array $cols = [];

    /** @var list<array{0:string,1:string,2:string,3:bool,4:list<string>,5:list<string>}>|null */
    private ?array $fks = null;

    /** @var array<string, bool> scope → an out-of-order foreign key is deferrable */
    private array $defer = [];

    /** @param (callable(): int)|null $clock */
    public function __construct(private PDO $db, private PurgeManifest $manifest, ?callable $clock = null)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function manifest(): PurgeManifest
    {
        return $this->manifest;
    }

    public function pdo(): PDO
    {
        return $this->db;
    }

    public static function archiveName(string $scope, int|string $target, int $ts): string
    {
        return self::ARCHIVE_PREFIX . '_' . ($scope === 'company' ? 'c' : 'u') . $target . '_' . gmdate('YmdHis', $ts);
    }

    /**
     * Validate and count, changing nothing.
     *
     * @return array{ok:bool, problems:list<string>, counts:array<string,int>, retained:array<string,int>, reasons:array<string,string>, total:int, scrub?:array<string,int>}
     */
    public function plan(string $scope, int|string $target): array
    {
        $out = ['ok' => false, 'problems' => [], 'counts' => [], 'retained' => [], 'reasons' => [], 'total' => 0];
        $target = self::target($scope, $target);
        if ($target === null) {
            $out['problems'][] = $scope === 'company' ? 'cmp_id must be a positive integer.' : 'uuid must be a subscriber id (digits).';

            return $out;
        }
        $out['problems'] = $this->problems($scope);
        if ($out['problems'] !== []) {
            return $out;
        }
        $fill = function () use (&$out, $scope, $target): void {
            foreach ($this->manifest->tables($scope) as $e) {
                $n = $this->count($e, $scope, $target, true);
                if ($n > 0) {
                    $out['counts'][$e['qualified']] = $n;
                }
                if (isset($e['retain_where'])) {
                    $k = $this->countWhere($e, '(' . $this->where($e, $scope) . ') AND (' . $e['retain_where'] . ')', $target);
                    if ($k > 0) {
                        $out['retained'][$e['qualified']] = $k;
                        $out['reasons'][$e['qualified']] = (string) $e['reason'];
                    }
                }
                if (self::isRetainReferenced($e)) {
                    $k = $this->countWhere($e, '(' . $this->where($e, $scope) . ') AND ' . $this->keptPredicate($e), $target);
                    if ($k > 0) {
                        $out['retained'][$e['qualified']] = $k;
                        $out['reasons'][$e['qualified']] = (string) $e['reason'];
                    }
                }
            }
            foreach ($this->manifest->retained($scope) as $e) {
                $n = $this->count($e, $scope, $target);
                if ($n > 0) {
                    $out['retained'][$e['qualified']] = $n;
                    $out['reasons'][$e['qualified']] = $e['reason'];
                }
            }
            $scrub = [];
            foreach ($this->scrubTargets($scope) as [$e, $where]) {
                $n = $this->countWhere($e, $where . ' AND (' . $this->scrubDiff($e['qualified']) . ')', $target);
                if ($n > 0) {
                    $scrub[$e['qualified']] = $n;
                }
            }
            if ($scrub !== []) {
                $out['scrub'] = $scrub;
            }
        };
        if ($this->needsKeep($scope) || $this->usesJsonScrub()) {
            $this->scratch(function () use ($fill, $scope, $target): void {
                $this->prepareTransaction($scope, $target, false);
                $fill();
            });
        } else {
            $fill();
        }
        $out['total'] = array_sum($out['counts']);
        $out['ok'] = true;

        return $out;
    }

    /**
     * $archive: the archive schema to use (the Worker passes the one its hooks archive into); default
     * a new aic_lifecycle_archive_<c|u><target>_<now>.
     *
     * @return array{ok:bool, status:string, scope:string, target:string, problems:list<string>, counts:array<string,int>, retained:array<string,int>, reasons:array<string,string>, total:int, archive:?string, error:?string, scrubbed?:array<string,int>}
     */
    public function purge(string $scope, int|string $target, bool $dryRun = false, ?string $archive = null): array
    {
        $plan = $this->plan($scope, $target);
        $base = $plan + ['scope' => $scope, 'target' => (string) $target, 'archive' => null, 'error' => null];
        unset($base['ok']);
        if (!$plan['ok']) {
            return ['ok' => false, 'status' => 'failed', 'error' => 'Refused, nothing was changed: ' . implode(' ', $plan['problems'])] + $base;
        }
        if ($dryRun) {
            return ['ok' => true, 'status' => 'dry_run'] + $base;
        }
        if ($plan['total'] === 0 && array_sum($plan['scrub'] ?? []) === 0) {
            return ['ok' => true, 'status' => 'nothing_to_purge'] + $base;
        }
        if ($archive !== null && preg_match(self::ARCHIVE_RE, $archive) !== 1) {
            throw new \InvalidArgumentException('Not a lifecycle archive schema name: ' . $archive);
        }

        $value = (string) self::target($scope, $target);
        $shared = $archive !== null;
        $archive ??= self::archiveName($scope, $value, ($this->clock)());
        $done = [];
        $scrubbed = [];
        $inTx = [];
        try {
            $this->db->beginTransaction();
            $this->db->exec("SET LOCAL lock_timeout = '30s'");
            $this->prepareTransaction($scope, $value, true);
            if ($plan['total'] > 0) {
                $this->createArchive($archive, $shared);
            }
            $pre = $this->breakLinks($archive, $scope, $value);
            foreach ($this->manifest->tables($scope) as $seq => $e) {
                $n = $this->moveRows($archive, $e, $this->where($e, $scope, 0, null, true), $value, $seq, $scope, $pre[$e['qualified']] ?? null);
                if ($n > 0) {
                    $done[$e['qualified']] = $n;
                }
            }
            // Rows chosen against the keep set are read back here, while it exists.
            foreach ($this->manifest->tables($scope) as $e) {
                if (isset($done[$e['qualified']]) && $this->keepDependent($e, $scope)) {
                    $inTx[$e['qualified']] = true;
                    $problem = $this->readBack($archive, $e, $scope, $value, $done[$e['qualified']]);
                    if ($problem !== null) {
                        throw new \RuntimeException('read-back before commit disagrees: ' . $problem);
                    }
                }
            }
            $scrubbed = $this->scrubRetained($scope, $value);
            $this->db->query('SELECT 1')->fetchColumn();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $msg = preg_replace('/\s+/', ' ', $e->getMessage());
            if (stripos((string) $msg, 'foreign key') !== false) {
                $msg .= ' — a table outside the manifest (or listed later) still references these rows; list it before its parent.';
            }

            return ['ok' => false, 'status' => 'failed', 'error' => 'Rolled back, nothing was changed: ' . $msg] + $base;
        }

        // Read back: the rows left and are in the archive.
        $problems = [];
        foreach ($this->manifest->tables($scope) as $e) {
            if (!isset($done[$e['qualified']]) || isset($inTx[$e['qualified']])) {
                continue;
            }
            $problem = $this->readBack($archive, $e, $scope, $value, $done[$e['qualified']]);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        $base['counts'] = $done;
        $base['total'] = array_sum($done);
        $base['archive'] = $done !== [] || $shared ? $archive : null;
        if ($scrubbed !== []) {
            $base['scrubbed'] = $scrubbed;
        }
        if ($problems !== []) {
            return ['ok' => false, 'status' => 'failed', 'error' => 'Committed, but the read-back disagrees: ' . implode('; ', $problems) . '. Investigate before retrying.'] + $base;
        }

        return ['ok' => true, 'status' => $done === [] && $scrubbed === [] ? 'nothing_to_purge' : 'purged'] + $base;
    }

    /**
     * Retention expiry for a company / person that was already purged: archive-first, delete the
     * retained rows whose retain_from + retain_years has passed ($now), then the retain_referenced
     * rows nothing references any more; record each table in aic_lifecycle_expiries. One transaction.
     * Manage is not told (the company / account is gone there).
     *
     * @return array{ok:bool, status:string, scope:string, target:string, expired:array<string,int>, orphans:array<string,int>, archive:?string, error:?string, problems:list<string>}
     */
    public function expire(string $scope, int|string $target, ?int $now = null, bool $dryRun = false): array
    {
        $out = ['ok' => false, 'status' => 'failed', 'scope' => $scope, 'target' => (string) $target, 'expired' => [], 'orphans' => [], 'archive' => null, 'error' => null, 'problems' => []];
        $value = self::target($scope, $target);
        if ($value === null) {
            $out['error'] = 'Not a valid ' . ($scope === 'company' ? 'cmp_id' : 'uuid') . '.';

            return $out;
        }
        $out['problems'] = $this->problems($scope);
        if ($out['problems'] !== []) {
            $out['error'] = 'Refused, nothing was changed: ' . implode(' ', $out['problems']);

            return $out;
        }
        $now ??= ($this->clock)();
        $entries = [];
        foreach ($this->manifest->retained($scope) as $i => $e) {
            if (self::hasPeriod($e)) {
                $entries[] = [self::SEQ_EXPIRED + $i, $e, '(' . $this->where($e, $scope) . ') AND ' . $this->expiredPredicate($e, $now)];
            }
        }
        foreach ($this->manifest->tables($scope) as $i => $e) {
            if (isset($e['retain_where']) && self::hasPeriod($e)) {
                $entries[] = [self::SEQ_EXPIRED + 10000 + $i, $e, '(' . $this->where($e, $scope) . ') AND (' . $e['retain_where'] . ') AND ' . $this->expiredPredicate($e, $now)];
            }
        }
        $any = false;
        foreach ($entries as [, $e, $where]) {
            if ($this->countWhere($e, $where, $value) > 0) {
                $any = true;
                break;
            }
        }
        if (!$any) {
            return ['ok' => true, 'status' => 'nothing_expired'] + $out;
        }

        $archive = $this->freeArchiveName($scope, $value, $now);
        try {
            $this->db->beginTransaction();
            $this->db->exec("SET LOCAL lock_timeout = '30s'");
            $this->prepareTransaction($scope, $value, false);
            $this->createArchive($archive, false);
            foreach ($entries as [$seq, $e, $where]) {
                $n = $this->moveRows($archive, $e, $where, $value, $seq, $scope, null, isset($e['retain_where']) ? '_aic_x_' : '');
                if ($n > 0) {
                    $out['expired'][$e['qualified']] = $n;
                }
            }
            if ($this->needsKeep($scope)) {
                $this->computeKeep($scope, $value);
            }
            // Only the chain tables: a purged company's other rows (e.g. restored by an operator) are not
            // the expiry's to delete.
            foreach ($this->manifest->tables($scope) as $seq => $e) {
                if (!$this->keepDependent($e, $scope)) {
                    continue;
                }
                $n = $this->moveRows($archive, $e, $this->where($e, $scope, 0, null, true), $value, $seq, $scope, null);
                if ($n > 0) {
                    $out['orphans'][$e['qualified']] = $n;
                }
            }
            if (!$dryRun) {
                $ins = $this->db->prepare('INSERT INTO aic_lifecycle_expiries (kind, target_cmp_id, target_uuid, source_table, rows_deleted, reason, retain_years, retain_days, retain_from, archive_name, expired_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS TIMESTAMPTZ))');
                $byTable = [];
                foreach ($entries as [, $e]) {
                    $byTable[$e['qualified']] = $e;
                }
                foreach (['expired' => 'retention_ended', 'orphans' => 'no_longer_referenced'] as $k => $reason) {
                    foreach ($out[$k] as $q => $n) {
                        $e = $k === 'expired' ? $byTable[$q] : [];
                        $ins->execute([$scope === 'company' ? 'company' : 'account', $scope === 'company' ? (int) $value : null, $scope === 'company' ? null : $value,
                            $q, $n, $reason, $e['retain_years'] ?? null, $e['retain_days'] ?? null, $e['retain_from'] ?? null, $archive, gmdate('Y-m-d\TH:i:s\Z', $now)]);
                    }
                }
            }
            if ($dryRun) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $msg = (string) preg_replace('/\s+/', ' ', $e->getMessage());
            if (str_contains($msg, 'aic_lifecycle_expiries')) {
                $msg .= ' — apply schema-1.1.sql (kit 1.1) first.';
            }
            $out['error'] = 'Rolled back, nothing was changed: ' . $msg;

            return $out;
        }
        $out['archive'] = $dryRun ? null : $archive;

        return ['ok' => true, 'status' => $dryRun ? 'dry_run' : 'expired'] + $out;
    }

    /**
     * Copy rows a product hook is about to change or delete into an archive schema, so a restore puts
     * them back (over the changed rows, by primary key). Rows already archived there are not copied
     * again (the first copy is the original). The table needs a primary key. Returns rows copied.
     *
     * @param list<mixed> $params values for the "?" in $whereSql
     */
    public function archiveRowsInto(string $archive, string $scope, string $target, string $table, string $whereSql, array $params, int $seq): int
    {
        if (preg_match(self::ARCHIVE_RE, $archive) !== 1) {
            throw new \InvalidArgumentException('Not a lifecycle archive schema name: ' . $archive);
        }
        $parts = explode('.', $table);
        if (count($parts) === 1) {
            array_unshift($parts, $this->manifest->schema());
        }
        if (count($parts) !== 2 || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $parts[0]) !== 1 || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $parts[1]) !== 1
            || str_starts_with($parts[1], 'aic_lifecycle_') || str_starts_with($parts[0], 'aic_lifecycle_')) {
            throw new \InvalidArgumentException('archiveRows: table must be a plain [schema.]table name: ' . $table);
        }
        [$schema, $name] = $parts;
        $qualified = $schema . '.' . $name;
        $pk = $this->primaryKey($qualified);
        if ($pk === []) {
            throw new \InvalidArgumentException("archiveRows: {$qualified} has no primary key, so a restore could not put changed rows back.");
        }
        $this->cols = $this->columns([$schema]);
        $src = self::q($schema) . '.' . self::q($name);
        $arch = substr('_aic_h_' . ($schema === $this->manifest->schema() ? $name : $schema . '__' . $name), 0, 63);
        $dst = self::q($archive) . '.' . self::q($arch);
        $keys = implode(', ', array_map([self::class, 'q'], $pk));

        $own = !$this->db->inTransaction();
        $own ? $this->db->beginTransaction() : $this->db->exec('SAVEPOINT aic_archive_rows');
        try {
            if ($this->usesJsonScrub()) {
                $this->scrubFunctions();
            }
            $this->createArchive($archive, true);
            $this->indexColumns($archive);
            $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $dst . ' AS SELECT * FROM ' . $src . ' WITH NO DATA');
            $copy = $this->db->prepare('INSERT INTO ' . $dst . ' SELECT * FROM ' . $src . ' WHERE (' . $whereSql . ') AND (' . $keys . ') NOT IN (SELECT ' . $keys . ' FROM ' . $dst . ')');
            $copy->execute(array_values($params));
            $n = $copy->rowCount();
            $this->scrubArchive($dst, $qualified);
            $upd = $this->db->prepare('UPDATE ' . self::q($archive) . '._aic_archive SET row_count = row_count + ? WHERE archive_table = ?');
            $upd->execute([$n, $arch]);
            if ($upd->rowCount() === 0) {
                $this->db->prepare('INSERT INTO ' . self::q($archive) . '._aic_archive (seq, source_schema, source_table, archive_table, scope, target, row_count, mode, key_columns) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$seq, $schema, $name, $arch, $scope, $target, $n, 'replace', implode(',', $pk)]);
            }
            $own ? $this->db->commit() : $this->db->exec('RELEASE SAVEPOINT aic_archive_rows');
        } catch (\Throwable $e) {
            if ($own) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } else {
                $this->db->exec('ROLLBACK TO SAVEPOINT aic_archive_rows');
            }
            throw $e;
        }

        return $n;
    }

    /**
     * Put an archive back, parents first. Dry run unless $apply.
     *
     * @return array{ok:bool, error:?string, tables:array<string,int>, applied:bool}
     */
    public function restore(string $archive, bool $apply = false): array
    {
        $out = ['ok' => false, 'error' => null, 'tables' => [], 'applied' => false];
        if (preg_match(self::ARCHIVE_RE, $archive) !== 1) {
            $out['error'] = 'Not a lifecycle archive schema name.';

            return $out;
        }
        try {
            $rows = $this->db->query('SELECT * FROM ' . self::q($archive) . '._aic_archive ORDER BY seq DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $out['error'] = 'Archive ' . $archive . ' is missing or has no _aic_archive index.';

            return $out;
        }
        foreach ($rows as $r) {
            $key = $r['source_schema'] . '.' . $r['source_table'] . (($r['mode'] ?? 'purge') === 'replace' ? ' (hook)' : '');
            $out['tables'][$key] = ($out['tables'][$key] ?? 0) + (int) $r['row_count'];
        }
        if (!$apply) {
            $out['ok'] = true;

            return $out;
        }
        try {
            $this->db->beginTransaction();
            $this->db->exec('SET CONSTRAINTS ALL DEFERRED');
            $relinks = [];
            foreach ($rows as $r) {
                $cols = $this->db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND is_generated = 'NEVER'
                    AND column_name IN (SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?) ORDER BY ordinal_position");
                $cols->execute([$r['source_schema'], $r['source_table'], $archive, $r['archive_table']]);
                $names = $cols->fetchAll(PDO::FETCH_COLUMN);
                $list = implode(', ', array_map([self::class, 'q'], $names));
                $src = self::q($r['source_schema']) . '.' . self::q($r['source_table']);
                $arch = self::q($archive) . '.' . self::q($r['archive_table']);
                $keys = array_values(array_filter(explode(',', (string) ($r['key_columns'] ?? ''))));
                if (($r['mode'] ?? 'purge') === 'replace') {
                    $always = $this->identityAlways($r['source_schema'] . '.' . $r['source_table']);
                    $set = array_map(static fn (string $c): string => self::q($c) . ' = EXCLUDED.' . self::q($c),
                        array_values(array_filter($names, static fn (string $c): bool => !in_array($c, $keys, true) && !in_array($c, $always, true))));
                    $sql = 'INSERT INTO ' . $src . " ({$list}) OVERRIDING SYSTEM VALUE SELECT {$list} FROM " . $arch
                        . ' ON CONFLICT (' . implode(', ', array_map([self::class, 'q'], $keys)) . ') DO ' . ($set === [] ? 'NOTHING' : 'UPDATE SET ' . implode(', ', $set));
                } else {
                    $broken = array_values(array_filter(explode(',', (string) ($r['relink_column'] ?? ''))));
                    $select = implode(', ', array_map(static fn (string $c): string => in_array($c, $broken, true) ? 'NULL' : self::q($c), $names));
                    $sql = 'INSERT INTO ' . $src . " ({$list}) OVERRIDING SYSTEM VALUE SELECT {$select} FROM " . $arch;
                    if ($broken !== []) {
                        $relinks[] = [$src, $arch, $broken, $keys];
                    }
                }
                $ins = $this->db->exec($sql);
                if ((int) $ins !== (int) $r['row_count']) {
                    throw new \RuntimeException("{$r['source_table']}: {$ins} of {$r['row_count']} row(s) restored");
                }
            }
            foreach ($relinks as [$src, $arch, $broken, $keys]) {
                $this->db->exec('UPDATE ' . $src . ' t SET ' . implode(', ', array_map(static fn (string $c): string => self::q($c) . ' = a.' . self::q($c), $broken))
                    . ' FROM ' . $arch . ' a WHERE ' . implode(' AND ', array_map(static fn (string $k): string => 't.' . self::q($k) . ' = a.' . self::q($k), $keys)));
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $out['error'] = 'Rolled back, nothing was restored: ' . preg_replace('/\s+/', ' ', $e->getMessage());

            return $out;
        }
        $out['ok'] = true;
        $out['applied'] = true;

        return $out;
    }

    /** @return list<array{schema:string, scope:string, target:string, created_at:string}> */
    public function archives(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT nspname FROM pg_namespace WHERE nspname LIKE 'aic\\_lifecycle\\_archive\\_%' ORDER BY nspname")->fetchAll(PDO::FETCH_COLUMN) as $s) {
            if (preg_match(self::ARCHIVE_RE, (string) $s, $m) === 1) {
                $ts = \DateTimeImmutable::createFromFormat('YmdHis', $m[3], new \DateTimeZone('UTC'));
                $out[] = ['schema' => (string) $s, 'scope' => $m[1] === 'c' ? 'company' : 'user', 'target' => $m[2],
                    'created_at' => $ts !== false ? $ts->format('Y-m-d\TH:i:s\Z') : ''];
            }
        }

        return $out;
    }

    public function dropArchive(string $archive): bool
    {
        if (preg_match(self::ARCHIVE_RE, $archive) !== 1) {
            return false;
        }
        $this->db->exec('DROP SCHEMA IF EXISTS ' . self::q($archive) . ' CASCADE');

        return true;
    }

    /** @return list<string> why this scope may not be purged on this database (empty = fine) */
    public function problems(string $scope): array
    {
        $problems = [];
        $schemas = [$this->manifest->schema() => true];
        foreach ($this->manifest->listed() as $q => $_) {
            $schemas[explode('.', $q, 2)[0]] = true;
        }
        foreach ($this->manifest->tables($scope) as $e) {
            foreach ($e['referenced_by'] ?? [] as $r) {
                $schemas[explode('.', $r['table'], 2)[0]] = true;
            }
        }
        $columns = $this->columns(array_keys($schemas));
        $this->cols = $columns;
        $this->fks = null;
        $has = static fn (string $q, string $col): bool => isset($columns[$q][$col]);

        $purge = $this->manifest->tables($scope);
        $retain = $this->manifest->retained($scope);
        foreach (array_merge($purge, $retain) as $e) {
            $q = $e['qualified'];
            if (!isset($columns[$q])) {
                $problems[] = "{$q} does not exist.";
                continue;
            }
            foreach (array_filter(array_merge($e['keys'] ?? [], [$e['only_if_null'] ?? null, $e['via']['column'] ?? null])) as $col) {
                if (!$has($q, $col)) {
                    $problems[] = "{$q} has no column {$col}.";
                }
            }
            if (isset($e['via']) && !$has($e['via']['parent'], $e['via']['parent_column'])) {
                $problems[] = "{$e['via']['parent']} has no column {$e['via']['parent_column']}.";
            }
            if (isset($e['retain_from'])) {
                if (!$has($q, $e['retain_from'])) {
                    $problems[] = "{$q} has no column {$e['retain_from']} (retain_from).";
                } elseif (!in_array($columns[$q][$e['retain_from']]['udt'], self::DATE_TYPES, true)) {
                    $problems[] = "{$q}.{$e['retain_from']} (retain_from) must be a date or timestamp column.";
                }
            }
        }
        foreach ($purge as $e) {
            if (isset($e['retain_where']) && !$this->predicateWorks($e)) {
                $problems[] = "{$e['qualified']}: retain_where does not run on this table: " . $e['retain_where'];
            }
        }
        if ($problems !== []) {
            return array_values(array_unique($problems));
        }

        if ($this->manifest->strict()) {
            // Listed for THIS scope (purged or retained) or ignored: a table only in user_tables that
            // also carries cmp_id would otherwise keep the company's rows on a company purge.
            $listed = [];
            foreach (array_merge($purge, $retain, $this->manifest->ignored()) as $e) {
                $listed[$e['qualified']] = true;
            }
            $keys = $this->manifest->keyColumns($scope);
            foreach ($columns as $q => $cols) {
                if (isset($listed[$q]) || str_contains($q, '.aic_lifecycle_')) {
                    continue;
                }
                $hit = array_values(array_filter($keys, static fn (string $k): bool => isset($cols[$k])));
                if ($hit !== []) {
                    $problems[] = "{$q} has " . implode(', ', $hit) . ' but is not in the manifest (added after the review?): list it in '
                        . "{$scope}_tables, {$scope}_retain or ignore.";
                }
            }
        }

        // retain_referenced: every referencing table, and a reference to follow.
        foreach ($purge as $e) {
            if (!self::isRetainReferenced($e)) {
                continue;
            }
            foreach ($e['referenced_by'] as $r) {
                if (!isset($columns[$r['table']])) {
                    $problems[] = "{$e['qualified']}: referenced_by {$r['table']} does not exist.";
                    continue;
                }
                if ($r['column'] === null) {
                    if ($this->references($e, $r) === []) {
                        $problems[] = "{$e['qualified']}: {$r['table']} has no foreign key into it — name the reference: {table, column, parent_column}.";
                    }
                    continue;
                }
                if (!$has($r['table'], $r['column'])) {
                    $problems[] = "{$r['table']} has no column {$r['column']} (referenced_by of {$e['qualified']}).";
                }
                $pc = $r['parent_column'] ?? (count($pk = $this->primaryKey($e['qualified'])) === 1 ? $pk[0] : null);
                if ($pc === null || !$has($e['qualified'], $pc)) {
                    $problems[] = "{$e['qualified']}: referenced_by {$r['table']}.{$r['column']} needs parent_column (no single-column primary key).";
                }
            }
        }

        // scrub rules for this scope's tables.
        foreach (array_merge($purge, $retain) as $e) {
            foreach ($this->manifest->scrub($e['qualified']) as $col => $rule) {
                $m = $columns[$e['qualified']][$col] ?? null;
                if ($m === null) {
                    $problems[] = "scrub: {$e['qualified']} has no column {$col}.";
                    continue;
                }
                $textual = in_array($m['udt'], self::TEXT_TYPES, true) || in_array($m['udt'], ['json', 'jsonb'], true);
                if ($rule['kind'] === 'json_paths' && !$textual) {
                    $problems[] = "scrub: {$e['qualified']}.{$col} is {$m['udt']}; json_paths needs a json, jsonb or text column.";
                } elseif (!$m['nullable'] && ($rule['kind'] === 'null' || ($rule['kind'] === 'redact' && !$textual))) {
                    $problems[] = "scrub: {$e['qualified']}.{$col} is NOT NULL and cannot be set to NULL — use redact on a text column, or json_paths.";
                }
            }
        }

        // break_cycle for this scope's purged tables.
        $viaCols = [];
        foreach ($purge as $e) {
            if (isset($e['via'])) {
                $viaCols[$e['qualified']][$e['via']['column']] = true;
                $viaCols[$e['via']['parent']][$e['via']['parent_column']] = true;
            }
        }
        foreach ($purge as $e) {
            foreach ($this->manifest->breakCycle($e['qualified']) as $col) {
                $m = $columns[$e['qualified']][$col] ?? null;
                if ($m === null) {
                    $problems[] = "break_cycle: {$e['qualified']} has no column {$col}.";
                } elseif (!$m['nullable']) {
                    $problems[] = "break_cycle: {$e['qualified']}.{$col} is NOT NULL, so it cannot be unlinked.";
                } elseif (isset($viaCols[$e['qualified']][$col])) {
                    $problems[] = "break_cycle: {$e['qualified']}.{$col} is used by a via entry; unlinking it would lose the rows it selects.";
                } elseif ($this->primaryKey($e['qualified']) === []) {
                    $problems[] = "break_cycle: {$e['qualified']} needs a primary key so a restore can put {$col} back.";
                }
            }
        }

        $pos = [];
        foreach ($purge as $i => $e) {
            $pos[$e['qualified']] = $i;
        }
        $byQ = [];
        foreach ($purge as $e) {
            $byQ[$e['qualified']] = $e;
        }
        $expirable = [];
        foreach ($retain as $i => $e) {
            if (self::hasPeriod($e)) {
                $expirable[$e['qualified']] = $i;
            }
        }
        $defer = false;
        foreach ($this->foreignKeys() as $fk) {
            [$child, $parent, $action, $deferrable, $childCols] = $fk;
            if ($child === $parent) {
                continue;
            }
            if (isset($pos[$parent])) {
                if (isset($pos[$child])) {
                    if ($pos[$child] > $pos[$parent]) {
                        if (array_intersect($childCols, $this->manifest->breakCycle($child)) !== []) {
                            continue;
                        }
                        if ($deferrable) {
                            $defer = true;
                            continue;
                        }
                        $problems[] = "{$child} references {$parent} but is listed after it: children are deleted first.";
                    }
                } elseif (in_array($action, ['c', 'n', 'd'], true) && !$this->keptReference($byQ[$parent], $child, $childCols)) {
                    $problems[] = "{$child} has a foreign key into {$parent} with ON DELETE " . ['c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'][$action]
                        . ": purging would change its rows without archiving them. List it in {$scope}_tables before {$parent}.";
                }
            }
            if (isset($expirable[$parent])) {
                if (isset($expirable[$child])) {
                    if ($expirable[$child] > $expirable[$parent] && !$deferrable) {
                        $problems[] = "{$child} references {$parent}: among the {$scope}_retain tables with a retention period, list the child first (expiry deletes in that order).";
                    }
                } elseif (!isset($pos[$child]) && in_array($action, ['c', 'n', 'd'], true)) {
                    $problems[] = "{$child} has a foreign key into {$parent} with ON DELETE " . ['c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'][$action]
                        . ": expiring {$parent} (retention period) would change its rows without archiving them.";
                }
            }
        }
        $this->defer[$scope] = $defer;

        return array_values(array_unique($problems));
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Run $fn in a transaction (or savepoint) that is always rolled back. */
    private function scratch(callable $fn): void
    {
        $own = !$this->db->inTransaction();
        $own ? $this->db->beginTransaction() : $this->db->exec('SAVEPOINT aic_plan');
        try {
            $fn();
        } finally {
            if ($own) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } else {
                $this->db->exec('ROLLBACK TO SAVEPOINT aic_plan');
            }
        }
    }

    /** Inside the purge transaction: deferral, the scrub functions, the keep set. */
    private function prepareTransaction(string $scope, string $value, bool $mayDefer): void
    {
        if ($mayDefer && ($this->defer[$scope] ?? false)) {
            $this->db->exec('SET CONSTRAINTS ALL DEFERRED');
        }
        if ($this->usesJsonScrub()) {
            $this->scrubFunctions();
        }
        if ($this->needsKeep($scope)) {
            $this->computeKeep($scope, $value);
        }
    }

    /** archiveName() at $ts, or the next second free (an expiry in the same second as a purge). */
    private function freeArchiveName(string $scope, string $value, int $ts): string
    {
        $stmt = $this->db->prepare('SELECT 1 FROM pg_namespace WHERE nspname = ?');
        for ($i = 0; $i < 60; $i++) {
            $name = self::archiveName($scope, $value, $ts + $i);
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() === false) {
                return $name;
            }
        }

        return self::archiveName($scope, $value, $ts + 60);
    }

    private function createArchive(string $archive, bool $ifNotExists): void
    {
        $ine = $ifNotExists ? 'IF NOT EXISTS ' : '';
        $this->db->exec('CREATE SCHEMA ' . $ine . self::q($archive));
        $this->db->exec('CREATE TABLE ' . $ine . self::q($archive) . '._aic_archive (seq INTEGER NOT NULL, source_schema TEXT NOT NULL, source_table TEXT NOT NULL,
                archive_table TEXT NOT NULL, scope TEXT NOT NULL, target TEXT NOT NULL, row_count BIGINT NOT NULL, archived_at TIMESTAMPTZ NOT NULL DEFAULT now())');
    }

    /** 1.1 index columns, added only to archives that need them (hook rows, unlinked cycles). */
    private function indexColumns(string $archive): void
    {
        $this->db->exec('ALTER TABLE ' . self::q($archive) . "._aic_archive ADD COLUMN IF NOT EXISTS mode TEXT NOT NULL DEFAULT 'purge',
            ADD COLUMN IF NOT EXISTS key_columns TEXT NULL, ADD COLUMN IF NOT EXISTS relink_column TEXT NULL");
    }

    /**
     * Copy the rows $where selects into the archive (scrubbed), delete them, check the counts, index
     * them. $preArchived: the rows were copied already (break_cycle). Returns rows moved.
     *
     * @param array<string,mixed> $e
     */
    private function moveRows(string $archive, array $e, string $where, string $value, int $seq, string $scope, ?int $preArchived, string $prefix = ''): int
    {
        $src = self::q($e['schema']) . '.' . self::q($e['table']);
        $params = self::params($where, $value);
        $arch = substr($prefix . $this->archiveTable($e), 0, 63);
        $dst = self::q($archive) . '.' . self::q($arch);
        if ($preArchived === null) {
            $count = $this->db->prepare('SELECT COUNT(*) FROM ' . $src . ' WHERE ' . $where);
            $count->execute($params);
            if ((int) $count->fetchColumn() === 0) {
                return 0;
            }
            $this->db->exec('CREATE TABLE ' . $dst . ' AS SELECT * FROM ' . $src . ' WITH NO DATA');
            $copy = $this->db->prepare('INSERT INTO ' . $dst . ' SELECT * FROM ' . $src . ' WHERE ' . $where);
            $copy->execute($params);
            $archived = $copy->rowCount();
            $this->scrubArchive($dst, $e['qualified']);
        } else {
            $archived = $preArchived;
        }
        $del = $this->db->prepare('DELETE FROM ' . $src . ' WHERE ' . $where);
        $del->execute($params);
        $deleted = $del->rowCount();
        if ($deleted !== $archived) {
            throw new \RuntimeException("{$e['qualified']}: {$archived} row(s) archived but {$deleted} deleted (written to concurrently?)");
        }
        $broken = $preArchived !== null ? $this->manifest->breakCycle($e['qualified']) : [];
        if ($broken === []) {
            $this->db->prepare('INSERT INTO ' . self::q($archive) . '._aic_archive (seq, source_schema, source_table, archive_table, scope, target, row_count) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$seq, $e['schema'], $e['table'], $arch, $scope, $value, $archived]);
        } else {
            $this->indexColumns($archive);
            $this->db->prepare('INSERT INTO ' . self::q($archive) . '._aic_archive (seq, source_schema, source_table, archive_table, scope, target, row_count, mode, key_columns, relink_column) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$seq, $e['schema'], $e['table'], $arch, $scope, $value, $archived, 'purge', implode(',', $this->primaryKey($e['qualified'])), implode(',', $broken)]);
        }

        return $archived;
    }

    /**
     * break_cycle: archive the purged rows of each unlinked table first, then set the cycle's column
     * to NULL on them, so the delete order no longer matters. Returns rows archived per table.
     *
     * @return array<string,int>
     */
    private function breakLinks(string $archive, string $scope, string $value): array
    {
        $pre = [];
        foreach ($this->manifest->tables($scope) as $e) {
            $cols = $this->manifest->breakCycle($e['qualified']);
            if ($cols === []) {
                continue;
            }
            $where = $this->where($e, $scope, 0, null, true);
            $params = self::params($where, $value);
            $src = self::q($e['schema']) . '.' . self::q($e['table']);
            $count = $this->db->prepare('SELECT COUNT(*) FROM ' . $src . ' WHERE ' . $where);
            $count->execute($params);
            if ((int) $count->fetchColumn() === 0) {
                continue;
            }
            $dst = self::q($archive) . '.' . self::q($this->archiveTable($e));
            $this->db->exec('CREATE TABLE ' . $dst . ' AS SELECT * FROM ' . $src . ' WITH NO DATA');
            $copy = $this->db->prepare('INSERT INTO ' . $dst . ' SELECT * FROM ' . $src . ' WHERE ' . $where);
            $copy->execute($params);
            $pre[$e['qualified']] = $copy->rowCount();
            $this->scrubArchive($dst, $e['qualified']);
            $this->db->prepare('UPDATE ' . $src . ' SET ' . implode(', ', array_map(static fn (string $c): string => self::q($c) . ' = NULL', $cols)) . ' WHERE ' . $where)
                ->execute($params);
        }

        return $pre;
    }

    /** @param array<string,mixed> $e */
    private function readBack(string $archive, array $e, string $scope, string $value, int $expected): ?string
    {
        $live = $this->count($e, $scope, $value, true);
        $kept = (int) $this->db->query('SELECT COUNT(*) FROM ' . self::q($archive) . '.' . self::q($this->archiveTable($e)))->fetchColumn();

        return $live !== 0 || $kept !== $expected ? "{$e['qualified']}: {$live} still live, {$kept} of {$expected} archived" : null;
    }

    /**
     * The rows each retain_referenced table keeps: those a row that stays still references —
     * directly, or through another retain_referenced table's kept rows (one recursive query; UNION
     * makes cycles terminate). Into pg_temp.aic_lifecycle_keep (dropped at commit).
     */
    private function computeKeep(string $scope, string $value): void
    {
        $this->db->exec('CREATE TEMP TABLE IF NOT EXISTS aic_lifecycle_keep (tbl TEXT NOT NULL, tid TID NOT NULL) ON COMMIT DROP');
        $this->db->exec('TRUNCATE ' . self::KEEP);
        $seeds = [];
        $steps = [];
        foreach ($this->manifest->tables($scope) as $t) {
            if (!self::isRetainReferenced($t)) {
                continue;
            }
            $tq = self::qq($t['qualified']);
            $tWhere = $this->where($t, $scope, 0, 't');
            foreach ($t['referenced_by'] as $r) {
                $s = $this->entry($scope, $r['table']);
                $sq = self::qq($r['table']);
                foreach ($this->references($t, $r) as [$childCols, $parentCols]) {
                    $join = implode(' AND ', array_map(static fn (string $c, string $p): string => 's.' . self::q($c) . ' = t.' . self::q($p), $childCols, $parentCols));
                    $survives = $s !== null ? '((' . $this->where($s, $scope, 0, 's') . ') IS NOT TRUE' . (isset($s['retain_where']) ? ' OR (' . $s['retain_where'] . ')' : '') . ')' : 'TRUE';
                    $seeds[] = 'SELECT CAST(' . self::lit($t['qualified']) . " AS TEXT) AS tbl, t.ctid AS tid FROM {$tq} t WHERE ({$tWhere}) AND EXISTS (SELECT 1 FROM {$sq} s WHERE {$join} AND {$survives})";
                    if ($s !== null && self::isRetainReferenced($s)) {
                        $steps[] = 'SELECT CAST(' . self::lit($t['qualified']) . " AS TEXT), t.ctid FROM {$sq} s JOIN {$tq} t ON {$join} WHERE k.tbl = " . self::lit($s['qualified'])
                            . " AND s.ctid = k.tid AND ({$tWhere})";
                    }
                }
            }
        }
        if ($seeds === []) {
            return;
        }
        $sql = 'WITH RECURSIVE keep(tbl, tid) AS (' . implode(' UNION ', $seeds)
            . ($steps !== [] ? ' UNION SELECT x.tbl, x.tid FROM keep k CROSS JOIN LATERAL (' . implode(' UNION ALL ', $steps) . ') x(tbl, tid)' : '')
            . ') INSERT INTO ' . self::KEEP . ' (tbl, tid) SELECT tbl, tid FROM keep ORDER BY tbl, tid';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(self::params($sql, $value));
    }

    /**
     * The column pairs a referenced_by entry follows into retain_referenced table $t.
     *
     * @param array<string,mixed> $t
     * @param array{table:string, column:?string, parent_column:?string} $r
     * @return list<array{0:list<string>, 1:list<string>}>
     */
    private function references(array $t, array $r): array
    {
        if ($r['column'] !== null) {
            $pc = $r['parent_column'] ?? (count($pk = $this->primaryKey($t['qualified'])) === 1 ? $pk[0] : null);

            return $pc !== null ? [[[$r['column']], [$pc]]] : [];
        }
        $out = [];
        foreach ($this->foreignKeys() as [$child, $parent, , , $childCols, $parentCols]) {
            if ($child === $r['table'] && $parent === $t['qualified']) {
                $out[] = [$childCols, $parentCols];
            }
        }

        return $out;
    }

    /**
     * A cascading foreign key from $child into retain_referenced $parent that is one of the references
     * that keep its rows: a row it points at is never deleted, so the cascade never fires.
     *
     * @param array<string,mixed> $parent
     * @param list<string> $childCols
     */
    private function keptReference(array $parent, string $child, array $childCols): bool
    {
        if (!self::isRetainReferenced($parent)) {
            return false;
        }
        foreach ($parent['referenced_by'] as $r) {
            if ($r['table'] === $child && ($r['column'] === null || [$r['column']] === $childCols)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $e */
    private static function isRetainReferenced(array $e): bool
    {
        return ($e['mode'] ?? 'purge') === 'retain_referenced';
    }

    private function needsKeep(string $scope): bool
    {
        foreach ($this->manifest->tables($scope) as $e) {
            if (self::isRetainReferenced($e)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $e a retain_referenced table, or a via child of one */
    private function keepDependent(array $e, string $scope, int $depth = 0): bool
    {
        if (self::isRetainReferenced($e)) {
            return true;
        }
        if (!isset($e['via']) || $depth > 8) {
            return false;
        }
        if (!empty($e['via']['include_kept'])) {
            return false;
        }
        $parent = $this->entry($scope, $e['via']['parent']);

        return $parent !== null && $this->keepDependent($parent, $scope, $depth + 1);
    }

    /** @return array<string,mixed>|null this scope's purge entry for "schema.table" */
    private function entry(string $scope, string $qualified): ?array
    {
        foreach ($this->manifest->tables($scope) as $p) {
            if ($p['qualified'] === $qualified) {
                return $p;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null this scope's retained entry for "schema.table" */
    private function retainedEntry(string $scope, string $qualified): ?array
    {
        foreach ($this->manifest->retained($scope) as $p) {
            if ($p['qualified'] === $qualified) {
                return $p;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $e */
    private function keptPredicate(array $e, ?string $alias = null): string
    {
        return ($alias !== null ? self::q($alias) . '.' : '') . 'ctid IN (SELECT k.tid FROM ' . self::KEEP . ' k WHERE k.tbl = ' . self::lit($e['qualified']) . ')';
    }

    /** @param array<string,mixed> $e a retained entry with retain_years */
    private function expiredPredicate(array $e, int $now): string
    {
        $col = self::q((string) $e['retain_from']);
        $interval = isset($e['retain_years']) ? 'make_interval(years => ' . (int) $e['retain_years'] . ')' : 'make_interval(days => ' . (int) $e['retain_days'] . ')';
        if (($this->cols[$e['qualified']][$e['retain_from']]['udt'] ?? '') === 'timestamptz') {
            return "({$col} + {$interval}) <= TIMESTAMPTZ '" . gmdate('Y-m-d H:i:s', $now) . "+00'";
        }

        // date / timestamp without time zone: read as UTC, whatever the session's time zone.
        return "(CAST({$col} AS TIMESTAMP) + {$interval}) <= TIMESTAMP '" . gmdate('Y-m-d H:i:s', $now) . "'";
    }

    /** @param array<string,mixed> $e */
    private static function hasPeriod(array $e): bool
    {
        return isset($e['retain_from']) && (isset($e['retain_years']) || isset($e['retain_days']));
    }

    /** @param array<string,mixed> $e */
    private function predicateWorks(array $e): bool
    {
        $own = !$this->db->inTransaction();
        $own ? $this->db->beginTransaction() : $this->db->exec('SAVEPOINT aic_check');
        try {
            $this->db->query('SELECT 1 FROM ' . self::q($e['schema']) . '.' . self::q($e['table']) . ' WHERE (' . $e['retain_where'] . ') LIMIT 0')->fetchAll();
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }
        $own ? $this->db->rollBack() : $this->db->exec('ROLLBACK TO SAVEPOINT aic_check');

        return $ok;
    }

    // ── scrub ────────────────────────────────────────────────────────────────

    private function usesJsonScrub(): bool
    {
        foreach ($this->manifest->scrubRules() as $cols) {
            foreach ($cols as $rule) {
                if ($rule['kind'] === 'json_paths') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Rows scrubbed in place at purge time: the target's rows in retained tables and the rows
     * retain_referenced tables keep. [entry, filter] pairs, for tables with scrub rules.
     *
     * @return list<array{0:array<string,mixed>, 1:string}>
     */
    private function scrubTargets(string $scope): array
    {
        $out = [];
        foreach ($this->manifest->tables($scope) as $e) {
            if (self::isRetainReferenced($e) && $this->manifest->scrub($e['qualified']) !== []) {
                $out[] = [$e, '(' . $this->where($e, $scope) . ') AND ' . $this->keptPredicate($e)];
            }
            if (isset($e['retain_where']) && $this->manifest->scrub($e['qualified']) !== []) {
                $out[] = [$e, '(' . $this->where($e, $scope) . ') AND (' . $e['retain_where'] . ')'];
            }
        }
        foreach ($this->manifest->retained($scope) as $e) {
            if ($this->manifest->scrub($e['qualified']) !== []) {
                $out[] = [$e, '(' . $this->where($e, $scope) . ')'];
            }
        }

        return $out;
    }

    /** @return array<string,int> rows scrubbed per table */
    private function scrubRetained(string $scope, string $value): array
    {
        $done = [];
        foreach ($this->scrubTargets($scope) as [$e, $where]) {
            $where .= ' AND (' . $this->scrubDiff($e['qualified']) . ')';
            $stmt = $this->db->prepare('UPDATE ' . self::q($e['schema']) . '.' . self::q($e['table']) . ' SET ' . $this->scrubSet($e['qualified']) . ' WHERE ' . $where);
            $stmt->execute(self::params($where, $value));
            if ($stmt->rowCount() > 0) {
                $done[$e['qualified']] = $stmt->rowCount();
            }
        }

        return $done;
    }

    /** Scrub a fresh archive copy (every row in it). */
    private function scrubArchive(string $dst, string $qualified): void
    {
        if ($this->manifest->scrub($qualified) === []) {
            return;
        }
        $this->db->exec('UPDATE ' . $dst . ' SET ' . $this->scrubSet($qualified) . ' WHERE ' . $this->scrubDiff($qualified));
    }

    private function scrubSet(string $qualified): string
    {
        $set = [];
        foreach ($this->manifest->scrub($qualified) as $col => $rule) {
            $set[] = self::q($col) . ' = ' . $this->scrubExpr($qualified, $col, $rule);
        }

        return implode(', ', $set);
    }

    /** True for a row whose scrubbed columns still differ from their scrubbed value. */
    private function scrubDiff(string $qualified): string
    {
        $diff = [];
        foreach ($this->manifest->scrub($qualified) as $col => $rule) {
            $c = self::q($col);
            $expr = $this->scrubExpr($qualified, $col, $rule);
            $diff[] = ($this->cols[$qualified][$col]['udt'] ?? '') === 'json'
                ? "CAST({$c} AS JSONB) IS DISTINCT FROM CAST({$expr} AS JSONB)"
                : "{$c} IS DISTINCT FROM {$expr}";
        }

        return implode(' OR ', $diff);
    }

    /** @param array{kind:string, paths:list<string>} $rule */
    private function scrubExpr(string $qualified, string $col, array $rule): string
    {
        $udt = $this->cols[$qualified][$col]['udt'] ?? 'text';
        $c = self::q($col);
        if ($rule['kind'] === 'null') {
            return 'NULL';
        }
        if ($rule['kind'] === 'redact') {
            return match (true) {
                in_array($udt, self::TEXT_TYPES, true) => self::lit(self::REDACTED),
                $udt === 'json'                         => "CAST('\"" . self::REDACTED . "\"' AS JSON)",
                $udt === 'jsonb'                        => "CAST('\"" . self::REDACTED . "\"' AS JSONB)",
                default                                 => 'NULL',
            };
        }
        $paths = 'ARRAY[' . implode(', ', array_map([self::class, 'lit'], $rule['paths'])) . ']::TEXT[]';

        return match ($udt) {
            'jsonb' => "pg_temp.aic_scrub_json({$c}, {$paths})",
            'json'  => "CAST(pg_temp.aic_scrub_json(CAST({$c} AS JSONB), {$paths}) AS JSON)",
            default => "pg_temp.aic_scrub_json_text(CAST({$c} AS TEXT), {$paths})",
        };
    }

    /** Session-local helpers for json_paths (pg_temp: nothing is installed in the product's schema). */
    private function scrubFunctions(): void
    {
        $r = self::lit('"' . self::REDACTED . '"');
        $this->db->exec(<<<SQL
            CREATE OR REPLACE FUNCTION pg_temp.aic_scrub_path(doc JSONB, path TEXT[]) RETURNS JSONB LANGUAGE plpgsql IMMUTABLE AS \$f\$
            DECLARE head TEXT; rest TEXT[]; k TEXT; i INTEGER; out JSONB;
            BEGIN
                IF doc IS NULL OR cardinality(path) = 0 THEN RETURN doc; END IF;
                head := path[1];
                rest := path[2:cardinality(path)];
                IF jsonb_typeof(doc) = 'object' THEN
                    out := doc;
                    FOR k IN SELECT jsonb_object_keys(doc) LOOP
                        IF head = '*' OR head = k THEN
                            out := jsonb_set(out, ARRAY[k], CASE WHEN cardinality(rest) = 0 THEN CAST({$r} AS JSONB) ELSE pg_temp.aic_scrub_path(doc -> k, rest) END);
                        END IF;
                    END LOOP;
                    RETURN out;
                ELSIF jsonb_typeof(doc) = 'array' THEN
                    out := doc;
                    FOR i IN 0 .. jsonb_array_length(doc) - 1 LOOP
                        IF head = '*' OR head = CAST(i AS TEXT) THEN
                            out := jsonb_set(out, ARRAY[CAST(i AS TEXT)], CASE WHEN cardinality(rest) = 0 THEN CAST({$r} AS JSONB) ELSE pg_temp.aic_scrub_path(doc -> i, rest) END);
                        END IF;
                    END LOOP;
                    RETURN out;
                END IF;
                RETURN doc;
            END \$f\$
            SQL);
        $this->db->exec(<<<'SQL'
            CREATE OR REPLACE FUNCTION pg_temp.aic_scrub_json(doc JSONB, paths TEXT[]) RETURNS JSONB LANGUAGE plpgsql IMMUTABLE AS $f$
            DECLARE p TEXT;
            BEGIN
                IF doc IS NULL THEN RETURN NULL; END IF;
                FOREACH p IN ARRAY paths LOOP
                    doc := pg_temp.aic_scrub_path(doc, string_to_array(p, '.'));
                END LOOP;
                RETURN doc;
            END $f$
            SQL);
        $this->db->exec(<<<SQL
            CREATE OR REPLACE FUNCTION pg_temp.aic_scrub_json_text(doc TEXT, paths TEXT[]) RETURNS TEXT LANGUAGE plpgsql IMMUTABLE AS \$f\$
            DECLARE j JSONB;
            BEGIN
                IF doc IS NULL THEN RETURN NULL; END IF;
                BEGIN
                    j := CAST(doc AS JSONB);
                EXCEPTION WHEN others THEN
                    RETURN {$this->litSql(self::REDACTED)};
                END;
                RETURN CAST(pg_temp.aic_scrub_json(j, paths) AS TEXT);
            END \$f\$
            SQL);
    }

    private function litSql(string $s): string
    {
        return self::lit($s);
    }

    // ── catalog ──────────────────────────────────────────────────────────────

    /**
     * @param list<string> $schemas
     * @return array<string, array<string, array{type:string, udt:string, nullable:bool}>> "schema.table" → columns (base tables only)
     */
    private function columns(array $schemas): array
    {
        $in = implode(', ', array_fill(0, count($schemas), '?'));
        $stmt = $this->db->prepare("
            SELECT c.table_schema, c.table_name, c.column_name, c.data_type, c.udt_name, c.is_nullable
              FROM information_schema.columns c
              JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
             WHERE c.table_schema IN ({$in})
        ");
        $stmt->execute($schemas);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['table_schema'] . '.' . $r['table_name']][$r['column_name']] = [
                'type' => (string) $r['data_type'], 'udt' => (string) $r['udt_name'], 'nullable' => $r['is_nullable'] === 'YES',
            ];
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string,3:bool,4:list<string>,5:list<string>}> [child, parent, on-delete action code, deferrable, child columns, parent columns] */
    private function foreignKeys(): array
    {
        if ($this->fks !== null) {
            return $this->fks;
        }
        $rows = $this->db->query("
            SELECT cn.nspname || '.' || c.relname AS child, pn.nspname || '.' || p.relname AS parent, con.confdeltype AS action, con.condeferrable AS deferrable,
                   (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(con.conkey) WITH ORDINALITY k(attnum, ord)
                      JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum) AS child_cols,
                   (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(con.confkey) WITH ORDINALITY k(attnum, ord)
                      JOIN pg_attribute a ON a.attrelid = con.confrelid AND a.attnum = k.attnum) AS parent_cols
              FROM pg_constraint con
              JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace cn ON cn.oid = c.relnamespace
              JOIN pg_class p ON p.oid = con.confrelid JOIN pg_namespace pn ON pn.oid = p.relnamespace
             WHERE con.contype = 'f'
             ORDER BY 1, 2, con.conname
        ")->fetchAll(PDO::FETCH_ASSOC);

        return $this->fks = array_map(static fn (array $r): array => [
            (string) $r['child'], (string) $r['parent'], (string) $r['action'], $r['deferrable'] === true || $r['deferrable'] === 't' || $r['deferrable'] === 1 || $r['deferrable'] === '1',
            explode(',', (string) $r['child_cols']), explode(',', (string) $r['parent_cols']),
        ], $rows);
    }

    /** @return list<string> primary-key columns of "schema.table", in key order */
    private function primaryKey(string $qualified): array
    {
        [$s, $t] = explode('.', $qualified, 2);
        $stmt = $this->db->prepare("
            SELECT a.attname FROM pg_index i
              JOIN pg_class c ON c.oid = i.indrelid JOIN pg_namespace n ON n.oid = c.relnamespace
              JOIN unnest(i.indkey) WITH ORDINALITY k(attnum, ord) ON true
              JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
             WHERE i.indisprimary AND n.nspname = ? AND c.relname = ?
             ORDER BY k.ord
        ");
        $stmt->execute([$s, $t]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> GENERATED ALWAYS identity columns (never in an UPDATE SET) */
    private function identityAlways(string $qualified): array
    {
        [$s, $t] = explode('.', $qualified, 2);
        $stmt = $this->db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND identity_generation = 'ALWAYS'");
        $stmt->execute([$s, $t]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $e */
    private function count(array $e, string $scope, string $value, bool $purgeOnly = false): int
    {
        return $this->countWhere($e, $this->where($e, $scope, 0, null, $purgeOnly), $value);
    }

    /** @param array<string,mixed> $e */
    private function countWhere(array $e, string $where, string $value): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . self::q($e['schema']) . '.' . self::q($e['table']) . ' WHERE ' . $where);
        $stmt->execute(self::params($where, $value));

        return (int) $stmt->fetchColumn();
    }

    /**
     * The row filter for one manifest entry; every "?" is the company or person. A via entry nests its
     * parent's filter; key_columns match any of them. $alias qualifies the columns; $purgeOnly leaves
     * out the rows retain_referenced tables keep (and, through via, their children's rows).
     *
     * @param array<string,mixed> $e
     */
    private function where(array $e, string $scope, int $depth = 0, ?string $alias = null, bool $purgeOnly = false): string
    {
        if ($depth > 8) {
            throw new \RuntimeException('via chain too deep at ' . $e['qualified']);
        }
        $a = $alias !== null ? self::q($alias) . '.' : '';
        if (isset($e['via'])) {
            $parent = $this->entry($scope, $e['via']['parent']) ?? $this->retainedEntry($scope, $e['via']['parent']);
            if ($parent === null) {
                throw new \RuntimeException('via parent missing for ' . $e['qualified']);
            }
            [$ps, $pt] = explode('.', $parent['qualified'], 2);
            $sql = $a . self::q($e['via']['column']) . ' IN (SELECT ' . self::q($e['via']['parent_column']) . ' FROM ' . self::q($ps) . '.' . self::q($pt)
                . ' WHERE ' . $this->where($parent, $scope, $depth + 1, null, $purgeOnly && empty($e['via']['include_kept'])) . ')';
        } else {
            $parts = array_map(static fn (string $k): string => $a . self::q($k) . ' = ?', $e['keys'] ?? [$e['key']]);
            $sql = count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
        }
        if (isset($e['only_if_null'])) {
            $sql .= ' AND ' . $a . self::q($e['only_if_null']) . ' IS NULL';
        }
        if ($purgeOnly && isset($e['retain_where'])) {
            $sql .= ' AND (' . $e['retain_where'] . ') IS NOT TRUE';
        }
        if ($purgeOnly && self::isRetainReferenced($e)) {
            $sql .= ' AND ' . $a . 'ctid NOT IN (SELECT k.tid FROM ' . self::KEEP . ' k WHERE k.tbl = ' . self::lit($e['qualified']) . ')';
        }

        return $sql;
    }

    /** @return list<string> the target value once per "?" */
    private static function params(string $sql, string $value): array
    {
        return array_fill(0, substr_count($sql, '?'), $value);
    }

    /** @param array<string,mixed> $e */
    private function archiveTable(array $e): string
    {
        $name = $e['schema'] === $this->manifest->schema() ? $e['table'] : $e['schema'] . '__' . $e['table'];

        return substr($name, 0, 63);
    }

    private static function target(string $scope, int|string $target): ?string
    {
        $t = trim((string) $target);
        if ($scope === 'company') {
            return preg_match('/^[1-9][0-9]{0,18}$/', $t) === 1 ? $t : null;
        }
        if ($scope === 'user') {
            return AccountPurge::validUuid($t);
        }

        throw new \InvalidArgumentException('scope is company or user');
    }

    public static function q(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /** "schema.table" → "schema"."table" */
    private static function qq(string $qualified): string
    {
        [$s, $t] = explode('.', $qualified, 2);

        return self::q($s) . '.' . self::q($t);
    }

    /** A SQL string literal (only ever for validated identifiers and fixed text). */
    private static function lit(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }
}
