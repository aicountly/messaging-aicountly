<?php

declare(strict_types=1);

/**
 * The dispatch worker.
 *
 *   php server-php/bin/dispatch-worker.php             one pass, then exit
 *   php server-php/bin/dispatch-worker.php --loop      keep going (for a supervisor)
 *   php server-php/bin/dispatch-worker.php --batch=25  claim up to 25 per pass
 *
 * ## Safe to run more than once
 *
 * Claiming uses `FOR UPDATE SKIP LOCKED`, so two workers take different rows
 * and neither waits. The unique index on `messaging_dispatch_jobs.message_uuid`
 * means one message can only ever have one job, so a duplicate queue read
 * cannot become a duplicate send.
 *
 * ## The gates run HERE, not at enqueue time
 *
 * Consent is re-read, the approval hash re-compared and any external fact
 * re-fetched immediately before the provider call. A message queued an hour ago
 * whose customer has since opted out does not send. See Domain/DispatchGuard.
 *
 * ## Designed to be killed
 *
 * A worker that dies mid-pass leaves its claimed jobs claimed. `--requeue-stale`
 * returns them to the queue after a timeout, which is the recovery path a
 * supervisor restart relies on. It is deliberately a separate flag: silently
 * requeuing on every start would re-run a job another live worker is still
 * processing.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\DispatchService;

$args = array_slice($argv, 1);
$loop = in_array('--loop', $args, true);
$requeueStale = in_array('--requeue-stale', $args, true);
$batch = 10;
$sleepSeconds = 2;

foreach ($args as $arg) {
    if (preg_match('/^--batch=(\d+)$/', $arg, $m) === 1) {
        $batch = max(1, min(100, (int) $m[1]));
    }
    if (preg_match('/^--sleep=(\d+)$/', $arg, $m) === 1) {
        $sleepSeconds = max(1, min(60, (int) $m[1]));
    }
}

$workerId = gethostname() . ':' . getmypid();

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the database: {$e->getMessage()}\n");
    exit(1);
}

if ($requeueStale) {
    // A claim older than 15 minutes belongs to a worker that is gone.
    $requeued = Db::run(
        "UPDATE messaging_dispatch_jobs
         SET status = 'queued', claimed_at = NULL, claimed_by = NULL,
             last_error_detail = 'Requeued after a worker was lost.'
         WHERE status = 'claimed' AND claimed_at < NOW() - INTERVAL '15 minutes'",
    )->rowCount();
    echo "requeued {$requeued} stale job(s)\n";
}

// A clean exit on SIGTERM, so a supervisor restart does not kill a job
// mid-provider-call and leave a message in `dispatching`.
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        echo "SIGTERM — finishing the current pass and stopping.\n";
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

$processed = 0;

do {
    $jobs = DispatchService::claim($workerId, $batch);

    foreach ($jobs as $job) {
        try {
            $result = DispatchService::process($job);
            $processed++;
            printf(
                "%s  job=%s message=%s outcome=%s  %s\n",
                gmdate('c'),
                substr((string) $job['job_uuid'], 0, 8),
                substr((string) $job['message_uuid'], 0, 8),
                $result['outcome'],
                $result['detail'],
            );
        } catch (\Throwable $e) {
            // One bad job must not stop the pass. It stays claimed and
            // --requeue-stale brings it back.
            error_log('[dispatch-worker] job ' . (string) $job['job_uuid'] . ' threw: ' . $e->getMessage());
            fwrite(STDERR, "FAILED job={$job['job_uuid']}: {$e->getMessage()}\n");
        }
    }

    if (!$loop) {
        break;
    }

    if ($jobs === []) {
        sleep($sleepSeconds);
    }
} while ($running);

echo "processed {$processed} job(s)\n";
