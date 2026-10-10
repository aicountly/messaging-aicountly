<?php

declare(strict_types=1);

/**
 * Where Messaging's database name and username come from, and what is said when they cannot be had.
 *
 *   php server-php/tests/console_database.php          (tests/run.sh runs it too, after the schema is applied)
 *
 * With CONSOLE_API_URL and CONSOLE_DB_DETAILS_KEY set, Console names the database and the login
 * (ConsoleDatabaseDetails); host, port and password stay in api/.env. Without them DB_NAME / DB_USER are
 * read as before. The same rules as Helpdesk, against Messaging's own Db, Health and bin/ scripts.
 *
 * Nothing real is contacted. Most groups script the HTTP transport, so what is tested is what this code
 * DECIDES; one group starts a local stand-in for Console (stubs/console-stub.php) and sends the actual curl
 * request, and runs bin/migrate.php as the server would. The groups that make a real database connection
 * need a PostgreSQL whose DB_NAME ends in `_test` (the .env tests/run.sh writes); without one they are
 * skipped and the run says so.
 */

namespace Aicountly\Api\Tests;

// Command line only. Over HTTP this file is not there.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

use Aicountly\Api\ConsoleDatabaseDetails as C;
use Aicountly\Api\DatabaseConnectionException;
use Aicountly\Api\DatabaseDiagnosis as D;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Health;
use PDOException;

// The test .env that tests/run.sh writes (absent when this file is run by hand: the database groups then skip).
Env::load(__DIR__ . '/../.env');

/** Just enough of an assertion harness; same shape as the other suites' output. */
final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;

    public static function group(string $name): void
    {
        echo "\n" . $name . "\n";
    }

    public static function ok(bool $condition, string $what): void
    {
        if ($condition) {
            self::$passed++;
            echo "  ok    " . $what . "\n";

            return;
        }
        self::$failed++;
        echo "  FAIL  " . $what . "\n";
    }

    public static function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected === $actual) {
            self::$passed++;
            echo "  ok    " . $what . "\n";

            return;
        }
        self::$failed++;
        echo "  FAIL  " . $what . "\n        expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
    }

    public static function skip(string $what): void
    {
        self::$skipped++;
        echo "  skip  " . $what . "\n";
    }
}

// The operator log is a file for the length of this run: what it says is asserted below, and it does not
// spill into the test output.
$previousLog = (string) ini_get('error_log');
$logFile = (string) tempnam(sys_get_temp_dir(), 'messaging-console-log-');
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

// The test database, when there is one: the .env tests/run.sh writes names a database ending in _test. Anything
// else is somebody's real database and is never touched.
$haveDb = str_ends_with((string) Env::get('DB_NAME'), '_test') && Env::get('DB_USER') !== '';

const CONSOLE_URL = 'https://console.example.test/api';

/** @var list<array{url: string, headers: list<string>}> $calls */
$calls = [];
/** @var list<array{status: int, body: ?string, error: ?string}> $queue */
$queue = [];

$setEnv = static function (array $vars): void {
    foreach ($vars as $k => $v) {
        $v === null ? putenv($k) : putenv($k . '=' . $v);
    }
};
// The test database's real settings, read before any case changes the environment.
$testDb = ['host' => Env::get('DB_HOST', '127.0.0.1'), 'port' => Env::get('DB_PORT', '5432'), 'pass' => Env::get('DB_PASS'),
    'name' => Env::get('DB_NAME'), 'user' => Env::get('DB_USER')];
$saved = [];
foreach (['CONSOLE_API_URL', 'CONSOLE_DB_DETAILS_KEY', 'AIC_ENVIRONMENT', 'APP_ENV', 'DB_NAME', 'DB_USER', 'DB_HOST', 'DB_PORT', 'DB_PASS', 'DB_SSLMODE'] as $k) {
    $saved[$k] = getenv($k) === false ? null : getenv($k);
}
$key = '';
$fresh = static function () use (&$key, &$calls, &$queue, $setEnv): void {
    $key = 'test-db-key-' . bin2hex(random_bytes(8));   // a key per case: no shared cache file
    $calls = [];
    $queue = [];
    $setEnv(['CONSOLE_API_URL' => CONSOLE_URL, 'CONSOLE_DB_DETAILS_KEY' => $key, 'AIC_ENVIRONMENT' => null, 'APP_ENV' => 'test',
        'DB_NAME' => 'junk_db_from_env', 'DB_USER' => 'junk_user_from_env']);
    C::resetForTesting(true);
};
$answer = static function (array $row = []) use (&$queue): void {
    $queue[] = ['status' => 200, 'error' => null, 'body' => (string) json_encode(['success' => true, 'data' => $row + [
        'product_slug' => 'messaging', 'environment' => 'production',
        'database_name' => 'cp_messaging', 'database_username' => 'cp_messaging_user',
    ]])];
};
C::useTransport(static function (string $url, array $headers) use (&$calls, &$queue): array {
    $calls[] = ['url' => $url, 'headers' => $headers];
    if ($queue === []) {
        throw new \LogicException('unexpected Console request (nothing queued)');
    }

    return array_shift($queue);
});
$refused = static function () use (&$key): ?string {
    try {
        C::resolve();
    } catch (PDOException $e) {
        return $e->getMessage();
    }

    return null;
};

// ---------------------------------------------------------------------------
T::group('Console database details: the classic settings');
// ---------------------------------------------------------------------------

$fresh();
$setEnv(['CONSOLE_API_URL' => null, 'CONSOLE_DB_DETAILS_KEY' => null]);
T::same(false, C::isConfigured(), 'not configured without the Console settings');
T::same(['name' => 'junk_db_from_env', 'user' => 'junk_user_from_env'], C::connectionIdentity(), 'DB_NAME / DB_USER apply');
T::same([], $calls, 'Console is never called');
$fresh();
$setEnv(['CONSOLE_DB_DETAILS_KEY' => null]);
T::same(false, C::isConfigured(), 'a URL without a key is not configured');
T::same('junk_db_from_env', C::connectionIdentity()['name'], 'and the classic settings apply');

// ---------------------------------------------------------------------------
T::group('Console database details: resolved from Console');
// ---------------------------------------------------------------------------

$fresh();
$answer();
T::same(true, C::isConfigured(), 'configured with both settings');
T::same(['name' => 'cp_messaging', 'user' => 'cp_messaging_user'], C::connectionIdentity(), 'Console\'s name and username win over DB_NAME / DB_USER');
T::same(CONSOLE_URL . '/database-details/resolve', $calls[0]['url'] ?? null, 'it asks GET /database-details/resolve');
T::ok(in_array('Authorization: Bearer ' . $key, $calls[0]['headers'] ?? [], true), 'with the key as a bearer token');
C::resolve();
C::resetForTesting(false);   // a new process: same cache file, empty memory
C::resolve();
T::same(1, count($calls), 'the answer is cached in memory and in the shared file');
T::ok(!str_contains((string) file_get_contents((string) (new \ReflectionMethod(C::class, 'filePath'))->invoke(null)), $key), 'the cache file never holds the key');

