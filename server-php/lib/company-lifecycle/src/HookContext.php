<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

use PDO;

/**
 * The third argument every purge hook receives (kit 1.1):
 *
 *   static function (int|string $target, array $result, HookContext $ctx): ?string
 *
 * A hook that anonymises or deletes outside the manifest (shared rows kept as "Deleted user",
 * one-to-one messages, rows the manifest cannot select) archives the rows FIRST:
 *
 *   $ctx->archiveRows('chat_messages', 'sender_uuid = ? AND conversation_id IN (…)', [$uuid]);
 *   $db->prepare('UPDATE chat_messages SET sender_uuid = NULL WHERE sender_uuid = ?')->execute([$uuid]);
 *
 * They go into the purge's own archive schema (created when the engine archived nothing), scrubbed by
 * the manifest's scrub rules, and `bin/worker.php --restore=<schema>` puts them back over the changed
 * rows by primary key (the table needs one). Rows already archived there are not copied twice — the
 * first copy is the original.
 *
 * A hook that keeps something by statute outside the database tables the manifest lists (a document
 * store, a workspace collection) reports it so Manage's ack carries it in retained[]:
 *
 *   $ctx->retain('workspace:statutory_registers', 'Companies Act 2013 s.88: registers kept permanently', 12);
 *
 * Before-hooks (Kit option hooks.company_before / hooks.account_before) run before the engine's purge
 * with an empty $result; after-hooks (hooks.company / hooks.account) after it, with its result.
 */
final class HookContext
{
    /** @var array<string, array{rows:int, reason:string}> */
    private array $retained = [];

    private int $archived = 0;

    private bool $used = false;

    private int $calls = 0;

    private int $seqBase = PurgeEngine::SEQ_HOOK_AFTER;

    public function __construct(
        private PurgeEngine $engine,
        public readonly string $scope,
        public readonly string $target,
        public readonly string $archiveName,
    ) {
    }

    /** @internal the Worker switches between before- and after-hooks */
    public function phase(string $phase): void
    {
        $this->seqBase = $phase === 'before' ? PurgeEngine::SEQ_HOOK_BEFORE : PurgeEngine::SEQ_HOOK_AFTER;
    }

    public function pdo(): PDO
    {
        return $this->engine->pdo();
    }

    /**
     * Copy the rows of $table matching $whereSql (with "?" placeholders bound to $params) into the
     * purge's archive. Call it before changing or deleting them. Returns how many rows were copied.
     *
     * @param list<mixed> $params
     */
    public function archiveRows(string $table, string $whereSql, array $params = []): int
    {
        $n = $this->engine->archiveRowsInto($this->archiveName, $this->scope, $this->target, $table, $whereSql, $params, $this->seqBase + $this->calls++);
        $this->archived += $n;
        $this->used = true;

        return $n;
    }

    /** Report something kept by statute outside the manifest's tables (ack retained[] + detail). */
    public function retain(string $name, string $reason, int $rows = 0): void
    {
        $name = trim($name);
        $reason = trim($reason);
        if ($name === '' || $reason === '') {
            throw new \InvalidArgumentException('retain() needs a name and the legal reason');
        }
        $prev = $this->retained[$name]['rows'] ?? 0;
        $this->retained[$name] = ['rows' => $prev + max(0, $rows), 'reason' => $reason];
    }

    /** @return array<string, array{rows:int, reason:string}> */
    public function retained(): array
    {
        return $this->retained;
    }

    /** The archive schema, once a hook archived into it. */
    public function archive(): ?string
    {
        return $this->used ? $this->archiveName : null;
    }

    public function archivedRows(): int
    {
        return $this->archived;
    }
}
