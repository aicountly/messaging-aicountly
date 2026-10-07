<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Which of several different problems is behind "the database is unavailable".
 *
 * Every one of them used to look the same from outside: index.php answers any PDOException with
 * 503 database_unavailable, and /api/health said "not reachable or not migrated". But a Console
 * key that was revoked, a database password that is wrong, a database that was never created, a
 * schema that was never migrated and a migration that was never run after a deploy are fixed in
 * five different places, and guessing between them is how a deployment gets re-deployed five times
 * without changing anything.
 *
 * classify() returns a category and a FIXED sentence saying what to do. The sentence is chosen from
 * the category, never built from the exception, so it cannot carry a DSN, a host, a user name, a
 * password or Console's key to an unauthenticated /api/health. The exception's own text goes to the
 * error log only.
 */
final class DatabaseDiagnosis
{
    /** @var array<string, string> category => what to do about it */
    private const HINTS = [
        'not_configured' => 'No database is configured. Set CONSOLE_API_URL and CONSOLE_DB_DETAILS_KEY (or DB_NAME and DB_USER) in api/.env.',
        'console_key_missing' => 'CONSOLE_API_URL is set but CONSOLE_DB_DETAILS_KEY is not, so Console is never asked for the database. Generate a key for this deployment in Console > SaaS Database Details (it starts with sdb_) and set it as CONSOLE_DB_DETAILS_KEY. CONSOLE_SERVICE_KEY is not used for this, and Console does not accept it.',
        'console_url_missing' => 'CONSOLE_DB_DETAILS_KEY is set but CONSOLE_API_URL is not. Set CONSOLE_API_URL to the Console API base including /api (https://console.aicountly.org/api).',
        'invalid_sslmode' => 'DB_SSLMODE in api/.env is not one of disable, allow, prefer, require, verify-ca, verify-full.',
        'console_config' => 'CONSOLE_API_URL or CONSOLE_DB_DETAILS_KEY in api/.env is malformed: the URL must start with https:// and the key must have no spaces or line breaks.',
        'console_key_rejected' => 'Console rejected CONSOLE_DB_DETAILS_KEY (revoked, rotated or wrong). Generate a key for this deployment in Console > SaaS Database Details and put it in api/.env.',
        'console_row_inactive' => 'Console reports this product\'s database row as inactive. Activate it in Console > SaaS Database Details.',
        'console_unreachable' => 'This server could not reach Console to ask for the database name and username. Check CONSOLE_API_URL and that the server may make outbound HTTPS calls.',
        'console_unexpected_answer' => 'Console answered, but not with a usable database name and username. Check this product\'s row in Console > SaaS Database Details.',
        'console_no_database_recorded' => 'Console has no database name and username recorded for this product. Record them on its row in Console > SaaS Database Details.',
        'console_environment_mismatch' => 'The Console key belongs to the other environment (production vs sandbox). Use the key generated on this deployment\'s own row.',
        'driver_missing' => 'PHP\'s pdo_pgsql extension is not enabled on this server.',
        'database_unreachable' => 'This server could not reach the PostgreSQL server. Check DB_HOST and DB_PORT in api/.env.',
        'database_ssl' => 'SSL negotiation with PostgreSQL failed. Check DB_SSLMODE in api/.env against what the PostgreSQL server supports.',
        'database_login_refused' => 'PostgreSQL refused the login. DB_PASS in api/.env must be the password of the database user (the one Console records for this row, or DB_USER).',
        'database_not_found' => 'PostgreSQL has no database with that name. Create it in cPanel, or correct the name on this product\'s row in Console > SaaS Database Details.',
        'database_permission_denied' => 'The database user has no privileges on this database. Grant it all privileges on the database in cPanel.',
        'not_migrated' => 'Connected, but the schema is not there. On the server run: php api/bin/migrate.php',
        'migrations_pending' => 'Connected, but migrations have not all been applied. On the server run: php api/bin/migrate.php',
        'schema_out_of_date' => 'The database schema is older than this code. On the server run: php api/bin/migrate.php',
        'database_error' => 'The database returned an error. The reason is in the server\'s PHP error log.',
    ];

    /** @return array{reason: string, hint: string} */
    public static function classify(\Throwable $e): array
    {
        if ($e instanceof DatabaseConnectionException) {
            return self::result($e->category);
        }

        $message = $e->getMessage();
        // A query's own SQLSTATE (42P01 undefined table, 42703 undefined column, 42501 no privilege). A failed
        // connection has none in errorInfo, and getCode() is then libpq's 08006 for several different causes,
        // which is why those are told apart by libpq's wording below.
        $state = $e instanceof \PDOException && isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : (string) $e->getCode();

        return self::result(match (true) {
            $state === '42P01' => 'not_migrated',
            $state === '42703' => 'schema_out_of_date',
            $state === '42501' => 'database_permission_denied',
            stripos($message, 'could not find driver') !== false => 'driver_missing',
            // "no pg_hba.conf entry … SSL off" mentions SSL, so a refused login is tested before SSL.
            preg_match('/password authentication failed|no pg_hba\.conf entry|authentication failed|role "[^"]*" does not exist/i', $message) === 1 => 'database_login_refused',
            preg_match('/database "[^"]*" does not exist/i', $message) === 1 => 'database_not_found',
            preg_match('/SSL|sslmode/i', $message) === 1 => 'database_ssl',
            preg_match('/connection refused|could not connect|timeout expired|timed out|no such file or directory|could not translate host name|name or service not known|network is unreachable|could not resolve/i', $message) === 1 => 'database_unreachable',
            default => 'database_error',
        });
    }

    /** The fixed sentence for a category (a category this class does not know is an unexplained database error). */
    public static function hint(string $category): string
    {
        return self::HINTS[$category] ?? self::HINTS['database_error'];
    }

    /** @return list<string> every category this class can answer with */
    public static function categories(): array
    {
        return array_keys(self::HINTS);
    }

    /** @return array{reason: string, hint: string} */
    private static function result(string $category): array
    {
        $category = isset(self::HINTS[$category]) ? $category : 'database_error';

        return ['reason' => $category, 'hint' => self::HINTS[$category]];
    }
}
