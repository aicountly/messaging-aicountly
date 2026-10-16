<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * What a purge in this product may touch — reviewed by a person, versioned with the product's code.
 * A PHP file returning an array (or the same as JSON). See examples/lifecycle-manifest.example.php.
 *
 *   app_key          the product's Manage catalog id
 *   schema           default schema of the tables below (default "public")
 *   strict           default true: refuse to purge while a table carrying one of a scope's key columns
 *                    is not in that scope's tables / retain lists nor in ignore (it was added after the
 *                    review, and purging around it would leave the company's or person's rows behind)
 *   company_tables   purged on company_purge_requested, IN THIS ORDER — children before parents, so
 *                    foreign keys hold. Each: {table, key} (the company key column: cmp_id,
 *                    company_id, …) or {table, via: {column, parent, parent_column}} for a child table
 *                    with no company column (rows whose `column` points at a purged `parent` row; the
 *                    parent must be listed AFTER it). Optional schema.
 *   company_retain   kept on purge because a statute requires it: {table, key, reason} — reason is the
 *                    legal basis, reported to Manage in the ack (retained[]) and to ops
 *   user_tables      purged on account_purge_requested, in order: the person's OWN data that is not
 *                    company data (personal settings, personal notes/calendars/chats/files, AI history,
 *                    sessions, device tokens). {table, key} with key = the column holding the portal
 *                    uuid (uuid_aictly, user_uuid, owner_uuid, …); optional only_if_null: "<column>" to
 *                    take only rows outside any company (e.g. a notes table shared by personal and
 *                    company notes). Data inside a company stays with the company.
 *   user_retain      kept on account purge, with the legal reason (e.g. security/sign-in logs)
 *   ignore           tables a key-column scan finds that are neither (reference data, the product's
 *                    own bookkeeping): {table, reason}. Listed so strict mode knows they were reviewed.
 *   draft            set by bin/discover-manifest.php; a manifest with draft: true is refused at runtime
 *
 * Since 1.1 (all optional; a 1.0 manifest validates and behaves exactly as before):
 *
 *   key_columns      instead of key, on any {table, key} entry: ['firm_cmp_id', 'client_cmp_id'] — the
 *                    row belongs to the company / person when ANY of them matches.
 *   mode             on a *_tables entry: 'purge' (default) or 'retain_referenced' with
 *                    referenced_by: [table | {table, column, parent_column?, schema?}, …] — the
 *                    tables that may reference this one. Rows referenced (directly, or through
 *                    other retain_referenced tables) by a row that stays are kept; every other row of
 *                    the company / person is purged (archived first). A table name uses every foreign
 *                    key from it to this table; {table, column} names one reference (with or without
 *                    a declared foreign key; parent_column defaults to the single-column primary
 *                    key). Optional reason (default: "referenced by retained records").
 *   retain_years     on a *_retain entry — or retain_days (e.g. 180 for CERT-In logs) — with
 *                    retain_from (a date / timestamp column): the row is deleted by
 *                    `bin/worker.php --expire-retained` once retain_from + the period has passed and
 *                    the company / person was purged. Missing → a warning (warnings()), never a
 *                    refusal; half-given is refused.
 *   retain_where     on a *_tables entry (mode purge), with reason (+ optional period): one SQL
 *                    condition on the table's own columns — matching rows of the target are retained
 *                    (reported, scrubbed, expired like a *_retain table), the others purged in manifest
 *                    order. The only free SQL a manifest may hold: no ; ? -- or comments.
 *   via parent       may also be a *_retain table (purge_children_of_retained: the child rows of the
 *                    target's retained rows are purged, the parent rows stay). A child of a
 *                    retain_referenced / retain_where parent follows only the parent rows that are
 *                    purged, unless via.include_kept: true (then the children of kept rows go too).
 *   scrub            {"table.column" | "schema.table.column": "null" | "redact" | "json_paths:[a.b, c.*.d]"}
 *                    applied to rows before they are copied into an archive, and to retained rows
 *                    (and rows a retain_referenced table keeps) at purge time. redact writes
 *                    "[redacted]" into text / json columns (NULL into other types); json_paths
 *                    replaces those keys' values with "[redacted]" (`*` = every key / element; a text
 *                    column that is not JSON is redacted whole).
 *   break_cycle      ["table.column", …]: for a foreign-key cycle whose constraints are not
 *                    DEFERRABLE, the engine sets these columns to NULL on the rows being purged
 *                    (after archiving them) before deleting. The table needs a primary key (restore
 *                    puts the link back). Deferrable cycles need nothing: the purge runs
 *                    SET CONSTRAINTS ALL DEFERRED when an out-of-order foreign key is deferrable.
 *
 * Identifiers are plain lowercase SQL names; anything else is refused, so a manifest can never inject
 * SQL. aic_lifecycle_* (the kit's own tables) can never be listed.
 */
final class PurgeManifest
{
    public const SCOPES = ['company', 'user'];

    public const MODES = ['purge', 'retain_referenced'];

    public const SCRUB_KINDS = ['null', 'redact', 'json_paths'];

    private const IDENT = '/^[a-z_][a-z0-9_]{0,62}$/';

    private const JSON_PATH = '/^(\*|[A-Za-z0-9_-]{1,64})(\.(\*|[A-Za-z0-9_-]{1,64})){0,15}$/';

    /**
     * @param array<string,mixed> $data validated
     * @param list<string> $warnings
     */
    private function __construct(private array $data, private array $warnings = [])
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Purge manifest not found: ' . $path);
        }
        if (str_ends_with(strtolower($path), '.json')) {
            $data = json_decode((string) file_get_contents($path), true);
        } else {
            $data = (static fn (string $p): mixed => require $p)($path);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Purge manifest ' . $path . ' must return an array / JSON object.');
        }

        return self::fromArray($data);
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $problems = [];
        $warnings = [];
        if (!empty($raw['draft'])) {
            $problems[] = 'This is an unreviewed draft (draft: true). Review every entry, then remove "draft".';
        }
        $app = is_string($raw['app_key'] ?? null) ? strtolower(trim($raw['app_key'])) : '';
        if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $app) !== 1) {
            $problems[] = 'app_key must be the Manage catalog id.';
        }
        $schema = is_string($raw['schema'] ?? null) ? $raw['schema'] : 'public';
        if (preg_match(self::IDENT, $schema) !== 1) {
            $problems[] = 'schema must be a plain lowercase identifier.';
        }
        $data = [
            'app_key' => $app,
            'schema'  => $schema,
            'strict'  => !array_key_exists('strict', $raw) || (bool) $raw['strict'],
        ];

        foreach (['company_tables', 'user_tables'] as $section) {
            $data[$section] = [];
            foreach (self::listOf($raw, $section, $problems) as $i => $e) {
                $where = "{$section}[{$i}]";
                $entry = self::tableRef($e, $schema, $where, $problems);
                if ($entry === null) {
                    continue;
                }
                if (isset($e['via'])) {
                    $via = $e['via'];
                    if (!is_array($via) || !self::ident($via['column'] ?? null) || !self::ident($via['parent'] ?? null) || !self::ident($via['parent_column'] ?? null)) {
                        $problems[] = "{$where}.via needs column, parent and parent_column as plain identifiers.";
                        continue;
                    }
                    $entry['via'] = [
                        'column'        => $via['column'],
                        'parent'        => self::qualify($via['parent'], is_string($via['parent_schema'] ?? null) ? $via['parent_schema'] : $schema),
                        'parent_column' => $via['parent_column'],
                    ];
                    if (!empty($via['include_kept'])) {
                        $entry['via']['include_kept'] = true;
                    }
                } else {
                    $keys = self::keys($e, $where, $section === 'company_tables' ? 'company' : 'person', $problems);
                    if ($keys === null) {
                        continue;
                    }
                    $entry += $keys;
                }
                if (isset($e['only_if_null'])) {
                    if (!self::ident($e['only_if_null'])) {
                        $problems[] = "{$where}.only_if_null must be a column name.";
                        continue;
                    }
                    $entry['only_if_null'] = $e['only_if_null'];
                }
                $mode = $e['mode'] ?? 'purge';
                if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
                    $problems[] = "{$where}.mode must be purge or retain_referenced.";
                    continue;
                }
                if ($mode === 'retain_referenced') {
                    $refs = self::referencedBy($e['referenced_by'] ?? null, $schema, $where, $problems);
                    if ($refs === null) {
                        continue;
                    }
                    $reason = is_string($e['reason'] ?? null) ? trim($e['reason']) : '';
                    if (stripos($reason, 'TODO') !== false) {
                        $problems[] = "{$where} ({$entry['table']}): replace the TODO reason.";
                        continue;
                    }
                    $entry['mode'] = $mode;
                    $entry['referenced_by'] = $refs;
                    $entry['reason'] = $reason !== '' ? $reason
                        : 'referenced by retained records (' . implode(', ', array_unique(array_column($refs, 'table'))) . ')';
                } elseif (isset($e['referenced_by'])) {
                    $problems[] = "{$where}.referenced_by is only for mode retain_referenced.";
                    continue;
                }
                if (isset($e['retain_where'])) {
                    $rw = is_string($e['retain_where']) ? trim($e['retain_where']) : '';
                    $reason = is_string($e['reason'] ?? null) ? trim($e['reason']) : '';
                    if ($mode !== 'purge' || !self::safePredicate($rw)) {
                        $problems[] = "{$where}.retain_where must be one SQL condition on the table's own columns (no ;, ?, comments; at most 500 characters), on a mode purge entry.";
                        continue;
                    }
                    if ($reason === '' || stripos($reason, 'TODO') !== false) {
                        $problems[] = "{$where} ({$entry['table']}): retain_where needs the legal reason the matching rows are kept.";
                        continue;
                    }
                    $period = self::period($e, $where, $entry['qualified'], $problems, $warnings);
                    if ($period === null) {
                        continue;
                    }
                    $entry += ['retain_where' => $rw, 'reason' => $reason] + $period;
                } elseif (isset($e['retain_years']) || isset($e['retain_days']) || isset($e['retain_from'])) {
                    $problems[] = "{$where}: retain_years / retain_days / retain_from belong on a *_retain entry or with retain_where.";
                    continue;
                }
                $data[$section][] = $entry;
            }
        }
        foreach (['company_retain', 'user_retain'] as $section) {
            $data[$section] = [];
            foreach (self::listOf($raw, $section, $problems) as $i => $e) {
                $where = "{$section}[{$i}]";
                $entry = self::tableRef($e, $schema, $where, $problems);
                if ($entry === null) {
                    continue;
                }
                $keys = self::keys($e, $where, null, $problems);
                if ($keys === null) {
                    continue;
                }
                $reason = is_string($e['reason'] ?? null) ? trim($e['reason']) : '';
                if ($reason === '' || stripos($reason, 'TODO') !== false) {
                    $problems[] = "{$where} ({$entry['table']}) needs the legal reason it is kept.";
                    continue;
                }
                $entry += $keys + ['reason' => $reason];
                if (isset($e['retain_where'])) {
                    $problems[] = "{$where}: retain_where belongs on a *_tables entry (the rows it does not match are purged in manifest order).";
                    continue;
                }
                $period = self::period($e, $where, $entry['qualified'], $problems, $warnings);
                if ($period === null) {
                    continue;
                }
                $data[$section][] = $entry + $period;
            }
        }
        $data['ignore'] = [];
        foreach (self::listOf($raw, 'ignore', $problems) as $i => $e) {
            $entry = self::tableRef($e, $schema, "ignore[{$i}]", $problems);
            if ($entry !== null) {
                $data['ignore'][] = $entry + ['reason' => is_string($e['reason'] ?? null) ? $e['reason'] : ''];
            }
        }

        $listed = [];
        foreach (['company_tables', 'company_retain', 'user_tables', 'user_retain', 'ignore'] as $s) {
            foreach ($data[$s] as $e) {
                $listed[$e['qualified']] = true;
            }
        }
        $data['scrub'] = [];
        $scrub = $raw['scrub'] ?? [];
        if (!is_array($scrub) || ($scrub !== [] && array_is_list($scrub))) {
            $problems[] = 'scrub must map "table.column" to null, redact or json_paths:[…].';
            $scrub = [];
        }
        foreach ($scrub as $ref => $rule) {
            $col = self::columnRef((string) $ref, $schema);
            $parsed = self::scrubRule($rule);
            if ($col === null || $parsed === null) {
                $problems[] = "scrub[{$ref}] must be \"table.column\" => null | redact | json_paths:[a.b, c.*.d].";
                continue;
            }
            if (!isset($listed[$col[0]])) {
                $problems[] = "scrub[{$ref}]: {$col[0]} is not listed in the manifest.";
                continue;
            }
            $data['scrub'][$col[0]][$col[1]] = $parsed;
        }
        $data['break_cycle'] = [];
        $breaks = $raw['break_cycle'] ?? [];
        if (!is_array($breaks) || !array_is_list($breaks)) {
            $problems[] = 'break_cycle must be a list of "table.column".';
            $breaks = [];
        }
        foreach ($breaks as $i => $ref) {
            $col = is_string($ref) ? self::columnRef($ref, $schema) : null;
            if ($col === null) {
                $problems[] = "break_cycle[{$i}] must be \"table.column\".";
                continue;
            }
            $inTables = in_array($col[0], array_column(array_merge($data['company_tables'], $data['user_tables']), 'qualified'), true);
            if (!$inTables) {
                $problems[] = "break_cycle[{$i}]: {$col[0]} must be in company_tables or user_tables (only purged rows are unlinked).";
                continue;
            }
            $data['break_cycle'][$col[0]][] = $col[1];
        }

        // Each table once per scope; via parents listed after their children.
        foreach (self::SCOPES as $scope) {
            $seen = [];
            foreach (array_merge($data[$scope . '_tables'], $data[$scope . '_retain']) as $e) {
                if (isset($seen[$e['qualified']])) {
                    $problems[] = "{$e['qualified']} is listed twice in the {$scope} sections.";
                }
                $seen[$e['qualified']] = true;
            }
            $order = array_flip(array_column($data[$scope . '_tables'], 'qualified'));
            $retained = array_flip(array_column($data[$scope . '_retain'], 'qualified'));
            foreach ($data[$scope . '_tables'] as $pos => $e) {
                if (isset($e['via'])) {
                    $p = $order[$e['via']['parent']] ?? null;
                    if ($p === null && isset($retained[$e['via']['parent']])) {
                        // purge_children_of_retained: the parent stays, its children of the target go.
                        continue;
                    }
                    if ($p === null) {
                        $problems[] = "{$e['qualified']}: via parent {$e['via']['parent']} must itself be in {$scope}_tables or {$scope}_retain.";
                    } elseif ($p <= $pos) {
                        $problems[] = "{$e['qualified']}: its via parent {$e['via']['parent']} must come after it (children are deleted first).";
                    }
                }
            }
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException("Purge manifest refused:\n - " . implode("\n - ", $problems));
        }

        return new self($data, $warnings);
    }

    /**
     * What a reviewer should still add (kit 1.1: retained tables without retain_years / retain_from).
     * Never a refusal.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, array{kind:string, paths:list<string>}> column → rule, for one "schema.table" */
    public function scrub(string $qualified): array
    {
        return $this->data['scrub'][$qualified] ?? [];
    }

    /** @return array<string, array<string, array{kind:string, paths:list<string>}>> */
    public function scrubRules(): array
    {
        return $this->data['scrub'];
    }

    /** @return list<string> columns break_cycle NULLs on the purged rows of "schema.table" */
    public function breakCycle(string $qualified): array
    {
        return $this->data['break_cycle'][$qualified] ?? [];
    }

    public function appKey(): string
    {
        return $this->data['app_key'];
    }

    public function schema(): string
    {
        return $this->data['schema'];
    }

    public function strict(): bool
    {
        return $this->data['strict'];
    }

    /** @return list<array{table:string, schema:string, qualified:string, key?:string, keys?:list<string>, via?:array{column:string,parent:string,parent_column:string}, only_if_null?:string, mode?:string, referenced_by?:list<array{table:string, column:?string, parent_column:?string}>, reason?:string}> */
    public function tables(string $scope): array
    {
        return $this->data[self::scope($scope) . '_tables'];
    }

    /** @return list<array{table:string, schema:string, qualified:string, key?:string, keys:list<string>, reason:string, retain_years?:int, retain_from?:string}> */
    public function retained(string $scope): array
    {
        return $this->data[self::scope($scope) . '_retain'];
    }

    /** @return list<array{table:string, schema:string, qualified:string, reason:string}> */
    public function ignored(): array
    {
        return $this->data['ignore'];
    }

    /** @return array<string, true> every table named anywhere in the manifest ("schema.table") */
    public function listed(): array
    {
        $all = [];
        foreach (['company_tables', 'company_retain', 'user_tables', 'user_retain', 'ignore'] as $s) {
            foreach ($this->data[$s] as $e) {
                $all[$e['qualified']] = true;
            }
        }

        return $all;
    }

    /** @return list<string> the key columns this scope's tables and retained tables use */
    public function keyColumns(string $scope): array
    {
        $scope = self::scope($scope);
        $keys = [];
        foreach (array_merge($this->data[$scope . '_tables'], $this->data[$scope . '_retain']) as $e) {
            foreach ($e['keys'] ?? [] as $k) {
                $keys[$k] = true;
            }
        }

        return array_keys($keys);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function scope(string $scope): string
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('scope is company or user');
        }

        return $scope;
    }

    private static function ident(mixed $v): bool
    {
        return is_string($v) && preg_match(self::IDENT, $v) === 1;
    }

    public static function qualify(string $table, string $schema): string
    {
        return $schema . '.' . $table;
    }

    /**
     * @param array<string,mixed> $raw
     * @param list<string> $problems
     * @return list<mixed>
     */
    private static function listOf(array $raw, string $section, array &$problems): array
    {
        $v = $raw[$section] ?? [];
        if (!is_array($v) || ($v !== [] && !array_is_list($v))) {
            $problems[] = "{$section} must be a list.";

            return [];
        }

        return $v;
    }

    /**
     * retain_years | retain_days, with retain_from. Neither: a warning. Anything else half-given: a problem.
     *
     * @param array<string,mixed> $e
     * @param list<string> $problems
     * @param list<string> $warnings
     * @return array{retain_years?:int, retain_days?:int, retain_from?:string}|null
     */
    private static function period(array $e, string $where, string $qualified, array &$problems, array &$warnings): ?array
    {
        $years = $e['retain_years'] ?? null;
        $days = $e['retain_days'] ?? null;
        $from = $e['retain_from'] ?? null;
        if ($years === null && $days === null && $from === null) {
            $warnings[] = "{$where} ({$qualified}): add retain_years or retain_days, and retain_from (kit 1.1), so `bin/worker.php --expire-retained` deletes these rows when the statutory period ends — until then they are kept for ever.";

            return [];
        }
        $okYears = is_int($years) && $years >= 1 && $years <= 100;
        $okDays = is_int($days) && $days >= 1 && $days <= 36600;
        if (($years !== null) === ($days !== null) || ($years !== null && !$okYears) || ($days !== null && !$okDays) || !self::ident($from)) {
            $problems[] = "{$where} ({$qualified}): give retain_years (1-100) OR retain_days (1-36600), with retain_from (the date column the period runs from).";

            return null;
        }

        return ($years !== null ? ['retain_years' => $years] : ['retain_days' => $days]) + ['retain_from' => $from];
    }

    /** retain_where: one condition, no statement separators, placeholders or comments. */
    private static function safePredicate(string $sql): bool
    {
        return $sql !== '' && strlen($sql) <= 500 && preg_match('/;|\?|--|\/\*|\*\/|\$\$/', $sql) !== 1
            && substr_count($sql, '(') === substr_count($sql, ')') && substr_count($sql, "'") % 2 === 0;
    }

    /**
     * key (one column) or key_columns (several; any one matching).
     *
     * @param array<string,mixed> $e
     * @param list<string> $problems
     * @return array{key?:string, keys:list<string>}|null
     */
    private static function keys(array $e, string $where, ?string $noun, array &$problems): ?array
    {
        if (isset($e['key_columns'])) {
            $cols = $e['key_columns'];
            if (isset($e['key']) || !is_array($cols) || $cols === [] || !array_is_list($cols) || count($cols) > 8
                || count(array_filter($cols, [self::class, 'ident'])) !== count($cols) || count(array_unique($cols)) !== count($cols)) {
                $problems[] = "{$where}.key_columns must be a list of distinct column names (and no key beside it).";

                return null;
            }

            return ['keys' => array_values($cols)];
        }
        if (self::ident($e['key'] ?? null)) {
            return ['key' => $e['key'], 'keys' => [$e['key']]];
        }
        $problems[] = $noun !== null
            ? "{$where} needs key (the column naming the {$noun}) or via."
            : "{$where} needs key (the column the retained rows are counted by).";

        return null;
    }

    /**
     * @param list<string> $problems
     * @return list<array{table:string, column:?string, parent_column:?string}>|null
     */
    private static function referencedBy(mixed $refs, string $schema, string $where, array &$problems): ?array
    {
        if (!is_array($refs) || $refs === [] || !array_is_list($refs)) {
            $problems[] = "{$where}: mode retain_referenced needs referenced_by, the tables whose rows keep this table's rows.";

            return null;
        }
        $out = [];
        foreach ($refs as $j => $r) {
            if (is_string($r) && self::ident($r)) {
                $out[] = ['table' => self::qualify($r, $schema), 'column' => null, 'parent_column' => null];
            } elseif (is_string($r) && ($c = self::tableName($r)) !== null) {
                $out[] = ['table' => $c, 'column' => null, 'parent_column' => null];
            } elseif (is_array($r) && self::ident($r['table'] ?? null) && self::ident($r['column'] ?? null)
                && (!isset($r['parent_column']) || self::ident($r['parent_column'])) && (!isset($r['schema']) || self::ident($r['schema']))) {
                $out[] = ['table' => self::qualify($r['table'], $r['schema'] ?? $schema), 'column' => $r['column'], 'parent_column' => $r['parent_column'] ?? null];
            } else {
                $problems[] = "{$where}.referenced_by[{$j}] must be a table name or {table, column, parent_column?}.";

                return null;
            }
        }

        return $out;
    }

    /** "schema.table" for "schema.table", null otherwise. */
    private static function tableName(string $ref): ?string
    {
        $parts = explode('.', $ref);

        return count($parts) === 2 && self::ident($parts[0]) && self::ident($parts[1]) ? $ref : null;
    }

    /** ["schema.table", column] for "table.column" / "schema.table.column". */
    private static function columnRef(string $ref, string $schema): ?array
    {
        $parts = explode('.', trim($ref));
        if (count($parts) === 2) {
            array_unshift($parts, $schema);
        }
        if (count($parts) !== 3 || !self::ident($parts[0]) || !self::ident($parts[1]) || !self::ident($parts[2])) {
            return null;
        }
        if (str_starts_with($parts[1], 'aic_lifecycle_')) {
            return null;
        }

        return [$parts[0] . '.' . $parts[1], $parts[2]];
    }

    /**
     * "null" | "redact" | "json_paths:[a.b, c.*.d]" (also json_paths:["a.b"] or ['json_paths' => ['a.b']]).
     *
     * @return array{kind:string, paths:list<string>}|null
     */
    public static function scrubRule(mixed $rule): ?array
    {
        if (is_array($rule) && isset($rule['json_paths']) && is_array($rule['json_paths'])) {
            $paths = $rule['json_paths'];
        } elseif (is_string($rule) && in_array(strtolower(trim($rule)), ['null', 'redact'], true)) {
            return ['kind' => strtolower(trim($rule)), 'paths' => []];
        } elseif (is_string($rule) && preg_match('/^json_paths:\s*\[(.*)\]\s*$/s', trim($rule), $m) === 1) {
            $paths = array_map(static fn (string $p): string => trim($p, " \t\n\"'"), explode(',', $m[1]));
        } else {
            return null;
        }
        $paths = array_values(array_filter(array_map(static fn ($p): string => is_string($p) ? trim($p) : '', $paths), static fn (string $p): bool => $p !== ''));
        if ($paths === [] || count($paths) > 50) {
            return null;
        }
        foreach ($paths as $p) {
            if (preg_match(self::JSON_PATH, $p) !== 1) {
                return null;
            }
        }

        return ['kind' => 'json_paths', 'paths' => $paths];
    }

    /**
     * @param list<string> $problems
     * @return array{table:string, schema:string, qualified:string}|null
     */
    private static function tableRef(mixed $e, string $defaultSchema, string $where, array &$problems): ?array
    {
        if (!is_array($e) || !self::ident($e['table'] ?? null)) {
            $problems[] = "{$where} needs table (a plain lowercase identifier).";

            return null;
        }
        $schema = $e['schema'] ?? $defaultSchema;
        if (!self::ident($schema)) {
            $problems[] = "{$where}.schema must be a plain lowercase identifier.";

            return null;
        }
        if (str_starts_with($e['table'], 'aic_lifecycle_') || str_starts_with($schema, 'aic_lifecycle_')) {
            $problems[] = "{$where}: {$e['table']} is the kit's own bookkeeping and can never be purged, retained or listed.";

            return null;
        }

        return ['table' => $e['table'], 'schema' => $schema, 'qualified' => self::qualify($e['table'], $schema)];
    }
}
