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
 * Identifiers are plain lowercase SQL names; anything else is refused, so a manifest can never inject
 * SQL. aic_lifecycle_* (the kit's own tables) can never be listed.
 */
final class PurgeManifest
{
    public const SCOPES = ['company', 'user'];

    private const IDENT = '/^[a-z_][a-z0-9_]{0,62}$/';

    /** @param array<string,mixed> $data validated */
    private function __construct(private array $data)
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
                } elseif (self::ident($e['key'] ?? null)) {
                    $entry['key'] = $e['key'];
                } else {
                    $problems[] = "{$where} needs key (the column naming the " . ($section === 'company_tables' ? 'company' : 'person') . ') or via.';
                    continue;
                }
                if (isset($e['only_if_null'])) {
                    if (!self::ident($e['only_if_null'])) {
                        $problems[] = "{$where}.only_if_null must be a column name.";
                        continue;
                    }
                    $entry['only_if_null'] = $e['only_if_null'];
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
                if (!self::ident($e['key'] ?? null)) {
                    $problems[] = "{$where} needs key (the column the retained rows are counted by).";
                    continue;
                }
                $reason = is_string($e['reason'] ?? null) ? trim($e['reason']) : '';
                if ($reason === '' || stripos($reason, 'TODO') !== false) {
                    $problems[] = "{$where} ({$entry['table']}) needs the legal reason it is kept.";
                    continue;
                }
                $data[$section][] = $entry + ['key' => $e['key'], 'reason' => $reason];
            }
        }
        $data['ignore'] = [];
        foreach (self::listOf($raw, 'ignore', $problems) as $i => $e) {
            $entry = self::tableRef($e, $schema, "ignore[{$i}]", $problems);
            if ($entry !== null) {
                $data['ignore'][] = $entry + ['reason' => is_string($e['reason'] ?? null) ? $e['reason'] : ''];
            }
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
            foreach ($data[$scope . '_tables'] as $pos => $e) {
                if (isset($e['via'])) {
                    $p = $order[$e['via']['parent']] ?? null;
                    if ($p === null) {
                        $problems[] = "{$e['qualified']}: via parent {$e['via']['parent']} must itself be in {$scope}_tables.";
                    } elseif ($p <= $pos) {
                        $problems[] = "{$e['qualified']}: its via parent {$e['via']['parent']} must come after it (children are deleted first).";
                    }
                }
            }
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException("Purge manifest refused:\n - " . implode("\n - ", $problems));
        }

        return new self($data);
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

    /** @return list<array{table:string, schema:string, qualified:string, key?:string, via?:array{column:string,parent:string,parent_column:string}, only_if_null?:string}> */
    public function tables(string $scope): array
    {
        return $this->data[self::scope($scope) . '_tables'];
    }

    /** @return list<array{table:string, schema:string, qualified:string, key:string, reason:string}> */
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
            if (isset($e['key'])) {
                $keys[$e['key']] = true;
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