// ---------------------------------------------------------------------------
T::group('Console database details: failures');
// ---------------------------------------------------------------------------

foreach ([401 => 'rejected', 403 => 'inactive'] as $status => $word) {
    $fresh();
    $answer();
    C::resolve();
    $queue[] = ['status' => $status, 'error' => null, 'body' => '{"success":false}'];
    // Age the cache past its fresh window, as a later request would find it.
    $path = (string) (new \ReflectionMethod(C::class, 'filePath'))->invoke(null);
    $data = json_decode((string) file_get_contents($path), true);
    $data['fetched_at'] = time() - 600;
    file_put_contents($path, json_encode($data));
    C::resetForTesting(false);
    $message = $refused();
    T::ok($message !== null && str_contains($message, $word) && !str_contains($message, $key), "HTTP $status is refused (not covered by the stale cache) and never names the key");
}

$fresh();
$answer();
C::resolve();
$path = (string) (new \ReflectionMethod(C::class, 'filePath'))->invoke(null);
$data = json_decode((string) file_get_contents($path), true);
$data['fetched_at'] = time() - 600;
file_put_contents($path, json_encode($data));
C::resetForTesting(false);
$queue[] = ['status' => 503, 'error' => null, 'body' => '{}'];
T::same(['name' => 'cp_messaging', 'user' => 'cp_messaging_user'], C::resolve(), 'a 5xx falls back to the last good answer');

$fresh();
$queue[] = ['status' => 0, 'error' => 'unreachable', 'body' => null];
T::ok($refused() !== null, 'unreachable with nothing cached is a PDOException');

foreach ([['database_name' => 'a;host=evil'], ['database_username' => 'x y'], ['database_name' => ''], ['database_username' => '']] as $row) {
    $fresh();
    $answer($row);
    T::ok($refused() !== null, 'an empty or unsafe identifier is refused: ' . json_encode($row));
}

$fresh();
$queue[] = ['status' => 200, 'error' => null, 'body' => 'not json'];
T::ok($refused() !== null, 'an answer that is not the expected JSON is refused');

// ---------------------------------------------------------------------------
T::group('Console database details: only the name and username come from Console (as in Connect)');
// ---------------------------------------------------------------------------

// Whatever else Console's row carries, the connection identity is the name and username and nothing more:
// a host, port, SSL mode or password in the answer is neither read nor needed, so it can neither
// break a connection that .env describes correctly nor replace its password.
$extras = [
    'a full set of connection fields'          => ['database_host' => 'elsewhere.example.test', 'database_port' => 6543, 'database_sslmode' => 'require', 'database_password' => 'from-console'],
    'empty strings for the unrecorded fields'  => ['database_host' => '', 'database_port' => '', 'database_sslmode' => '', 'database_password' => ''],
    'nulls for the unrecorded fields'          => ['database_host' => null, 'database_port' => null, 'database_sslmode' => null, 'database_password' => null],
    'a masked password'                        => ['database_password' => '********'],
    'fields this code has never heard of'      => ['id' => 7, 'is_active' => true, 'cpanel_username' => 'cp', 'created_at' => '2026-10-01'],
];
foreach ($extras as $what => $row) {
    $fresh();
    $answer($row);
    T::same(['name' => 'cp_messaging', 'user' => 'cp_messaging_user'], C::connectionIdentity(), "a row with $what resolves to its name and username only");
}

if ($haveDb) {
    // Db::connect() against the real test database: the name and login are Console's, the rest is .env's,
    // even when Console's row says otherwise.
    $pdoProp = new \ReflectionProperty(Db::class, 'pdo');
    $pdoProp->setAccessible(true);
    $keptPdo = $pdoProp->getValue();
    $connects = static function () use ($pdoProp): ?string {
        $pdoProp->setValue(null, null);
        try {
            $row = Db::connect()->query('SELECT current_database() AS db, current_user AS usr')->fetch();

            return $row['db'] . '/' . $row['usr'];
        } catch (PDOException $e) {
            return null;
        } finally {
            $pdoProp->setValue(null, null);
        }
    };
    $useTestDb = static function () use ($setEnv, $testDb): void {
        $setEnv(['DB_HOST' => $testDb['host'], 'DB_PORT' => $testDb['port'], 'DB_PASS' => $testDb['pass'], 'DB_SSLMODE' => null]);
    };

    $fresh();
    $useTestDb();
    $answer(['database_name' => $testDb['name'], 'database_username' => $testDb['user'], 'database_host' => 'unreachable.invalid', 'database_port' => 1,
        'database_sslmode' => 'verify-full', 'database_password' => 'wrong-password-from-console']);
    T::same($testDb['name'] . '/' . $testDb['user'], $connects(), 'a connection is made with .env\'s host, port and password; Console\'s name and username; nothing else from the row');
    T::same(1, count($calls), '…after asking Console once');

    $fresh();
    $useTestDb();
    $setEnv(['DB_PASS' => 'wrong-password-in-env']);
    $answer(['database_name' => $testDb['name'], 'database_username' => $testDb['user'], 'database_password' => $testDb['pass']]);
    T::same(null, $connects(), 'the password is .env\'s alone: the right one in Console\'s row does not rescue a wrong DB_PASS');

    $fresh();
    $useTestDb();
    $answer(['database_name' => $testDb['name'], 'database_username' => $testDb['user']]);
    $setEnv(['DB_SSLMODE' => 'bogus']);
    T::same(null, $connects(), 'an unknown DB_SSLMODE is refused rather than put in a connection string');
    $fresh();
    $useTestDb();
    $answer(['database_name' => $testDb['name'], 'database_username' => $testDb['user']]);
    $setEnv(['DB_SSLMODE' => 'disable']);
    T::same($testDb['name'] . '/' . $testDb['user'], $connects(), 'DB_SSLMODE from .env is passed on (disable)');
    $useTestDb();
    $pdoProp->setValue(null, $keptPdo);
} else {
    T::skip('Db::connect() with Console\'s name and username (needs a PostgreSQL whose DB_NAME ends in _test)');
}

// ---------------------------------------------------------------------------
T::group('Console database details: the key belongs to one environment');
// ---------------------------------------------------------------------------

foreach ([['production', 'sandbox', false], ['sandbox', 'production', false], ['production', 'production', true], ['sandbox', 'sandbox', true]] as [$app, $row, $ok]) {
    $fresh();
    $setEnv(['AIC_ENVIRONMENT' => $app]);
    $answer(['environment' => $row]);
    T::same($ok, $refused() === null, "$app deployment with a $row key is " . ($ok ? 'accepted' : 'refused'));
}
$fresh();
$setEnv(['AIC_ENVIRONMENT' => 'local']);
$answer(['environment' => 'production']);
T::same(null, $refused(), 'a local deployment may use any row');

