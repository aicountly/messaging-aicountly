<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * Runs the purges Manage asked for and tells Manage how each ended. Driven by bin/worker.php from
 * cron (every 5 minutes is plenty), never by the HTTP request that delivered the event.
 *
 * Per job (claimed with SKIP LOCKED; one abandoned by a dead worker is reclaimed after STALE_AFTER):
 *
 *   company_purge_requested
 *     1. refuse (ack failed, with the reason; not retried) when the company here is no longer
 *        purge_pending / purged — a newer signed event or Manage's companyinfo reversed it — or when
 *        Manage's internal/company-status (asked only if MANAGE_PRODUCT_SERVICE_KEY is set) now says
 *        active / restricted. Manage being unreachable does not hold the purge: the request was signed;
 *     2. PurgeEngine: archive, delete in manifest order, read back; statutory tables retained;
 *     3. company hooks (files, external indexes …), then the company is marked purged here.
 *   account_purge_requested
 *     AccountPurge: the manifest's user_tables, then account hooks.
 *
 * A failure is retried MAX_ATTEMPTS times (10, 20 … minutes apart), then acked failed — Manage blocks
 * that purge and alerts ops; after the fix, `bin/worker.php --requeue=<event_id>` runs it again and
 * Manage accepts the later purged. Every finished job's ack is then POSTed (AckClient), retried with
 * back-off until Manage takes it.
 *
 * 1.1:
 *   - legal hold: a product's legal_hold callable (Kit option) — fn (string $scope 'company'|'account',
 *     int|string $id): ?string — returning a reason stops the purge before anything is touched: the job
 *     becomes held, Manage is acked `held` with detail {reason}, and the job is looked at again every
 *     HOLD_RECHECK seconds; once the hook returns null the purge runs and Manage gets `purged`;
 *   - hooks get a HookContext third argument (archiveRows() before changing rows outside the engine,
 *     retain() for statutory data outside the manifest); before-hooks run ahead of the engine;
 *   - expireRetained() (bin/worker.php --expire-retained, daily): retention expiry of purged
 *     companies / people (PurgeEngine::expire()), skipping any under a legal hold.
 */
final class Worker
{
    public const MAX_ATTEMPTS = 3;

    public const STALE_AFTER = 3600;

    /** A held job is looked at again daily. */
    public const HOLD_RECHECK = 86400;

    /** @var (callable(string, int|string): ?string)|null */
    private $legalHold;

    /** @var callable(string, string): void */
    private $log;

    /** @var (callable(array<string,mixed>, string, string): void)|null */
    private $onFinish;

    /**
     * @param array{company?: list<callable(int|string, array<string,mixed>): ?string>, account?: list<callable(int|string, array<string,mixed>): ?string>} $hooks
     * @param (callable(string, string): void)|null $log
     * @param (callable(array<string,mixed> $job, string $status, string $detail): void)|null $onFinish e.g. the product's audit trail
     * @param (callable(string $scope, int|string $id): ?string)|null $legalHold a reason stops the purge (1.1)
     */
    public function __construct(
        private Config $config,
        private EventStore $store,
        private ?PurgeEngine $engine,
        private AckClient $ack,
        private array $hooks = [],
        ?callable $log = null,
        ?callable $onFinish = null,
        ?callable $legalHold = null,
    ) {
        $this->log = $log ?? [Kit::class, 'defaultLog'];
        $this->onFinish = $onFinish;
        $this->legalHold = $legalHold;
    }

    /**
     * @return array{purged:int, nothing_to_purge:int, failed:int, retrying:int, acks_sent:int, acks_retrying:int, acks_failed:int}
     */
    public function run(int $maxJobs = 5, int $maxAcks = 50): array
    {
        $summary = ['purged' => 0, 'nothing_to_purge' => 0, 'failed' => 0, 'retrying' => 0, 'acks_sent' => 0, 'acks_retrying' => 0, 'acks_failed' => 0];
        if ($this->legalHold !== null) {
            $summary['held'] = 0;
        }
        for ($i = 0; $i < max(0, $maxJobs); $i++) {
            $outcome = $this->processOne();
            if ($outcome === null) {
                break;
            }
            $summary[$outcome]++;
        }
        foreach ($this->sendDueAcks($maxAcks) as $r) {
            $summary['acks_' . $r]++;
        }

        return $summary;
    }

