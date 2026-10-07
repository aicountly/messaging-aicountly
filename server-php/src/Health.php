<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Channels\ChannelRegistry;
use PDOException;

/**
 * What /api/health can honestly say about this deployment.
 *
 * "The process is up" and "the app works" are different claims and only one of
 * them is useful. A deploy can go green, and a monitor stay quiet, on an app
 * whose every real endpoint answers 503 because its database was never created.
 *
 * WHAT IT MUST NOT SAY. This endpoint is UNAUTHENTICATED and public. The
 * driver's own message names the database, the role and the host — PostgreSQL's
 * "no pg_hba.conf entry for host X, user Y, database Z" hands all three to
 * anyone who asks. So the reason is reduced to a category here and the detail
 * goes to the error log, where the person fixing it can read it and a passer-by
 * cannot.
 *
 * It reports channel and integration configuration as BOOLEANS. Never a token,
 * never a phone number, never a WhatsApp business account id — those identify
 * the tenant and are configuration, not liveness.
 */
final class Health
{
    private const MIGRATIONS_TABLE = 'messaging_sql_migrations';

    /**
     * `source` says where the database name and username came from: "console" (CONSOLE_API_URL and
     * CONSOLE_DB_DETAILS_KEY are both set, so Console is asked) or "env" (DB_NAME / DB_USER in api/.env).
     * "env" on a deployed server is the sign that Console is not in use, whatever else is configured.
     *
     * A failure to get the name and username (Console refused, unreachable, or no key to ask with) is
     * reported by its own reason (console_key_missing, console_key_rejected, ...) with a fixed `hint` saying
     * what to do (DatabaseDiagnosis). Both come from a fixed list and can never echo a key or a name.
     *
     * @return array<string, mixed>
     */
    public static function database(): array
    {
        $source = ConsoleDatabaseDetails::isConfigured() ? 'console' : 'env';

        try {
            $pdo = Db::connect();
        } catch (PDOException $e) {
            $out = [
                'reachable' => false,
                'source'    => $source,
                'reason'    => self::categorise($e),
                'schema'    => null,
            ];
            if ($e instanceof DatabaseConnectionException) {
                $out['hint'] = DatabaseDiagnosis::hint($e->category);
            }

            return $out;
        } catch (\Throwable $e) {
            error_log('[health] database check failed: ' . $e->getMessage());

            return ['reachable' => false, 'source' => $source, 'reason' => 'error', 'schema' => null];
        }

        $onDisk = count(glob(__DIR__ . '/../database/migrations/*.sql') ?: []);

        try {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::MIGRATIONS_TABLE)->fetchColumn();
        } catch (\Throwable) {
            // No bookkeeping table means migrate.php has never run here. That is
            // a normal state on a host somebody has only just created.
            $applied = 0;
        }

        return [
            'reachable' => true,
            'source'    => $source,
            'reason'    => null,
            'schema'    => [
                'applied' => $applied,
                'pending' => max(0, $onDisk - $applied),
                'ready'   => $onDisk > 0 && $applied >= $onDisk,
            ],
        ];
    }

    /**
     * Whether each sibling product is configured — never how.
     *
     * None of these is required. Messaging with no Books still answers
     * messages; it simply cannot show what a customer owes, and it says so.
     * That is the difference between a degraded panel and a broken product, and
     * it is why nothing here feeds a `usable` flag except the database.
     *
     * @return array<string, mixed>
     */
    public static function integrations(): array
    {
        $out = [];
        foreach (['contacts', 'books', 'sales', 'pay', 'appointments', 'drive', 'reach', 'billing', 'ai'] as $name) {
            $out[$name] = [
                'required'   => false,
                'configured' => Features::enabled($name),
            ];
        }

        return $out;
    }

    /**
     * Which channels this deployment could send on.
     *
     * `configured` means credentials are present, NOT that the provider
     * answered — a health check that called Meta on every hit would make an
     * uptime monitor into a rate-limit problem. Live provider state belongs to
     * Channels & Trust, which reads it on request for somebody who asked.
     *
     * @return array<string, mixed>
     */
    public static function channels(): array
    {
        try {
            return ChannelRegistry::healthSummary();
        } catch (\Throwable $e) {
            error_log('[health] channel summary failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * A category a stranger may see, from a message they may not.
     *
     * The full driver text is logged, because the person who has to fix this
     * needs the database and role names that the category deliberately omits.
     */
    private static function categorise(PDOException $e): string
    {
        // A failure to obtain the database name / username already has its category (and its message is
        // ours, never the driver's): use it as it is, so Console's refusals are not squeezed into "error".
        if ($e instanceof DatabaseConnectionException) {
            error_log('[health] database unreachable [' . $e->category . ']: ' . $e->getMessage());

            return $e->category;
        }

        $message = $e->getMessage();
        error_log('[health] database unreachable: ' . $message);

        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'not configured')       => 'not_configured',
            str_contains($m, 'pg_hba'),
            str_contains($m, 'password authentication'),
            str_contains($m, 'role ') && str_contains($m, 'does not exist') => 'refused',
            str_contains($m, 'does not exist')       => 'no_such_database',
            str_contains($m, 'connection refused'),
            str_contains($m, 'could not connect'),
            str_contains($m, 'timeout')              => 'unreachable',
            str_contains($m, 'could not find driver') => 'driver_missing',
            default                                   => 'error',
        };
    }
}