// Connect's rule: refuse only when BOTH sides name an environment and they differ. A row that names none,
// or says "prod" / "staging", is not a mismatch.
foreach ([['production', ['environment' => 'prod'], true], ['production', ['environment' => ' Production '], true], ['sandbox', ['environment' => 'staging'], true],
    ['production', ['environment' => ''], true], ['sandbox', ['environment' => null], true], ['production', ['environment' => 'staging'], false], ['sandbox', ['environment' => 'prod'], false]] as [$app, $row, $ok]) {
    $fresh();
    $setEnv(['AIC_ENVIRONMENT' => $app]);
    $answer($row);
    T::same($ok, $refused() === null, "$app deployment with a row saying " . json_encode($row['environment']) . ' is ' . ($ok ? 'accepted' : 'refused'));
}
$fresh();
$setEnv(['AIC_ENVIRONMENT' => null, 'APP_ENV' => 'production']);
$queue[] = ['status' => 200, 'error' => null, 'body' => (string) json_encode(['success' => true, 'data' => ['database_name' => 'cp_messaging', 'database_username' => 'cp_messaging_user']])];
T::same(null, $refused(), 'a row with no environment field at all is accepted on a production deployment');

// ---------------------------------------------------------------------------
T::group('Console database details: where a connection comes from, and bin/db-check.php');
// ---------------------------------------------------------------------------

$fresh();
Env::load('/nonexistent/.env');   // no .env file: only the real environment counts for the next assertions
putenv('DB_HOST=db.local.example');
putenv('DB_PASS=env-secret-pass');
putenv('DB_PORT');      // earlier cases leave these exported
putenv('DB_SSLMODE');
$described = Db::describeSources();
T::ok(str_contains($described, 'database name/user from Console') && str_contains($described, 'host from .env') && str_contains($described, 'password from .env'), 'name/user are Console\'s, host and password are .env\'s, and the description says so');
T::ok(str_contains($described, 'port from default 5432') && str_contains($described, 'sslmode from libpq default'), 'what .env leaves out falls back to the defaults, and the description says so');
T::ok(!str_contains($described, 'env-secret-pass'), 'the description never contains the password');
putenv('DB_PASS');
T::ok(str_contains(Db::describeSources(), 'password from NOWHERE'), 'with no DB_PASS the description says so');
$setEnv(['CONSOLE_API_URL' => null, 'CONSOLE_DB_DETAILS_KEY' => null]);
T::ok(str_contains(Db::describeSources(), 'database name/user from .env (DB_NAME/DB_USER)'), 'without the Console settings the name and username are .env\'s');
putenv('DB_HOST');
Env::load(dirname(__DIR__) . '/.env');   // back to the test .env

// refresh() ignores the cache and uses what Console says now.
$fresh();
$answer();
C::resolve();
$answer(['database_name' => 'cp_messaging_moved']);
T::same('cp_messaging_moved', C::refresh()['name'], 'refresh() asks Console again and uses its new answer');
T::same(2, count($calls), '…with exactly one more Console call');
C::resetForTesting(false);
T::same('cp_messaging_moved', C::resolve()['name'], '…and caches it for the next process');

// The script, exactly as run on the server: a clean environment, so only .env and what is passed here count.
$check = static function (array $vars): array {
    $env = ['PATH' => (string) getenv('PATH')] + $vars;
    $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/db-check.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

    return [proc_close($proc), $out];
};
if ($haveDb) {
    [$code, $out] = $check([]);
    T::same(0, $code, 'db-check.php exits 0 when the database is reachable');
    T::ok(str_contains($out, 'connected') && str_contains($out, 'OK: Messaging can reach its database'), '…and says it connected');

    [$code, $out] = $check(['DB_PASS' => 'not-the-password-xyz']);
    T::same(1, $code, 'db-check.php exits 1 when the database refuses the connection');
    T::ok(str_contains($out, 'FAILED connecting') && str_contains($out, 'password from .env') && !str_contains($out, 'not-the-password-xyz'), '…names the failure and where the password comes from, never its value');
} else {
    T::skip('bin/db-check.php against the test database (needs a PostgreSQL whose DB_NAME ends in _test)');
}

$fresh();
[$code, $out] = $check(['CONSOLE_API_URL' => 'http://127.0.0.1:9/api', 'CONSOLE_DB_DETAILS_KEY' => 'a-key-that-must-not-be-printed']);
T::same(1, $code, 'db-check.php exits 1 when Console cannot be reached');
T::ok(str_contains($out, 'FAILED at Console') && !str_contains($out, 'a-key-that-must-not-be-printed'), '…says it failed at Console, never printing the key');

// ---------------------------------------------------------------------------
T::group('Console database details: the only way in');
// ---------------------------------------------------------------------------

$src = dirname(__DIR__) . '/src';
$offenders = [];
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && $file->getFilename() !== 'ConsoleDatabaseDetails.php'
        && preg_match('/[\'"]DB_(NAME|USER)[\'"]/', (string) file_get_contents($file->getPathname())) === 1) {
        $offenders[] = $file->getPathname();
    }
}
T::same([], $offenders, 'DB_NAME / DB_USER are read in src/ConsoleDatabaseDetails.php only');
$resolverSource = (string) file_get_contents($src . '/ConsoleDatabaseDetails.php');
T::same(0, preg_match('/database_(host|port|sslmode|password)/', $resolverSource), 'the resolver reads no host, port, SSL mode or password from Console (Connect\'s split)');
$pdo = 0;
foreach (['src', 'bin'] as $dir) {
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        $pdo += preg_match_all('/new\s+\\\\?PDO\s*\(/', (string) file_get_contents($file->getPathname()));
    }
}
T::same(1, $pdo, 'exactly one place builds a database connection (Db::connect)');

// Put everything back for the cases that follow.
C::useTransport(null);
C::resetForTesting(true);
$setEnv($saved);

