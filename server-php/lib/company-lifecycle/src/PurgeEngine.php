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
 *   - two purged tables are linked by a foreign key but the child is not listed before its parent.
 * It never touches a table that is not in the manifest, nor the kit's own aic_lifecycle_* tables.
 *
 * purge() then, inside ONE transaction:
 *   1. creates schema aic_lifecycle_archive_<c{cmp_id}|u{uuid}>_<UTC YmdHis> with a _aic_archive index;
 *   2. for each table in manifest order: copies the target's rows into the archive, deletes them, and
 *      checks deleted = archived (a concurrent writer makes it roll back and fail, never half-purge);
 * commits, then reads the rows back (none live, all archived). Any error rolls everything back.
 * Retained tables are only counted, never copied or deleted; they are reported with their legal
 * reason. Dry run (purge(…, true)) reports the same counts and changes nothing.
 *
 * restore() puts an archive back (parents first); archives() / dropArchive() manage the archive
 * schemas, which hold personal data and must be dropped after the product's archive window.
 */
final class PurgeEngine
{
    public const ARCHIVE_PREFIX = 'aic_lifecycle_archive';

    private const ARCHIVE_RE = '/^aic_lifecycle_archive_([cu])([0-9]{1,19})_([0-9]{14})$/';

    /** @var callable(): int */
    private $clock;

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

    public static function archiveName(string $scope, int|string $target, int $ts): string
    {
        return self::ARCHIVE_PREFIX . '_' . ($scope === 'company' ? 'c' : 'u') . $target . '_' . gmdate('YmdHis', $ts);
    }

    /**
     * Validate and count, changing nothing.
     *
     * @return array{ok:bool, problems:list<string>, counts:array<string,int>, retained:array<string,int>, reasons:array<string,string>, total:int}
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
        foreach ($this->manifest->tables($scope) as $e) {
            $n = $this->count($e, $scope, $target);
            if ($n > 0) {
                $out['counts'][$e['qualified']] = $n;
            }
        }
        foreach ($this->manifest->retained($scope) as $e) {
            $n = $this->count($e, $scope, $target);
            if ($n > 0) {
                $out['retained'][$e['qualified']] = $n;
                $out['reasons'][$e['qualified']] = $e['reason'];
            }
        }
        $out['total'] = array_sum($out['counts']);
        $out['ok'] = true;

        return $out;
    }

    /**
     * @return array{ok:bool, status:string, scope:string, target:string, problems:list<string>, counts:array<string,int>, retained:array<string,int>, reasons:array<string,string>, total:int, archive:?string, error:?string}
     */
    public function purge(string $scope, int|string $target, bool $dryRun = false): array
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
        if ($plan['total'] === 0) {
            return ['ok' => true, 'status' => 'nothing_to_purge'] + $base;
        }

