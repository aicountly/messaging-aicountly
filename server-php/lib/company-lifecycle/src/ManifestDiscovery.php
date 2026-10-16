<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

use PDO;

/**
 * Draft a purge manifest from a database's information_schema — for a PERSON to review. Never used at
 * runtime: the draft carries draft: true, which PurgeManifest refuses until someone has gone through
 * every entry and removed it.
 *
 * It finds base tables with a company column (cmp_id, company_id, companyid, organization_id) or a
 * person column (uuid, uuid_aictly, user_uuid, owner_uuid), orders each list children-first from the
 * foreign keys, proposes `via` entries for child tables that only reach the company through a foreign
 * key, and guesses which tables are statutory (audit / log / history / filed returns) — every guess is
 * marked REVIEW.
 */
final class ManifestDiscovery
{
    public const COMPANY_COLUMNS = ['cmp_id', 'company_id', 'companyid', 'organization_id'];

    public const USER_COLUMNS = ['uuid_aictly', 'user_uuid', 'owner_uuid', 'uuid'];

    /** Names that usually mean "kept by law": a person decides, the draft only flags them. */
    private const RETAIN_HINT = '/(audit|_log$|_logs$|log_|history|ledger_archive|gst_return|einvoice|eway|tds_return|filed)/';

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{manifest: array<string,mixed>, notes: array<string, list<string>>}
     */
    public function draft(string $appKey, string $schema = 'public'): array
    {
        $cols = $this->columns($schema);
        $fks = $this->foreignKeys($schema);
        $notes = [];
        $company = [];
        $user = [];
        $ignore = [];

        foreach ($cols as $table => $set) {
            if (str_starts_with($table, 'aic_lifecycle_')) {
                continue;
            }
            $ck = self::first($set, self::COMPANY_COLUMNS);
            $uk = self::first($set, self::USER_COLUMNS);
            if ($ck !== null) {
                $company[$table] = ['table' => $table, 'key' => $ck];
                if ($uk !== null) {
                    $notes[$table][] = "also has {$uk}: company data (stays with the company on an account purge) — unless it is the person's own setting inside the company, then ALSO list it in user_tables";
                }
                if (preg_match(self::RETAIN_HINT, $table) === 1) {
                    $notes[$table][] = 'name suggests a statutory record: move to company_retain with the legal reason if the law requires keeping it';
                }
            } elseif ($uk !== null) {
                $user[$table] = ['table' => $table, 'key' => $uk];
                $notes[$table][] = "no company column: personal data keyed by {$uk}? (if {$uk} is the row's own id, move it to ignore)";
            }
        }

        // Child tables with no key column that hang off a listed table: propose via entries.
        $addVia = static function (array &$list, array $other) use ($fks, &$notes): void {
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($fks as [$child, $childCol, $parent, $parentCol, $action]) {
                    if (isset($list[$parent]) && !isset($list[$child]) && !isset($other[$child]) && $child !== $parent
                        && !str_starts_with($child, 'aic_lifecycle_')) {
                        $list[$child] = ['table' => $child, 'via' => ['column' => $childCol, 'parent' => $parent, 'parent_column' => $parentCol]];
                        $notes[$child][] = "reaches {$parent} only through {$childCol}" . ($action === 'c' ? ' (ON DELETE CASCADE)' : '') . ' — check it really belongs to the same company/person';
                        $changed = true;
                    }
                }
            }
        };
        $addVia($company, $user);
        $addVia($user, $company);

        foreach ($cols as $table => $set) {
            if (!isset($company[$table]) && !isset($user[$table]) && !str_starts_with($table, 'aic_lifecycle_')) {
                foreach ($set as $c => $_) {
                    if (str_contains($c, 'cmp') || str_contains($c, 'company') || str_ends_with($c, 'uuid')) {
                        $ignore[$table] = ['table' => $table, 'reason' => 'REVIEW: has ' . $c . ' — company or personal data after all?'];
                        break;
                    }
                }
            }
        }

        $retain = [];
        foreach ($company as $t => $e) {
            if (isset($e['key']) && preg_match(self::RETAIN_HINT, $t) === 1) {
                $anchor = self::first($cols[$t], ['created_at', 'filed_on', 'filed_at', 'invoice_date', 'report_date', 'occurred_at', 'at']);
                $retain[] = $e + ['reason' => 'TODO: the statute that requires keeping it, or move back to company_tables']
                    + ($anchor !== null ? ['retain_years' => 8, 'retain_from' => $anchor] : []);
                $notes[$t][] = $anchor !== null
                    ? "retain_years 8 from {$anchor} is a guess: set the statute's period (retain_days for CERT-In's 180 days) and the column it runs from"
                    : 'add retain_years (or retain_days) and retain_from, the date column the statutory period runs from (kit 1.1)';
                unset($company[$t]);
            }
        }

