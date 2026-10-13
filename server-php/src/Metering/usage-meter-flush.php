<?php
declare(strict_types=1);

/**
 * Drain this product's usage spool to Manage. Run from cron every minute (and by hand after an
 * outage); it is safe to run while web requests are also flushing — one holds the spool lock.
 *
 *   php usage-meter-flush.php [--env=/path/to/api/.env] [--max=2000] [--status]
 *
 * Reads the same AICOUNTLY_METERING_* settings as the product (from the environment, or from the
 * .env file given with --env: KEY=VALUE lines, # comments). Exit 0 when nothing is left that is due,
 * 1 when Manage could not take everything (the rest stays spooled), 2 on a configuration problem.
 *
 * COPY, DON'T EDIT — source: manage-aicountly "Saas Common Handler/usage-metering/php/".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/AicountlyUsageMeter.php';

use Aicountly\Metering\UsageMeter;

$opts = getopt('', ['env:', 'max:', 'status']);
$file = is_string($opts['env'] ?? null) ? $opts['env'] : '';
$vars = [];
if ($file !== '') {
    if (!is_readable($file)) {
        fwrite(STDERR, "Cannot read {$file}\n");
        exit(2);
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $vars[$k] = trim($v, "\"'");
    }
}
$env = static function (string $k) use ($vars): ?string {
    $v = getenv($k);
    if ($v !== false && $v !== '') {
        return $v;
    }

    return $vars[$k] ?? null;
};

$meter = UsageMeter::fromEnv($env);
if (isset($opts['status'])) {
    echo json_encode($meter->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if (!$meter->enabled()) {
    echo "Metering is disabled (AICOUNTLY_METERING_ENABLED is not 1); nothing to do.\n";
    exit(0);
}
$result = $meter->flush(max(1, (int) ($opts['max'] ?? 2000)), 20);
echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
if ($result['error'] !== null && str_contains($result['error'], 'not configured')) {
    exit(2);
}
exit($result['error'] === null ? 0 : 1);
