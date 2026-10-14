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
 */
final class Worker
{
    public const MAX_ATTEMPTS = 3;

    public const STALE_AFTER = 3600;

    /** @var callable(string, string): void */
    private $log;

    /** @var (callable(array<string,mixed>, string, string): void)|null */
    private $onFinish;

    /**
     * @param array{company?: list<callable(int|string, array<string,mixed>): ?string>, account?: list<callable(int|string, array<string,mixed>): ?string>} $hooks
     * @param (callable(string, string): void)|null $log
     * @param (callable(array<string,mixed> $job, string $status, string $detail): void)|null $onFinish e.g. the product's audit trail
     */
    public function __construct(
        private Config $config,
        private EventStore $store,
        private ?PurgeEngine $engine,
        private AckClient $ack,
        private array $hooks = [],
        ?callable $log = null,
        ?callable $onFinish = null,
    ) {
        $this->log = $log ?? [Kit::class, 'defaultLog'];
        $this->onFinish = $onFinish;
    }

    /**
     * @return array{purged:int, nothing_to_purge:int, failed:int, retrying:int, acks_sent:int, acks_retrying:int, acks_failed:int}
     */
    public function run(int $maxJobs = 5, int $maxAcks = 50): array
    {
        $summary = ['purged' => 0, 'nothing_to_purge' => 0, 'failed' => 0, 'retrying' => 0, 'acks_sent' => 0, 'acks_retrying' => 0, 'acks_failed' => 0];
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

    /** Claim and run one job: purged | nothing_to_purge | failed | retrying, or null when none was due. */
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
                $o = self::outcome($this->engine->purge('company', (int) $job['cmp_id']), 'company', $this->hooks['company'] ?? [], (int) $job['cmp_id']);
                if ($o['ok']) {
                    $this->store->markPurged((int) $job['cmp_id']);
                }
            } else {
                $o = (new AccountPurge($this->engine, $this->hooks['account'] ?? []))->run((string) $job['uuid']);
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
     * @param list<callable(int|string, array<string,mixed>): ?string> $hooks
     * @return array{ok:bool, status:string, detail:string, archive:?string, rows:int, retained:list<string>}
     */
    public static function outcome(array $r, string $noun, array $hooks, int|string $target): array
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
        foreach ($hooks as $hook) {
            $line = $hook($target, $r);
            if (is_string($line) && trim($line) !== '') {
                $lines[] = trim($line);
            }
        }
        $purged = $lines !== [];
        if (!$purged) {
            $lines[] = 'This application holds no data for this ' . $noun . '.';
        }
        if ($retained !== []) {
            $lines[] = 'Retained by statute: ' . implode('; ', array_map(
                static fn (string $t): string => $t . ' (' . (int) $r['retained'][$t] . ' row(s)) — ' . (string) ($r['reasons'][$t] ?? ''),
                $retained,
            )) . '.';
        }

        return [
            'ok'       => true,
            'status'   => $purged ? 'purged' : 'nothing_to_purge',
            'detail'   => implode(' ', $lines),
            'archive'  => $r['archive'] ?? null,
            'rows'     => $total,
            'retained' => $retained,
        ];
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