        return [
            'manifest' => [
                'draft'          => true,
                'app_key'        => $appKey,
                'schema'         => $schema,
                'strict'         => true,
                'company_tables' => $this->order($company, $fks),
                'company_retain' => $retain,
                'user_tables'    => $this->order($user, $fks),
                'user_retain'    => [],
                'ignore'         => array_values($ignore),
            ],
            'notes' => $notes,
        ];
    }

    /**
     * PHP source of the draft, one entry per line with its REVIEW notes.
     *
     * @param array{manifest: array<string,mixed>, notes: array<string, list<string>>} $draft
     */
    public static function render(array $draft, string $generatedFrom): string
    {
        $m = $draft['manifest'];
        $out = "<?php\n// DRAFT purge manifest — generated " . gmdate('Y-m-d H:i') . " UTC by bin/discover-manifest.php from {$generatedFrom}.\n"
            . "// Never used at runtime while 'draft' => true. Review EVERY entry (README § Manifest review), fix the\n"
            . "// order (children before parents), give each retained table its legal reason, then delete 'draft'.\n\n"
            . "return [\n    'draft'   => true,\n    'app_key' => " . var_export($m['app_key'], true) . ",\n    'schema'  => " . var_export($m['schema'], true)
            . ",\n    'strict'  => true,\n";
        foreach (['company_tables', 'company_retain', 'user_tables', 'user_retain', 'ignore'] as $section) {
            $out .= "\n    '{$section}' => [\n";
            foreach ($m[$section] as $e) {
                foreach ($draft['notes'][$e['table']] ?? [] as $n) {
                    $out .= "        // REVIEW: {$n}\n";
                }
                $out .= '        ' . self::inline($e) . ",\n";
            }
            $out .= "    ],\n";
        }

        return $out . "];\n";
    }

    /** @param array<string,mixed> $v */
    private static function inline(array $v): string
    {
        $parts = [];
        foreach ($v as $k => $x) {
            $parts[] = var_export($k, true) . ' => ' . (is_array($x) ? self::inline($x) : var_export($x, true));
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Children before parents (topological order on the foreign keys between listed tables; cycles keep
     * their alphabetical order and are left for the reviewer).
     *
     * @param array<string, array<string,mixed>> $list
     * @param list<array{0:string,1:string,2:string,3:string,4:string}> $fks
     * @return list<array<string,mixed>>
     */
    private function order(array $list, array $fks): array
    {
        ksort($list);
        $parentsOf = [];
        foreach ($fks as [$child, , $parent]) {
            if (isset($list[$child], $list[$parent]) && $child !== $parent) {
                $parentsOf[$parent][$child] = true; // parent must wait for child
            }
        }
        $out = [];
        $done = [];
        $visiting = [];
        $visit = function (string $t) use (&$visit, &$out, &$done, &$visiting, $parentsOf, $list): void {
            if (isset($done[$t]) || isset($visiting[$t])) {
                return;
            }
            $visiting[$t] = true;
            foreach (array_keys($parentsOf[$t] ?? []) as $child) {
                $visit($child);
            }
            unset($visiting[$t]);
            $done[$t] = true;
            $out[] = $list[$t];
        };
        foreach (array_keys($list) as $t) {
            $visit($t);
        }

        return $out;
    }

    /** @return array<string, array<string,true>> */
    private function columns(string $schema): array
    {
        $stmt = $this->db->prepare("
            SELECT c.table_name, c.column_name FROM information_schema.columns c
              JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
             WHERE c.table_schema = ? ORDER BY c.table_name, c.ordinal_position
        ");
        $stmt->execute([$schema]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['table_name']][(string) $r['column_name']] = true;
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:string}> [child, child column, parent, parent column, on-delete] single-column FKs */
    private function foreignKeys(string $schema): array
    {
        $stmt = $this->db->prepare("
            SELECT c.relname AS child, ca.attname AS child_col, p.relname AS parent, pa.attname AS parent_col, con.confdeltype AS action
              FROM pg_constraint con
              JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace
              JOIN pg_class p ON p.oid = con.confrelid
              JOIN pg_attribute ca ON ca.attrelid = con.conrelid AND ca.attnum = con.conkey[1]
              JOIN pg_attribute pa ON pa.attrelid = con.confrelid AND pa.attnum = con.confkey[1]
             WHERE con.contype = 'f' AND n.nspname = ? AND array_length(con.conkey, 1) = 1
             ORDER BY 1, 2
        ");
        $stmt->execute([$schema]);

        return array_map(static fn (array $r): array => [(string) $r['child'], (string) $r['child_col'], (string) $r['parent'], (string) $r['parent_col'], (string) $r['action']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,true> $set
     * @param list<string> $candidates
     */
    private static function first(array $set, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (isset($set[$c])) {
                return $c;
            }
        }

        return null;
    }
}
