<?php

declare(strict_types=1);

/**
 * Every environment variable the server code reads by name is listed in server-php/.env.example
 * (set, or as a commented-out example), so a deployment is not missing one nobody wrote down.
 *
 * Scans src/, bin/, config/ and index.php for Env::get('NAME') and getenv('NAME'); the vendored
 * copy-don't-edit kits are their owners' to document. Names built at run time
 * (MESSAGING_<FLAG>_ENABLED) are documented as a pattern and are not checked here.
 *
 *   php server-php/tests/env_example.php
 */

$root = dirname(__DIR__);
// Read for another tool (libpq's password), not as this product's configuration.
const NOT_CONFIGURATION = ['PGPASSWORD'];
const VENDORED = ['/src/EstateAnalytics/', '/src/Clients/vendor-contacts-client/', '/src/Metering/AicountlyUsageMeter.php', '/src/Metering/usage-meter-flush.php'];

$paths = [$root . '/index.php'];
foreach (['src', 'bin', 'config'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!str_ends_with($path, '.php')) {
            continue;
        }
        foreach (VENDORED as $skip) {
            if (str_contains($path, $skip)) {
                continue 2;
            }
        }
        $paths[] = $path;
    }
}

$read = [];
foreach ($paths as $path) {
    $source = (string) file_get_contents($path);
    // A literal name, closed by its quote: 'MESSAGING_' . $flag is a pattern, not a name.
    if (preg_match_all("/(?:Env::get|getenv|\\\$env)\\(\\s*'([A-Z][A-Z0-9_]*[A-Z0-9])'\\s*[,)]/", $source, $m) > 0) {
        foreach ($m[1] as $name) {
            $read[$name][] = substr($path, strlen($root) + 1);
        }
    }
}

$documented = [];
foreach (file($root . '/.env.example', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]+)=/', $line, $m) === 1) {
        $documented[$m[1]] = true;
    }
}

$missing = [];
foreach ($read as $name => $where) {
    if (!isset($documented[$name]) && !in_array($name, NOT_CONFIGURATION, true)) {
        $missing[] = $name . ' (read in ' . implode(', ', array_unique($where)) . ')';
    }
}
sort($missing);

echo "server-php/.env.example\n";
if ($missing === []) {
    echo '  ok    every variable the server reads is in .env.example (' . count($read) . " names)\n";
    echo "\n============================================================\n1 passed, 0 failed\n";
    exit(0);
}
echo "  FAIL  every variable the server reads is in .env.example\n";
foreach ($missing as $line) {
    echo "        missing: {$line}\n";
}
echo "\n============================================================\n0 passed, 1 failed\n";
exit(1);
