<?php
declare(strict_types=1);

/*
 * Drains the analytics spool. Copy unchanged beside AicountlyAnalytics.php.
 *
 *   * * * * * php /path/to/analytics-flush.php --env=/path/to/api/.env >/dev/null 2>&1
 *
 *   php analytics-flush.php --status --env=…   pending / dead-letter counts, for monitoring
 *
 * The after-response flush in the SDK delivers almost everything; this catches what it could not
 * (Analytics briefly down, a CLI process, a request that died before shutdown).
 */

require_once __DIR__ . '/AicountlyAnalytics.php';

use Aicountly\Analytics\Analytics;

$args   = array_slice($argv, 1);
$status = in_array('--status', $args, true);

foreach ($args as $arg) {
    if (!str_starts_with($arg, '--env=')) {
        continue;
    }
    $file = substr($arg, 6);
    if (!is_file($file) || !is_readable($file)) {
        fwrite(STDERR, "analytics-flush: cannot read {$file}\n");
        exit(2);
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (preg_match('/^(["\'])(.*)\1$/', $v, $m)) {
            $v = $m[2];
        }
        if (str_starts_with($k, 'AICOUNTLY_ANALYTICS_')) {
            putenv("{$k}={$v}");
        }
    }
}

$client = Analytics::fromEnv();

if ($status) {
    echo json_encode($client->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if (!$client->enabled()) {
    exit(0);
}

$out = $client->flush(2000, 20);

// Only say something when there is something to say: a cron line that prints on every run is a
// cron line whose output nobody reads.
if ($out['dead'] > 0 || $out['error'] !== null) {
    fwrite(STDERR, 'analytics-flush: ' . json_encode($out, JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
exit(0);