        $value = (string) self::target($scope, $target);
        $archive = self::archiveName($scope, $value, ($this->clock)());
        $done = [];
        try {
            $this->db->beginTransaction();
            $this->db->exec("SET LOCAL lock_timeout = '30s'");
            $this->db->exec('CREATE SCHEMA ' . self::q($archive));
            $this->db->exec('CREATE TABLE ' . self::q($archive) . '._aic_archive (seq INTEGER NOT NULL, source_schema TEXT NOT NULL, source_table TEXT NOT NULL,
                archive_table TEXT NOT NULL, scope TEXT NOT NULL, target TEXT NOT NULL, row_count BIGINT NOT NULL, archived_at TIMESTAMPTZ NOT NULL DEFAULT now())');
            $index = $this->db->prepare('INSERT INTO ' . self::q($archive) . '._aic_archive (seq, source_schema, source_table, archive_table, scope, target, row_count) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($this->manifest->tables($scope) as $seq => $e) {
                $where = $this->where($e, $scope);
                $src = self::q($e['schema']) . '.' . self::q($e['table']);
                $count = $this->db->prepare('SELECT COUNT(*) FROM ' . $src . ' WHERE ' . $where);
                $count->execute([$value]);
                if ((int) $count->fetchColumn() === 0) {
                    continue;
                }
                $arch = $this->archiveTable($e);
                $dst = self::q($archive) . '.' . self::q($arch);
                $this->db->exec('CREATE TABLE ' . $dst . ' AS SELECT * FROM ' . $src . ' WITH NO DATA');
                $copy = $this->db->prepare('INSERT INTO ' . $dst . ' SELECT * FROM ' . $src . ' WHERE ' . $where);
                $copy->execute([$value]);
                $archived = $copy->rowCount();
                $del = $this->db->prepare('DELETE FROM ' . $src . ' WHERE ' . $where);
                $del->execute([$value]);
                $deleted = $del->rowCount();
                if ($deleted !== $archived) {
                    throw new \RuntimeException("{$e['qualified']}: {$archived} row(s) archived but {$deleted} deleted (written to concurrently?)");
                }
                $index->execute([$seq, $e['schema'], $e['table'], $arch, $scope, $value, $archived]);
                $done[$e['qualified']] = $archived;
            }
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
            if (!isset($done[$e['qualified']])) {
                continue;
            }
            $live = $this->count($e, $scope, $value);
            $kept = (int) $this->db->query('SELECT COUNT(*) FROM ' . self::q($archive) . '.' . self::q($this->archiveTable($e)))->fetchColumn();
            if ($live !== 0 || $kept !== $done[$e['qualified']]) {
                $problems[] = "{$e['qualified']}: {$live} still live, {$kept} of {$done[$e['qualified']]} archived";
            }
        }
        $base['counts'] = $done;
        $base['total'] = array_sum($done);
        $base['archive'] = $archive;
        if ($problems !== []) {
            return ['ok' => false, 'status' => 'failed', 'error' => 'Committed, but the read-back disagrees: ' . implode('; ', $problems) . '. Investigate before retrying.'] + $base;
        }

