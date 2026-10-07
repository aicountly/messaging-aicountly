<?php

declare(strict_types=1);

/**
 * Where does Messaging's database connection come from, and does it connect?
 *
 *   php server-php/bin/db-check.php
 *
 * Run it on the server, from the api/ folder, after a deploy or whenever /api/health or any
 * endpoint answers 503 database_unavailable. It does what the API does (Db::connect()), one
 * step at a time, and says which step failed and why. The split is Connect's:
 *
 *   - the database NAME and USERNAME come from Console (GET /database-details/resolve with
 *     CONSOLE_DB_DETAILS_KEY), asked right now with the cache bypassed;
 *   - the HOST, PORT, PASSWORD (and the optional SSL mode) come from this server's .env.
 *
 * Nothing secret is printed: not CONSOLE_DB_DETAILS_KEY, and not the database password (only
 * where it comes from). Every failure prints a REASON and what to do about it (a Console or configuration
 * failure is reported by the same reason in /api/health's database.reason). Exit code 0 when the database is reachable AND fully
 * migrated, 1 when it is not: a reachable database whose schema was never applied still answers
 * every request with 503 database_unavailable.
 */

namespace Aicountly\Api;

// A worker, never a web page. .htaccess already refuses bin/ over HTTP; this holds on a server that
// ignores .htaccess.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Environment.php';
require __DIR__ . '/../src/ConsoleDatabaseDetails.php';
require __DIR__ . '/../src/Db.php';

Env::load(__DIR__ . '/../.env');

$say = static function (string $label, string $value): void {
    echo sprintf("  %-26s %s\n", $label, $value);
};

echo "Messaging database check\n";
$say('environment', Environment::describe() . ' (AIC_ENVIRONMENT / APP_ENV)');

if (ConsoleDatabaseDetails::isConfigured()) {
    $say('Console', 'configured (CONSOLE_API_URL and CONSOLE_DB_DETAILS_KEY are set)');

    try {
        // Fresh: forget the cache, so this is what the next request after the cache expires will see.
        $identity = ConsoleDatabaseDetails::refresh();
    } catch (\PDOException $e) {
        $say('Console answered', 'NO');
        $diagnosis = DatabaseDiagnosis::classify($e);
        echo "\nFAILED at Console: {$e->getMessage()}\n";
        echo "Reason: {$diagnosis['reason']}. {$diagnosis['hint']}\n";
        echo "Every request answers 503 database_unavailable until this is fixed.\n";
        exit(1);
    }

    $say('Console answered', 'yes (cache bypassed)');
    $say('  database name / user', $identity['name'] . ' / ' . $identity['user']);
} else {
    // Console is a pair of settings: say which half is missing, and name the usual mistake, because with
    // only one of them Console is silently never asked and DB_NAME / DB_USER decide.
    $missing = array_values(array_filter([
        Env::get('CONSOLE_API_URL') === '' ? 'CONSOLE_API_URL' : null,
        Env::get('CONSOLE_DB_DETAILS_KEY') === '' ? 'CONSOLE_DB_DETAILS_KEY' : null,
    ]));
    $say('Console', 'NOT used: ' . implode(' and ', $missing) . ' not set, so DB_NAME / DB_USER are used');
    if (in_array('CONSOLE_DB_DETAILS_KEY', $missing, true) && Env::get('CONSOLE_SERVICE_KEY') !== '') {
        $say('  CONSOLE_SERVICE_KEY', 'is set, but it is not used for the database, and Console does not accept it there');
        $say('  what to set', 'the key from Console > SaaS Database Details > Generate key (it starts with sdb_), as CONSOLE_DB_DETAILS_KEY');
    }
    if (in_array(Environment::describe(), ['production', 'sandbox'], true)) {
        $say('  note', 'a deployed server should take its database name and username from Console, not from DB_NAME / DB_USER');
    }
    $identity = ConsoleDatabaseDetails::connectionIdentity();
    $say('  database name / user', ($identity['name'] !== '' ? $identity['name'] : '(unset)') . ' / ' . ($identity['user'] !== '' ? $identity['user'] : '(unset)'));
}

$say('connection settings', Db::describeSources());
$say('  host / port / sslmode', Env::get('DB_HOST', '127.0.0.1') . ' / ' . Env::get('DB_PORT', '5432') . ' / ' . (Env::get('DB_SSLMODE') ?: '(libpq default)'));

try {
    $pdo = Db::connect();
    $row = $pdo->query("SELECT current_database() AS db, current_user AS usr, COALESCE((SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()), false) AS ssl")->fetch();
    $say('connected', 'yes - database ' . $row['db'] . ', as ' . $row['usr'] . ', SSL ' . ($row['ssl'] ? 'on' : 'off'));
    try {
        $applied = array_map('strval', $pdo->query('SELECT filename FROM messaging_sql_migrations')->fetchAll(\PDO::FETCH_COLUMN));
    } catch (\PDOException) {
        // Connected, but the migrations table is not there: the schema was never applied.
        $say('migrations applied', 'none: messaging_sql_migrations does not exist');
        echo "\nNOT READY: connected, but the database has not been migrated.\n";
        echo 'Reason: not_migrated. ' . DatabaseDiagnosis::hint('not_migrated') . "\n";
        echo "Every request answers 503 database_unavailable until this is done.\n";
        exit(1);
    }
    $onDisk = array_map('basename', glob(__DIR__ . '/../database/migrations/*.sql') ?: []);
    $pending = array_values(array_diff($onDisk, $applied));
    $say('migrations applied', (string) count($applied) . ' of ' . count($onDisk) . ' shipped with this code');
    if ($pending !== []) {
        $say('migrations pending', implode(', ', $pending));
        echo "\nNOT READY: connected, but " . count($pending) . " migration(s) are pending.\n";
        echo 'Reason: migrations_pending. ' . DatabaseDiagnosis::hint('migrations_pending') . "\n";
        exit(1);
    }
    echo "\nOK: Messaging can reach its database, and it is fully migrated.\n";
    exit(0);
} catch (\Throwable $e) {
    $say('connected', 'NO');
    $diagnosis = DatabaseDiagnosis::classify($e);
    echo "\nFAILED connecting: {$e->getMessage()}\n";
    echo "Reason: {$diagnosis['reason']}. {$diagnosis['hint']}\n";
    if (Env::get('DB_PASS') === '') {
        echo "DB_PASS is not set in server-php/.env. Console does not hold the password: set DB_HOST, DB_PORT and DB_PASS\n"
            . "in api/.env (the password must belong to the database user Console names for this row).\n";
    }
    exit(1);
}