    /** Claim and run one job: purged | nothing_to_purge | failed | retrying | held, or null when none was due. */
    public function processOne(): ?string
    {
        $job = $this->store->claimJob(self::STALE_AFTER);
        if ($job === null) {
            return null;
        }
        $who = $job['kind'] === 'company' ? 'company ' . $job['cmp_id'] : 'person ' . $job['uuid'];
        ($this->log)('notice', "purge job {$job['id']}: {$who} (event {$job['event_id']}), attempt {$job['attempts']}");

        try {
            if ($this->engine === null) {
                return $this->failedAttempt($job, 'Not purged: no reviewed purge manifest is configured (LIFECYCLE_MANIFEST).');
            }
            if ($job['kind'] === 'company') {
                $refusal = $this->refusal((int) $job['cmp_id']);
                if ($refusal !== null) {
                    return $this->finish($job, ['status' => 'failed', 'detail' => 'Not purged: ' . $refusal, 'archive' => null, 'rows' => 0, 'retained' => []]);
                }
                $hold = $this->holdReason('company', (int) $job['cmp_id']);
                if ($hold !== null) {
                    return $this->hold($job, $hold);
                }
                $o = $this->purgeCompany((int) $job['cmp_id']);
                if ($o['ok']) {
                    $this->store->markPurged((int) $job['cmp_id']);
                }
            } else {
                $hold = $this->holdReason('account', (string) $job['uuid']);
                if ($hold !== null) {
                    return $this->hold($job, $hold);
                }
                $before = $this->hooks['account_before'] ?? [];
                $after = $this->hooks['account'] ?? [];
                $ctx = $before !== [] || $after !== [] ? $this->context('user', (string) $job['uuid']) : null;
                $o = (new AccountPurge($this->engine, $after, $before, $ctx))->run((string) $job['uuid']);
            }
            if (!$o['ok']) {
                return $this->failedAttempt($job, $o['detail'], $o);
            }

            return $this->finish($job, $o);
        } catch (\Throwable $e) {
            return $this->failedAttempt($job, 'The purge stopped with an error: ' . $e->getMessage());
        }
    }

    /**
     * Retention expiry (bin/worker.php --expire-retained, daily): for every company / person this
     * product purged, delete the retained rows whose period has passed (PurgeEngine::expire()). A
     * target under a legal hold is skipped. Manage is not told. Needs schema-1.1.sql.
     *
     * @return array{targets:int, expired_rows:int, orphan_rows:int, held:int, failed:int, expired:list<array<string,mixed>>, errors:list<string>}
     */
    public function expireRetained(bool $dryRun = false, int $limit = 500): array
    {
        $out = ['targets' => 0, 'expired_rows' => 0, 'orphan_rows' => 0, 'held' => 0, 'failed' => 0, 'expired' => [], 'errors' => []];
        if ($this->engine === null) {
            throw new \RuntimeException('no reviewed purge manifest is configured (LIFECYCLE_MANIFEST)');
        }
        if (!$this->store->hasSchema11()) {
            throw new \RuntimeException('aic_lifecycle_expiries is missing: apply schema-1.1.sql (kit 1.1) first');
        }
        foreach (array_slice($this->store->purgedTargets(), 0, max(1, $limit)) as [$scope, $id]) {
            if ($this->holdReason($scope === 'company' ? 'company' : 'account', $id) !== null) {
                $out['held']++;
                continue;
            }
            $r = $this->engine->expire($scope, $id, null, $dryRun);
            if (!$r['ok']) {
                $out['failed']++;
                $out['errors'][] = "{$scope} {$id}: " . $r['error'];
                ($this->log)('error', "retention expiry of {$scope} {$id} failed: {$r['error']}");
                continue;
            }
            if (in_array($r['status'], ['expired', 'dry_run'], true)) {
                $out['targets']++;
                $out['expired_rows'] += array_sum($r['expired']);
                $out['orphan_rows'] += array_sum($r['orphans']);
                $out['expired'][] = ['scope' => $scope, 'target' => $id, 'expired' => $r['expired'], 'orphans' => $r['orphans'], 'archive' => $r['archive']];
                ($this->log)('notice', sprintf('retention expiry %s %s: %d retained row(s) past their period%s%s', $scope, $id, array_sum($r['expired']),
                    $r['orphans'] !== [] ? ', ' . array_sum($r['orphans']) . ' no longer referenced' : '', $dryRun ? ' (dry run)' : ' archived into ' . $r['archive'] . ' and removed'));
            }
        }

        return $out;
    }