(static function () use ($haveDb, $logFile): void {
    $setEnv = static function (array $vars): void {
        foreach ($vars as $k => $v) {
            $v === null ? putenv($k) : putenv($k . '=' . $v);
        }
    };
    // The test database's real settings, read before anything here changes the environment.
    $testDb = ['host' => Env::get('DB_HOST', '127.0.0.1'), 'port' => Env::get('DB_PORT', '5432'), 'pass' => Env::get('DB_PASS'),
        'name' => Env::get('DB_NAME'), 'user' => Env::get('DB_USER')];
    $envFile = dirname(__DIR__) . '/.env';
    $saved = [];
    foreach (['CONSOLE_API_URL', 'CONSOLE_DB_DETAILS_KEY', 'AIC_ENVIRONMENT', 'APP_ENV', 'DB_NAME', 'DB_USER', 'DB_HOST', 'DB_PORT', 'DB_PASS', 'DB_SSLMODE', 'DB_SCHEMA', 'CONSOLE_SERVICE_KEY'] as $k) {
        $saved[$k] = getenv($k) === false ? null : getenv($k);
    }
    $pdoProp = new \ReflectionProperty(Db::class, 'pdo');
    $pdoProp->setAccessible(true);
    // A second, separate connection to the test database: the cases that take a migration record away (or
    // rename the table) do it through this one, so Db's own connection is only ever used the way the API uses it.
    $admin = null;
    if ($haveDb) {
        $admin = new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '5432'), Env::get('DB_NAME')),
            Env::get('DB_USER'),
            Env::get('DB_PASS'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
        );
    }
    $reconnect = static function () use ($pdoProp): void {
        $pdoProp->setValue(null, null);
    };
    // Only the real environment counts below: a setting a case leaves out must really be absent, not
    // filled in from the test .env.
    Env::load('/nonexistent/.env');
    $restore = static function () use ($setEnv, $saved, $pdoProp, $envFile): void {
        $setEnv($saved);
        Env::load($envFile);
        C::useTransport(null);
        C::resetForTesting(true);
        $pdoProp->setValue(null, null);
    };

    // -----------------------------------------------------------------------
    T::group('Database diagnosis: what each failure is called');
    // -----------------------------------------------------------------------

    $libpq = static fn (string $detail): string => 'SQLSTATE[08006] [7] connection to server at "localhost" (127.0.0.1), port 5432 failed: ' . $detail;
    $named = [
        'a wrong password' => [new PDOException($libpq('FATAL:  password authentication failed for user "cp_user"')), 'database_login_refused'],
        'a host the pg_hba.conf refuses (its text also says "SSL off")' => [new PDOException($libpq('FATAL:  no pg_hba.conf entry for host "203.0.113.9", user "cp_user", database "cp_db", SSL off')), 'database_login_refused'],
        'a user that does not exist' => [new PDOException($libpq('FATAL:  role "cp_user" does not exist')), 'database_login_refused'],
        'a database that does not exist' => [new PDOException($libpq('FATAL:  database "cp_db" does not exist')), 'database_not_found'],
        'a refused connection' => [new PDOException($libpq("Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?")), 'database_unreachable'],
        'a host that does not resolve' => [new PDOException('SQLSTATE[08006] [7] could not translate host name "db.invalid" to address: Name or service not known'), 'database_unreachable'],
        'a missing socket' => [new PDOException($libpq('No such file or directory')), 'database_unreachable'],
        'SSL that the server does not offer' => [new PDOException($libpq('server does not support SSL, but SSL was required')), 'database_ssl'],
        'a missing PHP driver' => [new PDOException('could not find driver'), 'driver_missing'],
        'something it has no name for' => [new PDOException('SQLSTATE[HY000]: General error: something unforeseen'), 'database_error'],
        'not a PDO error at all' => [new \RuntimeException('boom'), 'database_error'],
    ];
    foreach ($named as $what => [$e, $reason]) {
        T::same($reason, D::classify($e)['reason'], "$what is $reason");
    }
    $query = static function (string $state, string $message): PDOException {
        $e = new PDOException($message);
        $e->errorInfo = [$state, 7, $message];

        return $e;
    };
    T::same('not_migrated', D::classify($query('42P01', 'ERROR:  relation "messaging_sql_migrations" does not exist'))['reason'], 'a query on a table that is not there (SQLSTATE 42P01) is not_migrated');
    T::same('schema_out_of_date', D::classify($query('42703', 'ERROR:  column "pulse_task_id" does not exist'))['reason'], 'a query on a column that is not there (42703) is schema_out_of_date');
    T::same('database_permission_denied', D::classify($query('42501', 'ERROR:  permission denied for table messaging_sql_migrations'))['reason'], 'a query the database user may not run (42501) is database_permission_denied');

    $secret = new PDOException($libpq('FATAL:  password authentication failed for user "cp_secret_user" (password: hunter2-secret, key: dbk-live-0123456789)'));
    $diagnosis = (string) json_encode(D::classify($secret));
    T::ok(!str_contains($diagnosis, 'hunter2-secret') && !str_contains($diagnosis, 'cp_secret_user') && !str_contains($diagnosis, 'dbk-live') && !str_contains($diagnosis, '127.0.0.1'),
        'the answer is a fixed sentence: nothing from the exception (user, password, key, host) is in it');
    $allHaveHints = D::categories() !== [];
    foreach (D::categories() as $category) {
        $allHaveHints = $allHaveHints && D::hint($category) !== '';
    }
    T::ok($allHaveHints, 'every category has a sentence saying what to do');
    T::same(D::hint('database_error'), D::hint('a-category-nobody-defined'), 'a category this class does not know is an unexplained database error, never an empty answer');

    // -----------------------------------------------------------------------
    T::group('Database diagnosis: what Console\'s refusals are called');
    // -----------------------------------------------------------------------

    $consoleUrl = 'https://console.example.test/api';
    $key = '';
    $queue = [];
    $calls = 0;
    /** @var list<list<string>> $sentHeaders the headers of every Console request, in order */
    $sentHeaders = [];
    C::useTransport(static function (string $url, array $headers) use (&$queue, &$calls, &$sentHeaders): array {
        $calls++;
        $sentHeaders[] = $headers;
        if ($queue === []) {
            throw new \LogicException('unexpected Console request (nothing queued)');
        }

        return array_shift($queue);
    });
    $consoleCase = static function (array $env = []) use (&$key, &$queue, &$calls, &$sentHeaders, $setEnv, $consoleUrl): void {
        $key = 'diag-key-' . bin2hex(random_bytes(8));   // a key per case: no shared cache file
        $queue = [];
        $calls = 0;
        $sentHeaders = [];
        $setEnv($env + ['CONSOLE_API_URL' => $consoleUrl, 'CONSOLE_DB_DETAILS_KEY' => $key, 'AIC_ENVIRONMENT' => null, 'APP_ENV' => 'test', 'DB_NAME' => null, 'DB_USER' => null]);
        C::resetForTesting(true);
    };
    $reply = static function (int $status, ?string $body = '', ?string $error = null) use (&$queue): void {
        $queue[] = ['status' => $status, 'body' => $body, 'error' => $error];
    };
    $row = static fn (array $over = []): string => (string) json_encode(['success' => true, 'data' => $over + ['environment' => 'production', 'database_name' => 'cp_messaging', 'database_username' => 'cp_messaging_user']]);
    $categoryOf = static function () use (&$key): ?string {
        try {
            C::resolve();
        } catch (DatabaseConnectionException $e) {
            return $e->category . (str_contains($e->getMessage(), $key) ? ' (LEAKED THE KEY)' : '');
        }

        return null;
    };

    $refusals = [
        'a revoked key (401)' => [static fn () => $reply(401, '{"success":false}'), [], 'console_key_rejected'],
        'an inactive row (403)' => [static fn () => $reply(403, '{"success":false}'), [], 'console_row_inactive'],
        'Console down (503), nothing cached' => [static fn () => $reply(503), [], 'console_unreachable'],
        'Console not answering at all' => [static fn () => $reply(0, null, 'unreachable'), [], 'console_unreachable'],
        'a route Console does not have (404)' => [static fn () => $reply(404), [], 'console_unexpected_answer'],
        'an answer that is not JSON' => [static fn () => $reply(200, '<html>maintenance</html>'), [], 'console_unexpected_answer'],
        'a response that was too large' => [static fn () => $reply(200, null, 'too_large'), [], 'console_unexpected_answer'],
        'a name with a ";" in it' => [static fn () => $reply(200, $row(['database_name' => 'cp;host=evil'])), [], 'console_unexpected_answer'],
        'no database recorded for the product' => [static fn () => $reply(200, $row(['database_name' => '', 'database_username' => ''])), [], 'console_no_database_recorded'],
        'a sandbox key on a production deployment' => [static fn () => $reply(200, $row(['environment' => 'sandbox'])), ['APP_ENV' => 'production'], 'console_environment_mismatch'],
        'a CONSOLE_API_URL that is not http(s)' => [static fn () => null, ['CONSOLE_API_URL' => 'ftp://console.example.test'], 'console_config'],
        'a key that cannot be a header' => [static fn () => null, ['CONSOLE_DB_DETAILS_KEY' => "bad key\r\nX-Injected: 1"], 'console_config'],
    ];
    foreach ($refusals as $what => [$queueIt, $env, $expected]) {
        $consoleCase($env);
        $queueIt();
        T::same($expected, $categoryOf(), "$what is $expected");
    }

    $consoleCase();
    $reply(401, '{"success":false}');
    $categoryOf();
    $before = $calls;
    T::same('console_key_rejected', $categoryOf(), 'the failure remembered for a few seconds keeps its category');
    T::same($before, $calls, '…without asking Console again');

    $consoleCase();
    $reply(401, '{"success":false}');
    try {
        C::resolve();
        $isPdo = false;
    } catch (PDOException $e) {
        $isPdo = $e instanceof DatabaseConnectionException;
    }
    T::ok($isPdo, 'a refusal is still a PDOException, so every caller that answers 503 database_unavailable to a failed connection still does');

    // -----------------------------------------------------------------------
    T::group('Database diagnosis: what /api/health says');
    // -----------------------------------------------------------------------

    // Messaging's health answer keeps the keys it always had (reachable, reason, schema: tests/ci reads them);
    // `source` is new, and a failure to obtain the database name / username is reported by its own reason with a
    // fixed `hint`. The classic driver failures keep the categories they have always had.
    $wrongPass = 'diag-wrong-password-xyz';
    $consoleKey = '';
    $outputs = [];
    $newest = null;
    $migrationsOnDisk = count(glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: []);
    $pg = ['CONSOLE_API_URL' => null, 'CONSOLE_DB_DETAILS_KEY' => null, 'CONSOLE_SERVICE_KEY' => null, 'AIC_ENVIRONMENT' => null, 'APP_ENV' => 'test', 'DB_SSLMODE' => null, 'DB_SCHEMA' => null,
        'DB_HOST' => $testDb['host'], 'DB_PORT' => $testDb['port'], 'DB_PASS' => $testDb['pass'], 'DB_NAME' => $testDb['name'], 'DB_USER' => $testDb['user']];
    $healthWith = static function (array $env) use ($setEnv, $reconnect, $pg): array {
        $setEnv($env + $pg);
        $reconnect();
        C::resetForTesting(true);
        try {
            return Health::database();
        } finally {
            $reconnect();
        }
    };
    $encode = static fn (array $health): string => (string) json_encode($health);
    $schemaOf = static fn (array $health): mixed => array_key_exists('schema', $health) ? $health['schema'] : 'missing';

    // What does not need a database: configuration that is refused before any connection is made.
    $noDb = ['DB_NAME' => null, 'DB_USER' => null];
    foreach ([
        'nothing configured at all' => [$noDb, 'not_configured'],
        'an unknown DB_SSLMODE' => [['DB_SSLMODE' => 'bogus'], 'invalid_sslmode'],
    ] as $what => [$env, $reason]) {
        // DB_NAME / DB_USER are present for the SSL case (it is refused after the name is known) and absent for the other.
        $h = $healthWith($env + ($reason === 'invalid_sslmode' ? ['DB_NAME' => 'cp_diag', 'DB_USER' => 'cp_diag_user'] : []));
        $outputs[] = $encode($h);
        T::same([false, $reason, 'env', null, D::hint($reason)], [$h['reachable'], $h['reason'] ?? null, $h['source'] ?? null, $schemaOf($h), $h['hint'] ?? null], "health: $what is $reason, from source env, with the fixed sentence for it");
    }

    $consoleCase();
    $setEnv(['CONSOLE_API_URL' => $consoleUrl, 'CONSOLE_DB_DETAILS_KEY' => $key] + $pg);   // left operand wins in `+`
    $reply(401, '{"success":false}');
    $reconnect();
    $h = Health::database();
    $reconnect();
    $outputs[] = $encode($h);
    T::same([false, 'console', 'console_key_rejected', D::hint('console_key_rejected')], [$h['reachable'], $h['source'] ?? null, $h['reason'] ?? null, $h['hint'] ?? null], 'health: a refused Console key is console_key_rejected, source console, with its fixed sentence');
    $consoleKey = $key;

    $removedRow = null;   // a migration record taken away by a case below; the shutdown function puts it back if a case dies
    if (!$haveDb) {
        T::skip('health, bin/db-check.php and the Console key against PostgreSQL (need a PostgreSQL whose DB_NAME ends in _test)');
    } else {
        $restoreRow = static function () use ($admin, &$removedRow): void {
            if ($removedRow === null) {
                return;
            }
            $admin->prepare('INSERT INTO messaging_sql_migrations (migration_id, filename, checksum, applied_at) VALUES (:id, :f, :c, :a)')
                ->execute(['id' => $removedRow['migration_id'], 'f' => $removedRow['filename'], 'c' => $removedRow['checksum'], 'a' => $removedRow['applied_at']]);
            $removedRow = null;
        };
        $heal = static function () use ($admin, $restoreRow): void {
            try {
                $restoreRow();
                $held = $admin->query("SELECT to_regclass('messaging_sql_migrations_diag_hold')")->fetchColumn();
                $real = $admin->query("SELECT to_regclass('messaging_sql_migrations')")->fetchColumn();
                if ($held && !$real) {
                    $admin->exec('ALTER TABLE messaging_sql_migrations_diag_hold RENAME TO messaging_sql_migrations');
                }
            } catch (\Throwable) {
                // Nothing more can be done from a shutdown function.
            }
        };
        register_shutdown_function($heal);
        $takeNewestRecordAway = static function () use ($admin, &$removedRow): void {
            $removedRow = $admin->query('SELECT migration_id, filename, checksum, applied_at FROM messaging_sql_migrations ORDER BY filename DESC LIMIT 1')->fetch();
            $admin->prepare('DELETE FROM messaging_sql_migrations WHERE migration_id = :id')->execute(['id' => $removedRow['migration_id']]);
        };
        $hideMigrationsTable = static fn () => $admin->exec('ALTER TABLE messaging_sql_migrations RENAME TO messaging_sql_migrations_diag_hold');
        $showMigrationsTable = static fn () => $admin->exec('ALTER TABLE messaging_sql_migrations_diag_hold RENAME TO messaging_sql_migrations');

        $h = $healthWith([]);
        T::same([true, 'env', 0, true], [$h['reachable'], $h['source'] ?? null, $h['schema']['pending'] ?? null, $h['schema']['ready'] ?? null], 'a migrated database: reachable, from DB_NAME / DB_USER, nothing pending, ready');
        T::ok(array_key_exists('reason', $h) && $h['reason'] === null && !array_key_exists('hint', $h), '…with no reason and no hint');

        // Pending: the newest migration's record is taken away, as if this code had shipped one nobody ran.
        $takeNewestRecordAway();
        try {
            $h = $healthWith([]);
            T::same([true, 1, false], [$h['reachable'], $h['schema']['pending'] ?? null, $h['schema']['ready'] ?? null], 'a database missing a migration the code ships is reachable but NOT ready: one pending');
        } finally {
            $restoreRow();
        }

        // Not migrated: the migrations table is not there at all, which is what a database nobody ever ran migrate.php against looks like.
        $hideMigrationsTable();
        try {
            $h = $healthWith([]);
            T::same([true, 0, $migrationsOnDisk, false], [$h['reachable'], $h['schema']['applied'] ?? null, $h['schema']['pending'] ?? null, $h['schema']['ready'] ?? null], 'a database that connects but was never migrated: none applied, all pending, not ready');
        } finally {
            $showMigrationsTable();
        }

        foreach ([
            'a wrong DB_PASS' => [['DB_PASS' => $wrongPass], 'refused'],
            'a database that does not exist' => [['DB_NAME' => 'diag_no_such_database'], 'no_such_database'],
            'a PostgreSQL port nothing listens on' => [['DB_PORT' => '59999'], 'unreachable'],
            'a user that does not exist' => [['DB_USER' => 'diag_no_such_user'], 'refused'],
        ] as $what => [$env, $reason]) {
            $h = $healthWith($env);
            $outputs[] = $encode($h);
            T::same([false, $reason, 'env', null, false], [$h['reachable'], $h['reason'] ?? null, $h['source'] ?? null, $schemaOf($h), array_key_exists('hint', $h)], "health: $what is $reason (the category it always had, no hint)");
        }

        // Console as the source, answering for a database that exists: no DB_NAME and no DB_USER anywhere.
        $consoleCase();
        $queue[] = ['status' => 200, 'error' => null, 'body' => $row(['database_name' => $testDb['name'], 'database_username' => $testDb['user'], 'environment' => 'production'])];
        $setEnv(['CONSOLE_API_URL' => $consoleUrl, 'CONSOLE_DB_DETAILS_KEY' => $key, 'DB_NAME' => null, 'DB_USER' => null] + $pg);
        $reconnect();
        $h = Health::database();
        $reconnect();
        T::same([true, 'console', true], [$h['reachable'], $h['source'] ?? null, $h['schema']['ready'] ?? null], 'Console configured and no DB_NAME / DB_USER anywhere: it connects, source console');
        T::same(1, $calls, '…after asking Console once');

        $everything = implode("\n", $outputs);
        T::ok(!str_contains($everything, $wrongPass) && !str_contains($everything, $consoleKey)
            && ($testDb['pass'] === '' || !str_contains($everything, (string) $testDb['pass']))
            && !str_contains($everything, $testDb['host']) && !str_contains($everything, 'SQLSTATE') && !str_contains($everything, 'FATAL'),
            'no response above carries a password, a Console key, a host, a SQLSTATE or the driver\'s text');
        $known = array_merge(D::categories(), ['refused', 'no_such_database', 'unreachable', 'driver_missing', 'error']);
        T::ok(array_reduce($outputs, static fn (bool $carry, string $json): bool => $carry && in_array((json_decode($json, true)['reason'] ?? ''), $known, true), true), 'every reason reported is a documented category');

        // -----------------------------------------------------------------------
        T::group('Database diagnosis: bin/db-check.php');
        // -----------------------------------------------------------------------

        // The script as run on the server: a clean environment, so only .env and what is passed here count.
        $check = static function (array $vars = []): array {
            $env = ['PATH' => (string) getenv('PATH')] + $vars;
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/db-check.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

            return [proc_close($proc), $out];
        };

        [$code, $out] = $check();
        T::same(0, $code, 'migrated and reachable: exit 0');
        T::ok(str_contains($out, 'OK: Messaging can reach its database') && str_contains($out, 'fully migrated'), '…and says it is fully migrated');

        $hideMigrationsTable();
        try {
            [$code, $out] = $check();
        } finally {
            $showMigrationsTable();
        }
        T::same(1, $code, 'connected but never migrated: exit 1, not 0 (every request would still answer 503)');
        T::ok(str_contains($out, 'NOT READY') && str_contains($out, 'not_migrated') && str_contains($out, 'php api/bin/migrate.php'), '…saying NOT READY, the reason, and what to run');

        $takeNewestRecordAway();
        $pendingName = (string) $removedRow['filename'];
        try {
            [$code, $out] = $check();
        } finally {
            $restoreRow();
        }
        T::same(1, $code, 'connected with a migration pending: exit 1');
        T::ok(str_contains($out, 'migrations_pending') && str_contains($out, $pendingName), '…naming the migration that is pending');

        [$code, $out] = $check(['DB_PASS' => $wrongPass]);
        T::same(1, $code, 'a wrong password: exit 1');
        T::ok(str_contains($out, 'Reason: database_login_refused') && !str_contains($out, $wrongPass), '…with its reason, and never the password');

        [$code, $out] = $check(['DB_PORT' => '59999']);
        T::ok($code === 1 && str_contains($out, 'Reason: database_unreachable'), 'a port nothing listens on: exit 1 and database_unreachable');

        [$code, $out] = $check(['CONSOLE_API_URL' => 'http://127.0.0.1:9/api', 'CONSOLE_DB_DETAILS_KEY' => 'a-key-that-must-not-be-printed']);
        T::ok($code === 1 && str_contains($out, 'Reason: console_unreachable') && !str_contains($out, 'a-key-that-must-not-be-printed'), 'Console not reachable: exit 1, console_unreachable, and never the key');

        [$code, $out] = $check(['CONSOLE_API_URL' => $consoleUrl, 'CONSOLE_SERVICE_KEY' => 'estate-service-key-must-never-be-sent']);
        T::ok(str_contains($out, 'NOT used: CONSOLE_DB_DETAILS_KEY not set') && str_contains($out, 'CONSOLE_SERVICE_KEY') && str_contains($out, 'sdb_') && !str_contains($out, 'estate-service-key-must-never-be-sent'),
            'with only CONSOLE_API_URL and CONSOLE_SERVICE_KEY it says Console is NOT used, which variable is missing, that CONSOLE_SERVICE_KEY is not it, and never its value');
    }

    // -----------------------------------------------------------------------
    T::group('Database diagnosis: the Console key under the wrong name');
    // -----------------------------------------------------------------------

    // A deployment that has CONSOLE_API_URL and a key under some other name (CONSOLE_SERVICE_KEY is the usual one: it is
    // a different, estate-wide key, and Console's database endpoint rejects it) has told Console nothing. Console is then
    // never asked, nothing fails loudly, and commenting DB_NAME / DB_USER out leaves no database at all.
    $estateKey = 'estate-service-key-must-never-be-sent';

    $consoleCase();
    clearstatcache();
    $logMark = (int) filesize($logFile);   // only what this case writes: the log is shared with the group that checks it at the end
    $h = $healthWith($noDb + ['CONSOLE_API_URL' => $consoleUrl, 'CONSOLE_SERVICE_KEY' => $estateKey]);
    T::same([false, 'console_key_missing', 'env'], [$h['reachable'], $h['reason'] ?? null, $h['source'] ?? null], 'CONSOLE_API_URL + CONSOLE_SERVICE_KEY and no DB_NAME / DB_USER is console_key_missing, source env');
    T::ok(str_contains((string) ($h['hint'] ?? ''), 'CONSOLE_DB_DETAILS_KEY') && str_contains((string) ($h['hint'] ?? ''), 'CONSOLE_SERVICE_KEY') && str_contains((string) ($h['hint'] ?? ''), 'sdb_'),
        '…and the hint says which variable to set, that CONSOLE_SERVICE_KEY is not it, and what the right key looks like');
    T::same(0, $calls, 'Console is never asked: there is no key to ask with');
    T::same([], $sentHeaders, '…and CONSOLE_SERVICE_KEY is not sent anywhere in its place');
    $newLog = substr((string) file_get_contents($logFile), $logMark);
    T::ok(str_contains($newLog, 'CONSOLE_SERVICE_KEY is set, but it is not used for the database') && !str_contains($newLog, $estateKey), 'the operator log names the mistake, without the key\'s value');
    T::ok(!str_contains((string) json_encode($h), $estateKey), 'and health does not carry the key\'s value');

    $h = $healthWith($noDb + ['CONSOLE_DB_DETAILS_KEY' => 'sdb_' . str_repeat('a', 64)]);
    T::same([false, 'console_url_missing'], [$h['reachable'], $h['reason'] ?? null], 'a key with no CONSOLE_API_URL is console_url_missing');

    if ($haveDb) {
        $h = $healthWith(['CONSOLE_API_URL' => $consoleUrl, 'CONSOLE_SERVICE_KEY' => $estateKey, 'DB_NAME' => $testDb['name'], 'DB_USER' => $testDb['user']]);   // no $noDb: left operand wins in `+`
        T::same([true, 'env', 0], [$h['reachable'], $h['source'] ?? null, $calls], 'with DB_NAME / DB_USER back in, it works from them: source env, and still no call to Console (the reported behaviour)');

        // The same deployment done right: the per-row key under its own name, and nothing local naming a database.
        $consoleCase();
        $queue[] = ['status' => 200, 'error' => null, 'body' => $row(['database_name' => $testDb['name'], 'database_username' => $testDb['user'], 'environment' => 'production'])];
        $setEnv(['CONSOLE_API_URL' => $consoleUrl, 'CONSOLE_DB_DETAILS_KEY' => $key, 'CONSOLE_SERVICE_KEY' => $estateKey, 'DB_NAME' => null, 'DB_USER' => null] + $pg);
        $reconnect();
        $h = Health::database();
        $reconnect();
        T::same([true, 'console'], [$h['reachable'], $h['source'] ?? null], 'CONSOLE_DB_DETAILS_KEY connects with no DB_NAME and no DB_USER: source console');
        T::same(1, $calls, '…after asking Console once');
        $bearers = array_map(static fn (array $headers): array => array_values(array_filter($headers, static fn (string $line): bool => str_starts_with($line, 'Authorization:'))), $sentHeaders);
        T::same([['Authorization: Bearer ' . $key]], $bearers, 'the bearer token is the per-row key; CONSOLE_SERVICE_KEY, set beside it, is not what is sent');
    } else {
        T::skip('the working configurations against PostgreSQL (need a PostgreSQL whose DB_NAME ends in _test)');
    }

    // -----------------------------------------------------------------------
    T::group('The real transport: a stand-in Console over HTTP, and bin/migrate.php');
    // -----------------------------------------------------------------------

    $startStub = static function (string $state, string $log): array {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/stub/console.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['CONSOLE_STUB_STATE' => $state, 'CONSOLE_STUB_LOG' => $log, 'PATH' => (string) getenv('PATH')],
        );
        for ($i = 0; $i < 60; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($s) {
                fclose($s);

                return [$proc, $port];
            }
            usleep(100000);
        }
        throw new \RuntimeException('the Console stand-in did not start');
    };
    $stubRequests = static fn (string $log): array => array_values(array_map(static fn (string $l): array => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents($log)))));
    $cacheFile = static fn (): string => (string) (new \ReflectionMethod(C::class, 'filePath'))->invoke(null);
    $refusal = static function (): ?string {
        try {
            C::resolve();
        } catch (PDOException $e) {
            return $e->getMessage();
        }

        return null;
    };

    $stubState = (string) tempnam(sys_get_temp_dir(), 'messaging-console-state-');
    $stubLog = (string) tempnam(sys_get_temp_dir(), 'messaging-console-stublog-');
    [$stub, $stubPort] = $startStub($stubState, $stubLog);
    $stubKey = 'real-transport-key-' . bin2hex(random_bytes(4));
    $stubRow = ['database_name' => $haveDb ? $testDb['name'] : 'cp_messaging', 'database_username' => $haveDb ? $testDb['user'] : 'cp_messaging_user', 'environment' => 'sandbox'];
    $stubCaches = [];
    $realTransport = static function () use ($setEnv, $stubPort, $stubKey, $cacheFile, &$stubCaches): void {
        $setEnv(['CONSOLE_API_URL' => 'http://127.0.0.1:' . $stubPort . '/api', 'CONSOLE_DB_DETAILS_KEY' => $stubKey, 'APP_ENV' => 'local', 'AIC_ENVIRONMENT' => null, 'DB_NAME' => null, 'DB_USER' => null]);
        C::resetForTesting(true);
        $stubCaches[] = $cacheFile();
        C::useTransport(null);
    };
    $setStub = static fn (array $state) => file_put_contents($stubState, json_encode($state));

    $setStub(['key' => $stubKey, 'row' => $stubRow, 'mode' => 'ok']);
    $realTransport();
    T::same(['name' => $stubRow['database_name'], 'user' => $stubRow['database_username']], C::resolve(), 'a real request is answered');
    T::same([['method' => 'GET', 'path' => '/api/database-details/resolve', 'authorization' => 'Bearer ' . $stubKey]], $stubRequests($stubLog), 'it was one GET to /api/database-details/resolve with the key as a bearer token');

    $setStub(['key' => 'some-other-key', 'row' => $stubRow, 'mode' => 'ok']);
    $realTransport();
    $message = $refusal();
    T::ok($message !== null && str_contains($message, 'rejected CONSOLE_DB_DETAILS_KEY') && !str_contains($message, $stubKey), 'a wrong key is a 401, refused, and the message never names the key');

    $setStub(['key' => $stubKey, 'row' => $stubRow, 'mode' => 'redirect']);
    $realTransport();
    file_put_contents($stubLog, '');
    $message = $refusal();
    T::ok($message !== null, 'a redirect is an answer, not somewhere to send the key: refused');
    T::same([], array_values(array_filter($stubRequests($stubLog), static fn (array $r): bool => $r['path'] === '/elsewhere')), '…and the redirect target was never requested');

    $setStub(['key' => $stubKey, 'row' => $stubRow, 'mode' => 'huge']);
    $realTransport();
    $message = $refusal();
    T::ok($message !== null && str_contains($message, 'larger than'), 'a response larger than the cap is refused, not buffered');

    $setStub(['key' => $stubKey, 'row' => $stubRow, 'mode' => 'ok']);
    $realTransport();
    C::resolve();
    // Age the cached answer past its fresh window, as a request ten minutes later would find it, then take Console away.
    $aged = json_decode((string) file_get_contents($cacheFile()), true);
    $aged['fetched_at'] = time() - 400;
    file_put_contents($cacheFile(), json_encode($aged));
    C::resetForTesting(false);
    proc_terminate($stub);
    proc_close($stub);
    clearstatcache();
    $logMark = (int) filesize($logFile);
    T::same(['name' => $stubRow['database_name'], 'user' => $stubRow['database_username']], C::resolve(), 'Console gone, the last good answer carries on');
    T::ok(str_contains(substr((string) file_get_contents($logFile), $logMark), 'using the last details it gave'), '…and the outage is logged');
    C::resetForTesting(true);
    $started = microtime(true);
    $message = $refusal();
    T::ok($message !== null && str_contains($message, 'Could not fetch database details from Console') && microtime(true) - $started < 3, 'Console gone with nothing cached: a PDOException, quickly');

    if ($haveDb) {
        [$stub, $stubPort] = $startStub($stubState, $stubLog);
        $runMigrate = static function (string $withKey) use ($stubPort, $testDb): array {
            $proc = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/bin/migrate.php', '--status'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                // A clean environment, as on the server: only what is listed here counts. DB_NAME and DB_USER are junk, and
                // being real environment variables they would beat the .env file's: a script that read them cannot connect.
                ['PATH' => (string) getenv('PATH'), 'APP_ENV' => 'local', 'DB_HOST' => $testDb['host'], 'DB_PORT' => $testDb['port'], 'DB_PASS' => $testDb['pass'],
                    'DB_NAME' => 'junk_db_from_env', 'DB_USER' => 'junk_user_from_env',
                    'CONSOLE_API_URL' => 'http://127.0.0.1:' . $stubPort . '/api', 'CONSOLE_DB_DETAILS_KEY' => $withKey],
            );
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);

            return [proc_close($proc), $out . $err];
        };
        $setEnv(['CONSOLE_API_URL' => 'http://127.0.0.1:' . $stubPort . '/api', 'CONSOLE_DB_DETAILS_KEY' => $stubKey]);
        C::resetForTesting(true);
        $stubCaches[] = $cacheFile();   // bin/migrate.php writes its shared cache file under this URL + key
        $setStub(['key' => $stubKey, 'row' => $stubRow, 'mode' => 'ok']);
        [$code, $out] = $runMigrate($stubKey);
        T::same(0, $code, 'bin/migrate.php connects with Console\'s name and username although DB_NAME / DB_USER name a database that does not exist (exit 0)');
        T::ok(str_contains($out, '001_messaging_channels.sql'), '…and lists the migrations');
        [$code, $out] = $runMigrate('a-key-that-must-not-be-printed');
        T::same(1, $code, 'bin/migrate.php with a wrong key stops (exit 1)');
        T::ok(str_contains($out, 'rejected CONSOLE_DB_DETAILS_KEY') && str_contains($out, 'Reason: console_key_rejected') && !str_contains($out, 'a-key-that-must-not-be-printed'), '…saying why, with the reason, and never printing the key');
        proc_terminate($stub);
        proc_close($stub);
    } else {
        T::skip('bin/migrate.php against the stand-in (needs a PostgreSQL whose DB_NAME ends in _test)');
    }
    foreach ($stubCaches as $path) {
        @unlink($path);
    }
    @unlink($stubState);
    @unlink($stubLog);

    // -----------------------------------------------------------------------
    T::group('Database diagnosis: the request-time answer stays generic');
    // -----------------------------------------------------------------------

    // index.php answers every PDOException a route lets through with a retryable 503 database_unavailable and logs the
    // detail. A failure to obtain the database name is one (it extends PDOException, with no SQLSTATE, like a connection
    // that was never made), so it gets that same answer, and the words the caller reads carry nothing from the exception.
    T::ok(new DatabaseConnectionException('x', 'console_unreachable') instanceof PDOException, 'a failure to obtain the database name is a PDOException, so index.php still catches it');
    $index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
    $caught = preg_match('/catch \(\\\\PDOException \$e\) \{(.*?)\n\} catch/s', $index, $m) === 1 ? $m[1] : '';
    T::ok(str_contains($caught, "Http::error(503, 'database_unavailable'"), 'index.php still answers a PDOException with 503 database_unavailable');
    $answered = preg_match('/Http::error\(503[^;]*;/s', $caught, $m) === 1 ? $m[0] : '';
    T::ok($answered !== '' && !str_contains($answered, '$e') && !str_contains($answered, 'getMessage'), '…with words that carry nothing from the exception (the detail goes to the log only)');

    // -----------------------------------------------------------------------
    T::group('Database diagnosis: the operator log carries the reason, never a secret');
    // -----------------------------------------------------------------------

    $log = (string) file_get_contents($logFile);
    T::ok(str_contains($log, '[health] database unreachable [console_key_rejected]'), 'health logs why it failed, with the reason: console_key_rejected');
    T::ok(str_contains($log, '[health] database unreachable [not_configured]') && str_contains($log, '[health] database unreachable [console_key_missing]'), '…and not_configured, console_key_missing');
    if ($haveDb) {
        T::ok(str_contains($log, '[messaging-db] connection refused [database_login_refused]'), 'a refused connection is logged with its reason');
        T::ok(str_contains($log, 'password authentication failed'), 'the driver\'s own text is there too, for the person who can read the log');
        T::ok(!str_contains($log, $wrongPass), 'but not the wrong password the case tried');
    }
    T::ok(!str_contains($log, $consoleKey) && !str_contains($log, $estateKey), 'and neither Console\'s key nor the estate key is in it');

    $restore();
})();

ini_set('error_log', $previousLog);
@unlink($logFile);

echo sprintf("\n%d passed, %d failed%s\n", T::$passed, T::$failed, T::$skipped > 0 ? ', ' . T::$skipped . ' skipped' : '');
exit(T::$failed === 0 ? 0 : 1);
