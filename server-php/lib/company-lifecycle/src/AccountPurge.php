<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * account_purge_requested: an AICOUNTLY account was closed (my.aicountly's day-45 purge) and this
 * product must delete the data that person owns that is NOT company data — the manifest's
 * user_tables: personal settings, personal notes / calendars / chats / files, AI history, sessions,
 * device tokens. Rows inside companies stay with the company (companies the person owned arrive as
 * separate company_purge_requested events, reason account_closed). user_retain tables (e.g. sign-in
 * security logs a statute requires) are kept and reported.
 *
 * Data outside the database (uploaded files, push registrations at a provider, a search index) is
 * the product's to remove: pass hooks, callable(string $uuid, array $purgeResult): ?string — return a
 * line for the ack detail, or throw to fail the attempt (it is retried, then acked failed).
 */
final class AccountPurge
{
    /** @param list<callable(string, array<string,mixed>): ?string> $hooks */
    public function __construct(private PurgeEngine $engine, private array $hooks = [])
    {
    }

    /** my.aicountly subscriber ids: positive integers in canonical form (Manage's validUuid). */
    public static function validUuid(mixed $uuid): ?string
    {
        $uuid = is_scalar($uuid) ? trim((string) $uuid) : '';

        return preg_match('/^[1-9][0-9]{0,18}$/', $uuid) === 1 ? $uuid : null;
    }

    /**
     * @return array{status:string, detail:string, archive:?string, rows:int, retained:list<string>, ok:bool}
     */
    public function run(string $uuid): array
    {
        $uuid = self::validUuid($uuid);
        if ($uuid === null) {
            return ['ok' => false, 'status' => 'failed', 'detail' => 'Not purged: the uuid is not a subscriber id.', 'archive' => null, 'rows' => 0, 'retained' => []];
        }
        if ($this->engine->manifest()->tables('user') === [] && $this->hooks === []) {
            // A product that keeps nothing personal answers that honestly.
            return ['ok' => true, 'status' => 'nothing_to_purge', 'detail' => 'This application keeps no personal data outside companies (manifest user_tables is empty).',
                'archive' => null, 'rows' => 0, 'retained' => self::retainedNames($this->engine->plan('user', $uuid))];
        }
        $result = $this->engine->purge('user', $uuid);

        return Worker::outcome($result, 'person', $this->hooks, $uuid);
    }

    /**
     * @param array<string,mixed> $result
     * @return list<string>
     */
    private static function retainedNames(array $result): array
    {
        return array_keys($result['retained'] ?? []);
    }
}
