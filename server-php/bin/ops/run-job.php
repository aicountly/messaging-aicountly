<?php

declare(strict_types=1);

/**
 * AICOUNTLY ops kit — run one scheduled job and leave a heartbeat (E8-OPS: F160, F162, F022, F142).
 *
 * CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/run-job.php (identical where a product uses it,
 * at server-php/bin/ops/run-job.php). For products whose commands do not report a heartbeat themselves
 * (Books, Pulse), bin/ops/jobs.txt runs each job through this wrapper:
 *
 *   php bin/ops/run-job.php --name=inventory-retry --every=300 [--monitor=books.inventory_retry] \
 *       [--state-dir=writable/ops] [--env-file=.env] -- php spark books:inventory-retry --limit 50
 *
 * It runs the command (output passed through), then writes <state-dir>/<name>.json —
 *   {name, every, last_started_at, last_finished_at, last_exit, last_duration_ms, runs, last_ok_at}
 * — which the product's health reads (aic_ops_job_states()), and, when --monitor is given and the .env
 * holds CONSOLE_CRON_MONITOR_KEY or CONSOLE_SERVICE_KEY, reports started/ok/error to Console's Cron Job
 * Monitor (heartbeat-lib.php). Exit code: the command's own. Counts only; nothing tenant-specific.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/heartbeat-lib.php';

$sep = array_search('--', $argv, true);
if ($sep === false || $sep === count($argv) - 1) {
    fwrite(STDERR, "usage: php bin/ops/run-job.php --name=<job> --every=<seconds> [--monitor=<code>] [--state-dir=DIR] [--env-file=FILE] -- <command...>\n");
    exit(2);
}
$opts = [];
foreach (array_slice($argv, 1, $sep - 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) === 1) {
        $opts[$m[1]] = $m[2];
    }
}
$cmd = array_slice($argv, $sep + 1);
$name = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($opts['name'] ?? ''))) ?? '';
$every = max(60, (int) ($opts['every'] ?? 300));
$apiDir = dirname(__DIR__, 2);
$stateDir = (string) ($opts['state-dir'] ?? $apiDir . '/writable/ops');
if ($stateDir[0] !== '/') {
    $stateDir = $apiDir . '/' . $stateDir;
}
$envFile = (string) ($opts['env-file'] ?? $apiDir . '/.env');
if ($envFile[0] !== '/') {
    $envFile = $apiDir . '/' . $envFile;
}
if ($name === '') {
    fwrite(STDERR, "run-job: --name is required\n");
    exit(2);
}

$env = aic_ops_env_reader($envFile);
$monitor = (string) ($opts['monitor'] ?? '');
$runId = aic_ops_run_id($monitor !== '' ? $monitor : $name);
$started = gmdate('c');
$t0 = microtime(true);
if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0750, true);
}
$statePath = $stateDir . '/' . $name . '.json';
$state = is_file($statePath) ? (json_decode((string) file_get_contents($statePath), true) ?: []) : [];
$state = array_merge($state, ['name' => $name, 'every' => $every, 'last_started_at' => $started]);
@file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
if ($monitor !== '') {
    aic_ops_heartbeat($env, $monitor, $runId, 'started', $started, null);
}

$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $apiDir);
$code = is_resource($proc) ? proc_close($proc) : 127;

$finished = gmdate('c');
$state['last_finished_at'] = $finished;
$state['last_exit'] = $code;
$state['last_duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
$state['runs'] = (int) ($state['runs'] ?? 0) + 1;
if ($code === 0) {
    $state['last_ok_at'] = $finished;
}
@file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
if ($monitor !== '') {
    aic_ops_heartbeat($env, $monitor, $runId, $code === 0 ? 'ok' : 'error', $started, $finished,
        $code === 0 ? '' : $name . ' exited ' . $code, ['exit' => $code, 'duration_ms' => $state['last_duration_ms']]);
}
exit($code);

/** A reader for KEY=VALUE lines of a .env file (quotes stripped); process env wins. */
function aic_ops_env_reader(string $file): callable
{
    $vals = [];
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m) === 1) {
                $v = trim($m[2]);
                if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
                    $v = substr($v, 1, -1);
                }
                $vals[$m[1]] = $v;
            }
        }
    }

    return static fn (string $k): string => (string) (getenv($k) ?: ($vals[$k] ?? ''));
}
