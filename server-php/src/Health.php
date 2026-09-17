<?php

declare(strict_types=1);

namespace Aicountly\Api;

use PDOException;

/**
 * What /api/health can honestly say about Messaging's database.
 *
 * Nothing this product currently serves depends on it — every existing route
 * is portal-relay only — so this never gates `status`. It exists so a
 * database, once one is added here, is visible and debuggable from day one
 * rather than silently broken until the first feature that needs it ships.
 *
 * This endpoint is UNAUTHENTICATED and public, so failures are categorised
 * rather than passed through: a driver message like "Access denied for user
 * 'x'@'y'" names the account and host to anyone who asks. The full message
 * goes to the error log, where the person fixing it can read it.
 */
final class Health
{
    /** Migration bookkeeping table for this product. */
    private const MIGRATIONS_TABLE = 'messaging_sql_migrations';

    /**
     * @return array<string, mixed>
     */
    public static function database(): array
    {
        try {
            $pdo = Db::connect();
        } catch (PDOException $e) {
            return [
                'reachable' => false,
                'reason'    => self::categorise($e->getMessage()),
                'schema'    => null,
            ];
        } catch (\Throwable $e) {
            error_log('[health] database check failed: ' . $e->getMessage());

            return ['reachable' => false, 'reason' => 'error', 'schema' => null];
        }

        $onDisk = count(glob(__DIR__ . '/../database/migrations/*.sql') ?: []);

        try {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::MIGRATIONS_TABLE)->fetchColumn();
        } catch (\Throwable) {
            // No bookkeeping table means migrate.php has never run here. That
            // is a normal state on a host that has not been given a schema
            // yet, not an error worth logging.
            $applied = 0;
        }

        return [
            'reachable' => true,
            'reason'    => null,
            'schema'    => [
                'applied' => $applied,
                'pending' => max(0, $onDisk - $applied),
                // The one field worth reading at a glance: is there a schema
                // on disk, and has it all been applied here.
                'ready'   => $onDisk > 0 && $applied >= $onDisk,
            ],
        ];
    }

    /**
     * A category a stranger may see, from a message they may not.
     *
     * The full driver text is logged, because the person who has to fix this
     * needs the account and host names that the category deliberately omits.
     */
    private static function categorise(string $message): string
    {
        error_log('[health] database unreachable: ' . $message);

        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'not configured')        => 'not_configured',
            str_contains($m, 'access denied')         => 'refused',
            str_contains($m, 'unknown database')      => 'no_such_database',
            str_contains($m, 'connection refused'),
            str_contains($m, "can't connect"),
            str_contains($m, 'timeout')                => 'unreachable',
            str_contains($m, 'could not find driver')  => 'driver_missing',
            default                                    => 'error',
        };
    }
}