    /**
     * POST every ack that is due. One result per ack: sent | retrying | failed.
     *
     * @return list<string>
     */
    public function sendDueAcks(int $limit = 50): array
    {
        $results = [];
        foreach ($this->store->dueAcks($limit) as $row) {
            $answer = $this->ack->send($row['body']);
            $attempt = $row['attempts'] + 1;
            if ($answer['ok']) {
                $this->store->recordAck($row['id'], true, $answer['http'], null, null);
                ($this->log)('notice', "ack {$row['event_id']} sent to Manage");
                $results[] = 'sent';
                continue;
            }
            $giveUp = $answer['permanent'] || $attempt >= AckClient::MAX_ATTEMPTS;
            $this->store->recordAck($row['id'], false, $answer['http'], $answer['error'], $giveUp ? null : AckClient::delay($attempt));
            ($this->log)($giveUp ? 'error' : 'warning', "ack {$row['event_id']} not delivered (attempt {$attempt}): {$answer['error']}" . ($giveUp ? ' — giving up; see bin/worker.php --status' : ', will retry'));
            $results[] = $giveUp ? 'failed' : 'retrying';
        }

        return $results;
    }

    /**
     * Turn a PurgeEngine result (plus hooks) into the job's outcome.
     *
     * @param array<string,mixed> $r
     * @param list<callable(int|string, array<string,mixed>, HookContext=): ?string> $hooks
     * @param HookContext|null $ctx passed to the hooks as their third argument (1.1)
     * @return array{ok:bool, status:string, detail:string, archive:?string, rows:int, retained:list<string>}
     */
    public static function outcome(array $r, string $noun, array $hooks, int|string $target, ?HookContext $ctx = null): array
    {
        $retained = array_keys($r['retained'] ?? []);
        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'status' => 'failed', 'detail' => (string) ($r['error'] ?? 'The purge failed.'), 'archive' => null, 'rows' => 0, 'retained' => $retained];
        }
        $total = (int) ($r['total'] ?? 0);
        $lines = [];
        if ($total > 0) {
            $lines[] = sprintf('%d row(s) from %d table(s) archived into schema %s and removed.', $total, count($r['counts'] ?? []), (string) $r['archive']);
        }
        if (($r['scrubbed'] ?? []) !== []) {
            $lines[] = sprintf('Personal fields scrubbed in %d retained row(s) (%s).', array_sum($r['scrubbed']), implode(', ', array_keys($r['scrubbed'])));
        }
        $ctx?->phase('after');
        foreach ($hooks as $hook) {
            $line = $ctx === null ? $hook($target, $r) : $hook($target, $r, $ctx);
            if (is_string($line) && trim($line) !== '') {
                $lines[] = trim($line);
            }
        }
        $archive = $r['archive'] ?? null;
        if ($ctx !== null && $ctx->archive() !== null) {
            $archive ??= $ctx->archive();
            if ($ctx->archivedRows() > 0) {
                $lines[] = sprintf('%d row(s) the product changed outside the manifest archived first into schema %s.', $ctx->archivedRows(), $ctx->archive());
            }
        }
        $purged = $lines !== [];
        if (!$purged) {
            $lines[] = 'This application holds no data for this ' . $noun . '.';
        }
        $parts = array_map(
            static fn (string $t): string => $t . ' (' . (int) $r['retained'][$t] . ' row(s)) — ' . (string) ($r['reasons'][$t] ?? ''),
            $retained,
        );
        foreach ($ctx?->retained() ?? [] as $name => $k) {
            if (!in_array($name, $retained, true)) {
                $retained[] = $name;
            }
            $parts[] = $name . ($k['rows'] > 0 ? ' (' . $k['rows'] . ' item(s))' : '') . ' — ' . $k['reason'];
        }
        if ($parts !== []) {
            $lines[] = 'Retained by statute: ' . implode('; ', $parts) . '.';
        }

        return [
            'ok'       => true,
            'status'   => $purged ? 'purged' : 'nothing_to_purge',
            'detail'   => implode(' ', $lines),
            'archive'  => $archive,
            'rows'     => $total,
            'retained' => $retained,
        ];
    }

    /** company purge: before-hooks, the engine (into the hooks' archive), after-hooks. */
    private function purgeCompany(int $cmpId): array
    {
        $before = $this->hooks['company_before'] ?? [];
        $after = $this->hooks['company'] ?? [];
        if ($before === [] && $after === []) {
            return self::outcome($this->engine->purge('company', $cmpId), 'company', [], $cmpId);
        }
        $ctx = $this->context('company', (string) $cmpId);
        $ctx->phase('before');
        foreach ($before as $hook) {
            $hook($cmpId, [], $ctx);
        }

        return self::outcome($this->engine->purge('company', $cmpId, false, $ctx->archiveName), 'company', $after, $cmpId, $ctx);
    }

    private function context(string $scope, string $target): HookContext
    {
        return new HookContext($this->engine, $scope, $target, PurgeEngine::archiveName($scope, $target, $this->store->now()));
    }

    private function holdReason(string $scope, int|string $id): ?string
    {
        if ($this->legalHold === null) {
            return null;
        }
        $reason = ($this->legalHold)($scope, $id);

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    /**
     * A legal hold stops the purge: the job waits as held, Manage is told once (ack held, detail
     * {reason}), and the job is looked at again in HOLD_RECHECK seconds.
     *
     * @param array{id:int, event_id:string, kind:string, cmp_id:?int, uuid:?string, attempts:int} $job
     */
    private function hold(array $job, string $reason): string
    {
        $told = $this->store->lastAckStatus($job['id']) === 'held';
        $detail = 'Not purged: legal hold — ' . $reason;
        $body = $told ? null : AckClient::body($job, $this->config->appKey, 'held', [], $reason);
        $this->store->holdJob($job['id'], $detail, $this->store->now() + self::HOLD_RECHECK, $job['event_id'], $body);
        ($this->log)('notice', "purge job {$job['id']} (event {$job['event_id']}) held: {$reason}" . ($told ? ' (still held; Manage already told)' : ''));
        if (!$told && $this->onFinish !== null) {
            try {
                ($this->onFinish)($job, 'held', $detail);
            } catch (\Throwable $e) {
                ($this->log)('warning', 'onFinish hook failed: ' . $e->getMessage());
            }
        }

        return 'held';
    }

    /** Why this company purge must not run now, or null when it may. */
    private function refusal(int $cmpId): ?string
    {
        $state = $this->store->state($cmpId)['state'] ?? null;
        if (!in_array($state, ['purge_pending', 'purged'], true)) {
            return sprintf('the company is %s here, not awaiting a purge — a newer lifecycle event or Manage\'s companyinfo superseded the request.', $state ?? 'active');
        }
        $manage = $this->ack->companyLifecycle($cmpId);
        if ($manage['known'] && in_array($manage['lifecycle'], ['active', 'restricted'], true)) {
            return sprintf('Manage now reports the company as %s (internal/company-status), not purge_pending.', $manage['lifecycle']);
        }
        if (!$manage['known'] && $this->config->serviceKey !== null) {
            ($this->log)('warning', "Manage could not confirm the lifecycle of cmp={$cmpId} ({$manage['error']}); proceeding on the signed request");
        }

        return null;
    }

    /**
     * @param array{id:int, event_id:string, kind:string, cmp_id:?int, uuid:?string, attempts:int} $job
     * @param array<string,mixed>|null $o
     */
    private function failedAttempt(array $job, string $detail, ?array $o = null): string
    {
        if ($job['attempts'] < self::MAX_ATTEMPTS) {
            $delay = 600 * $job['attempts'];
            $this->store->retryJob($job['id'], $delay, $detail);
            ($this->log)('warning', "purge job {$job['id']} failed (attempt {$job['attempts']} of " . self::MAX_ATTEMPTS . "), retry in {$delay} s: {$detail}");

            return 'retrying';
        }

        return $this->finish($job, ['status' => 'failed', 'detail' => $detail, 'archive' => null, 'rows' => 0, 'retained' => $o['retained'] ?? []]);
    }

    /**
     * @param array{id:int, event_id:string, kind:string, cmp_id:?int, uuid:?string, attempts:int} $job
     * @param array{status:string, detail:string, archive:?string, rows:int, retained:list<string>} $o
     */
    private function finish(array $job, array $o): string
    {
        $body = AckClient::body($job, $this->config->appKey, $o['status'], $o['retained'], $o['detail']);
        $this->store->finishJob($job['id'], $o['status'], $o['detail'], $o['archive'], $o['rows'], $o['retained'], $job['event_id'], $body);
        ($this->log)($o['status'] === 'failed' ? 'error' : 'notice', "purge job {$job['id']} (event {$job['event_id']}) {$o['status']}: {$o['detail']}");
        if ($this->onFinish !== null) {
            try {
                ($this->onFinish)($job, $o['status'], $o['detail']);
            } catch (\Throwable $e) {
                ($this->log)('warning', 'onFinish hook failed: ' . $e->getMessage());
            }
        }

        return $o['status'];
    }
}