        return ['ok' => true, 'status' => $done === [] ? 'nothing_to_purge' : 'purged'] + $base;
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
            $rows = $this->db->query('SELECT seq, source_schema, source_table, archive_table, row_count FROM ' . self::q($archive) . '._aic_archive ORDER BY seq DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $out['error'] = 'Archive ' . $archive . ' is missing or has no _aic_archive index.';

            return $out;
        }
        foreach ($rows as $r) {
            $out['tables'][$r['source_schema'] . '.' . $r['source_table']] = (int) $r['row_count'];
        }
        if (!$apply) {
            $out['ok'] = true;

            return $out;
        }
        try {
            $this->db->beginTransaction();
            foreach ($rows as $r) {
                $cols = $this->db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND is_generated = 'NEVER'
                    AND column_name IN (SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?) ORDER BY ordinal_position");
                $cols->execute([$r['source_schema'], $r['source_table'], $archive, $r['archive_table']]);
                $list = implode(', ', array_map([self::class, 'q'], $cols->fetchAll(PDO::FETCH_COLUMN)));
                $ins = $this->db->exec('INSERT INTO ' . self::q($r['source_schema']) . '.' . self::q($r['source_table']) . " ({$list}) OVERRIDING SYSTEM VALUE SELECT {$list} FROM "
                    . self::q($archive) . '.' . self::q($r['archive_table']));
                if ((int) $ins !== (int) $r['row_count']) {
                    throw new \RuntimeException("{$r['source_table']}: {$ins} of {$r['row_count']} row(s) restored");
                }
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
        $columns = $this->columns(array_keys($schemas));
        $has = static fn (string $q, string $col): bool => isset($columns[$q][$col]);

        $purge = $this->manifest->tables($scope);
        foreach (array_merge($purge, $this->manifest->retained($scope)) as $e) {
            $q = $e['qualified'];
            if (!isset($columns[$q])) {
                $problems[] = "{$q} does not exist.";
                continue;
            }
            foreach (array_filter([$e['key'] ?? null, $e['only_if_null'] ?? null, $e['via']['column'] ?? null]) as $col) {
                if (!$has($q, $col)) {
                    $problems[] = "{$q} has no column {$col}.";
                }
            }
            if (isset($e['via']) && !$has($e['via']['parent'], $e['via']['parent_column'])) {
                $problems[] = "{$e['via']['parent']} has no column {$e['via']['parent_column']}.";
            }
        }

        if ($this->manifest->strict()) {
            // Listed for THIS scope (purged or retained) or ignored: a table only in user_tables that
            // also carries cmp_id would otherwise keep the company's rows on a company purge.
            $listed = [];
            foreach (array_merge($purge, $this->manifest->retained($scope), $this->manifest->ignored()) as $e) {
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

        $pos = [];
        foreach ($purge as $i => $e) {
            $pos[$e['qualified']] = $i;
        }
        foreach ($this->foreignKeys() as $fk) {
            [$child, $parent, $action] = $fk;
            if (!isset($pos[$parent]) || $child === $parent) {
                continue;
            }
            if (isset($pos[$child])) {
                if ($pos[$child] > $pos[$parent]) {
                    $problems[] = "{$child} references {$parent} but is listed after it: children are deleted first.";
                }
            } elseif (in_array($action, ['c', 'n', 'd'], true)) {
                $problems[] = "{$child} has a foreign key into {$parent} with ON DELETE " . ['c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'][$action]
                    . ": purging would change its rows without archiving them. List it in {$scope}_tables before {$parent}.";
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param list<string> $schemas
     * @return array<string, array<string,true>> "schema.table" → columns (base tables only)
     */
    private function columns(array $schemas): array
    {
        $in = implode(', ', array_fill(0, count($schemas), '?'));
        $stmt = $this->db->prepare("
            SELECT c.table_schema, c.table_name, c.column_name
              FROM information_schema.columns c
              JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
             WHERE c.table_schema IN ({$in})
        ");
        $stmt->execute($schemas);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['table_schema'] . '.' . $r['table_name']][$r['column_name']] = true;
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string}> [child, parent, on-delete action code] */
    private function foreignKeys(): array
    {
        $rows = $this->db->query("
            SELECT cn.nspname || '.' || c.relname AS child, pn.nspname || '.' || p.relname AS parent, con.confdeltype AS action
              FROM pg_constraint con
              JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace cn ON cn.oid = c.relnamespace
              JOIN pg_class p ON p.oid = con.confrelid JOIN pg_namespace pn ON pn.oid = p.relnamespace
             WHERE con.contype = 'f'
        ")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [(string) $r['child'], (string) $r['parent'], (string) $r['action']], $rows);
    }

    /** @param array<string,mixed> $e */
    private function count(array $e, string $scope, string $value): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . self::q($e['schema']) . '.' . self::q($e['table']) . ' WHERE ' . $this->where($e, $scope));
        $stmt->execute([$value]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The row filter for one manifest entry, with exactly one "?" (the company or person). A via entry
     * nests its parent's filter.
     *
     * @param array<string,mixed> $e
     */
    private function where(array $e, string $scope, int $depth = 0): string
    {
        if ($depth > 8) {
            throw new \RuntimeException('via chain too deep at ' . $e['qualified']);
        }
        if (isset($e['via'])) {
            $parent = null;
            foreach ($this->manifest->tables($scope) as $p) {
                if ($p['qualified'] === $e['via']['parent']) {
                    $parent = $p;
                }
            }
            if ($parent === null) {
                throw new \RuntimeException('via parent missing for ' . $e['qualified']);
            }
            [$ps, $pt] = explode('.', $parent['qualified'], 2);
            $sql = self::q($e['via']['column']) . ' IN (SELECT ' . self::q($e['via']['parent_column']) . ' FROM ' . self::q($ps) . '.' . self::q($pt)
                . ' WHERE ' . $this->where($parent, $scope, $depth + 1) . ')';
        } else {
            $sql = self::q($e['key']) . ' = ?';
        }
        if (isset($e['only_if_null'])) {
            $sql .= ' AND ' . self::q($e['only_if_null']) . ' IS NULL';
        }

        return $sql;
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
}
