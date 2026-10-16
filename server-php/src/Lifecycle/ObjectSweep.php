<?php

declare(strict_types=1);

namespace Aicountly\Api\Lifecycle;

use PDO;

/**
 * Company lifecycle: what lives OUTSIDE the database goes with the rows that pointed at it.
 *
 * The receiver kit (lib/company-lifecycle) purges rows archive-first in one transaction and then runs
 * the product's hooks. This is that hook. After the commit it reads the rows that were just purged —
 * from the purge's own archive schema, so exactly those rows and no others — collects the stored
 * objects they reference (attachment files, Drive documents, connected-account tokens) into
 * lifecycle_object_deletions, and deletes them:
 *
 *   - each object is tried up to $tries times with a short back-off;
 *   - every outcome is recorded on its lifecycle_object_deletions row (attempts, last_error,
 *     finished_at) — that table is the audit trail of what was removed outside the database;
 *   - an object that still could not be removed is left `pending` and the hook THROWS, so the kit
 *     retries the job (10, 20 minutes later; the rows are already gone, so the retry only finishes
 *     the objects) and, after its last attempt, acks Manage `failed` — ops then fix the store and
 *     run `bin/worker.php --requeue=<event_id>`. A purge is never reported done while an object it
 *     owed is still stored.
 *
 * Collectors map an archived table to the objects a row references; deleters remove one object of a
 * store, return normally when it is gone (already-gone counts as gone) and throw when it is not.
 * A deleter receives the queue row too (archive_name, source_table), for the stores that must re-read
 * the archived row — a token is never copied into the queue, only a pointer to its row.
 */
final class ObjectSweep
{
    public const TABLE = 'lifecycle_object_deletions';

    /** @var callable(string, string): void */
    private $log;

    /** @var callable(int): void */
    private $sleep;

    /**
     * @param array<string, callable(array<string,mixed>): list<array{0:string,1:string}>> $collectors table => row → [[store, key], …]
     * @param array<string, callable(string, array<string,mixed>): void> $deleters store => delete one object
     * @param (callable(string, string): void)|null $log
     * @param (callable(int): void)|null $sleep seconds; tests pass a no-op
     */
    public function __construct(
        private PDO $db,
        private array $collectors,
        private array $deleters,
        ?callable $log = null,
        ?callable $sleep = null,
        private int $tries = 3,
    ) {
        $this->log = $log ?? static function (string $level, string $message): void {
            error_log('company-lifecycle [' . $level . ']: ' . $message);
        };
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /** The kit hook for 'company' or 'account' jobs. */
    public function hook(string $kind): callable
    {
        // Kit 1.1 hooks take a HookContext third argument; the sweep changes no product row (only its own
        // bookkeeping table), so it has nothing to archive with it.
        return fn (int|string $target, array $result, ?\Aicountly\Lifecycle\HookContext $ctx = null): ?string => $this->run($kind, (string) $target, $result);
    }

    /**
     * @param array<string,mixed> $result the PurgeEngine result (archive, counts)
     */
    public function run(string $kind, string $target, array $result): ?string
    {
        $archive = is_string($result['archive'] ?? null) ? (string) $result['archive'] : '';
        if ($archive !== '') {
            $this->collect($kind, $target, $archive, array_keys(is_array($result['counts'] ?? null) ? $result['counts'] : []));
        }

        $pending = $this->db->prepare('SELECT * FROM ' . self::TABLE . " WHERE target_kind = ? AND target_ref = ? AND status = 'pending' ORDER BY id");
        $pending->execute([$kind, $target]);
        $done = [];
        $left = [];
        foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $error = $this->deleteOne($row);
            if ($error === null) {
                $done[$row['store']] = ($done[$row['store']] ?? 0) + 1;
            } else {
                $left[] = $row['store'] . ' ' . self::redact((string) $row['object_key']) . ': ' . $error;
            }
        }

        if ($left !== []) {
            ($this->log)('error', count($left) . " stored object(s) of {$kind} {$target} not removed yet: " . implode('; ', array_slice($left, 0, 5)));

            throw new \RuntimeException(count($left) . ' stored object(s) could not be removed yet (' . implode('; ', array_slice($left, 0, 3))
                . (count($left) > 3 ? '; …' : '') . '); the rows are purged, the objects are retried. See ' . self::TABLE . '.');
        }
        if ($done === []) {
            return null;
        }
        ksort($done);

        return sprintf('Removed %d stored object(s) outside the database (%s).', array_sum($done),
            implode(', ', array_map(static fn (string $s, int $n): string => "{$s}: {$n}", array_keys($done), $done)));
    }

    /** @param list<string> $purgedTables "schema.table" names the purge archived */
    private function collect(string $kind, string $target, string $archive, array $purgedTables): void
    {
        if (preg_match('/^aic_lifecycle_archive_[cu][0-9]{1,19}_[0-9]{14}$/', $archive) !== 1) {
            return;
        }
        $insert = $this->db->prepare('INSERT INTO ' . self::TABLE . ' (target_kind, target_ref, archive_name, source_table, store, object_key)
            VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (target_kind, target_ref, store, object_key) DO NOTHING');
        foreach ($purgedTables as $qualified) {
            $table = str_contains($qualified, '.') ? explode('.', $qualified, 2)[1] : $qualified;
            $collector = $this->collectors[$table] ?? null;
            if ($collector === null || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $table) !== 1) {
                continue;
            }
            $rows = $this->db->query('SELECT * FROM "' . $archive . '"."' . $table . '"');
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach ($collector($row) as [$store, $key]) {
                    if ($store !== '' && $key !== '') {
                        $insert->execute([$kind, $target, $archive, $table, $store, $key]);
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function deleteOne(array $row): ?string
    {
        $deleter = $this->deleters[(string) $row['store']] ?? null;
        $error = null;
        for ($attempt = 1; $attempt <= max(1, $this->tries); $attempt++) {
            if ($deleter === null) {
                $error = 'no deleter for store ' . $row['store'];
                break;
            }
            try {
                $deleter((string) $row['object_key'], $row);
                $error = null;
                break;
            } catch (\Throwable $e) {
                $error = substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 300);
                if ($attempt < $this->tries) {
                    ($this->sleep)($attempt);
                }
            }
        }
        $update = $this->db->prepare('UPDATE ' . self::TABLE . ' SET attempts = attempts + ?, last_error = ?, status = ?, finished_at = CASE WHEN ? THEN now() ELSE finished_at END WHERE id = ?');
        $ok = $error === null;
        $update->execute([$deleter === null ? 0 : min($attempt, max(1, $this->tries)), $error, $ok ? 'deleted' : 'pending', $ok ? 'true' : 'false', (int) $row['id']]);
        ($this->log)($ok ? 'notice' : 'warning', ($ok ? 'removed ' : 'could not remove ') . $row['store'] . ' object ' . self::redact((string) $row['object_key'])
            . ' (' . $row['target_kind'] . ' ' . $row['target_ref'] . ')' . ($ok ? '' : ': ' . $error));

        return $error;
    }

    /** Object keys are not secrets, but keep log lines short. */
    private static function redact(string $key): string
    {
        return strlen($key) > 80 ? substr($key, 0, 77) . '…' : $key;
    }
}
