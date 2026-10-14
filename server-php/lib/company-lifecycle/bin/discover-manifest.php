#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Print a DRAFT purge manifest for a person to review (never used at runtime while draft: true).
 *
 *   php bin/discover-manifest.php --dsn="pgsql:host=127.0.0.1;port=5432;dbname=books" --user=books --app=books \
 *       [--schema=public] [--password=… | PGPASSWORD=…] [--out=lifecycle-manifest.draft.php]
 *
 * Run it against a copy of production's schema (a fresh migrate is fine: only the structure is read).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/autoload.php';

$o = getopt('', ['dsn:', 'schema::', 'user::', 'password::', 'app:', 'out::', 'help']);
if (isset($o['help']) || !isset($o['dsn'], $o['app'])) {
    fwrite(STDERR, "usage: php bin/discover-manifest.php --dsn=pgsql:… --app=<catalog id> [--schema=public] [--user=…] [--password=…] [--out=file]\n");
    exit(2);
}
$password = $o['password'] ?? (getenv('PGPASSWORD') ?: null);
try {
    $pdo = new PDO((string) $o['dsn'], isset($o['user']) ? (string) $o['user'] : null, $password !== null ? (string) $password : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, 'cannot connect: ' . $e->getMessage() . "\n");
    exit(1);
}
$schema = (string) ($o['schema'] ?? 'public');
$draft = (new Aicountly\Lifecycle\ManifestDiscovery($pdo))->draft((string) $o['app'], $schema);
$src = Aicountly\Lifecycle\ManifestDiscovery::render($draft, 'schema ' . $schema);
if (isset($o['out']) && $o['out'] !== false && $o['out'] !== '') {
    file_put_contents((string) $o['out'], $src);
    fwrite(STDERR, sprintf("draft written to %s: %d company, %d retain?, %d user, %d to look at\n", $o['out'],
        count($draft['manifest']['company_tables']), count($draft['manifest']['company_retain']), count($draft['manifest']['user_tables']), count($draft['manifest']['ignore'])));
} else {
    echo $src;
}
exit(0);
