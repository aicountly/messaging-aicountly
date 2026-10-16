<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * For SENDERS: what a product's outbox does when another product answers a machine write with
 *
 *     HTTP 423 {code: COMPANY_RESTRICTED, message, restricted_until, lifecycle}
 *
 * (the company is read-only THERE — its subscription to that product lapsed, or it is being purged
 * there). The rule every AICOUNTLY sender follows (Books / Inventory / Pay / Sales / Purchases / POS /
 * Billing / FR, docs/company-lifecycle in Manage):
 *
 *   - deferred, not failed: the item becomes `deferred_restricted` (no attempt spent, no alert), with
 *     the company's other items for the same receiver;
 *   - it is not sent again until restricted_until passes or Manage says the company is active again —
 *     Manage's internal/company-status is asked at most hourly per company (RECHECK_SECONDS);
 *   - `lifecycle: purged` from the receiver, or Manage reporting the company deleted / unknown, makes it
 *     `dropped_company_deleted` — keep an audit entry: never lost without a trace.
 *
 * The decisions are pure (refusal(), decide(), nextCheckAt()); review() asks Manage (service key) and
 * decides. Store the state in the sender's own outbox: status, restricted_until, next_check_at.
 *
 *   $answer = DeferredSend::classify($httpStatus, $responseBody, time());
 *   // ['action' => 'deliver'|'defer'|'drop'|'other', 'restricted_until', 'next_check_at', 'reason']
 *   … hourly, for deferred companies whose next_check_at is due:
 *   $d = $kit->deferredSend()->review($cmpId, 'books', $storedUntil);   // resume | wait | drop
 */
final class DeferredSend
{
    public const CODE = 'COMPANY_RESTRICTED';

    public const DEFERRED_RESTRICTED = 'deferred_restricted';

    public const DROPPED_COMPANY_DELETED = 'dropped_company_deleted';

    /** Manage is asked about a deferred company at most this often. */
    public const RECHECK_SECONDS = 3600;

    /** @var callable */
    private $http;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable|null $http see Http::request()
     * @param (callable(): int)|null $clock
     */
    public function __construct(private Config $config, ?callable $http = null, ?callable $clock = null)
    {
        $this->http = $http ?? [Http::class, 'request'];
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The receiver's read-only answer, or null for any other answer. $body: decoded JSON or the raw text.
     *
     * @return array{restricted_until: ?string, lifecycle: string, message: string}|null
     */
    public static function refusal(int $status, mixed $body): ?array
    {
        if (is_string($body)) {
            $body = json_decode($body, true);
        }
        if ($status !== 423 || !is_array($body)) {
            return null;
        }
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        if (($body['code'] ?? ($error['code'] ?? null)) !== self::CODE) {
            return null;
        }
        $until = $body['restricted_until'] ?? ($error['restricted_until'] ?? ($details['restricted_until'] ?? null));
        $lifecycle = $body['lifecycle'] ?? ($error['lifecycle'] ?? ($details['lifecycle'] ?? 'restricted'));
        $message = $body['message'] ?? ($error['message'] ?? null);

        return [
            'restricted_until' => is_string($until) && trim($until) !== '' ? trim($until) : null,
            'lifecycle'        => is_string($lifecycle) && $lifecycle !== '' ? strtolower($lifecycle) : 'restricted',
            'message'          => is_string($message) && trim($message) !== '' ? trim($message) : 'The company is read-only there.',
        ];
    }

    /** The receiver no longer holds the company. */
    public static function isGone(array $refusal): bool
    {
        return ($refusal['lifecycle'] ?? '') === 'purged';
    }

    /**
     * One receiver answer → what the outbox does with the item.
     *
     * @return array{action:string, restricted_until:?string, next_check_at:?int, reason:string}
     *   deliver (2xx), defer (423 COMPANY_RESTRICTED: status deferred_restricted, look again at
     *   next_check_at), drop (423 and purged there: dropped_company_deleted), other (the outbox's own
     *   retry rules apply)
     */
    public static function classify(int $status, mixed $body, int $now): array
    {
        if ($status >= 200 && $status < 300) {
            return ['action' => 'deliver', 'restricted_until' => null, 'next_check_at' => null, 'reason' => 'delivered'];
        }
        $refusal = self::refusal($status, $body);
        if ($refusal === null) {
            return ['action' => 'other', 'restricted_until' => null, 'next_check_at' => null, 'reason' => 'HTTP ' . $status];
        }
        if (self::isGone($refusal)) {
            return ['action' => 'drop', 'restricted_until' => $refusal['restricted_until'], 'next_check_at' => null, 'reason' => 'the receiver no longer holds this company (purged there)'];
        }

        return ['action' => 'defer', 'restricted_until' => $refusal['restricted_until'], 'next_check_at' => self::nextCheckAt($refusal['restricted_until'], $now),
            'reason' => $refusal['message']];
    }

    /** At restricted_until if that is within the hour, otherwise in an hour. */
    public static function nextCheckAt(?string $restrictedUntil, int $now): int
    {
        $hour = $now + self::RECHECK_SECONDS;
        $until = self::timestamp($restrictedUntil);

        return $until !== null && $until > $now ? min($until, $hour) : $hour;
    }

    /**
     * resume (send now) | drop (company deleted) | wait (look again at next_check_at).
     *
     * @param array{known: bool, status?: ?string, lifecycle?: ?string, restricted_until?: ?string} $manage
     * @return array{action: string, restricted_until: ?string, next_check_at: ?int, reason: string}
     */
    public static function decide(array $manage, ?string $storedUntil, int $now): array
    {
        $wait = static fn (?string $until, string $why): array => ['action' => 'wait', 'restricted_until' => $until, 'next_check_at' => self::nextCheckAt($until, $now), 'reason' => $why];
        $resume = static fn (?string $until, string $why): array => ['action' => 'resume', 'restricted_until' => $until, 'next_check_at' => null, 'reason' => $why];
        $passed = static fn (?string $until): bool => ($t = self::timestamp($until)) !== null && $t <= $now;

        if (!($manage['known'] ?? false)) {
            return $passed($storedUntil)
                ? $resume($storedUntil, 'restricted_until has passed (Manage could not be asked)')
                : $wait($storedUntil, 'Manage could not be asked; still before restricted_until');
        }
        $status = strtolower((string) ($manage['status'] ?? ''));
        if (in_array($status, ['deleted', 'unknown'], true)) {
            return ['action' => 'drop', 'restricted_until' => $storedUntil, 'next_check_at' => null, 'reason' => 'Manage reports the company ' . $status];
        }
        $lifecycle = strtolower((string) ($manage['lifecycle'] ?? ''));
        $until = is_string($manage['restricted_until'] ?? null) && $manage['restricted_until'] !== '' ? $manage['restricted_until'] : $storedUntil;

        return match (true) {
            $lifecycle === 'active' && $status !== 'recycled' => $resume(null, 'Manage reports the company active'),
            $lifecycle === 'purge_pending'                      => $resume($until, 'purge pending: one delivery asks the receiver whether it has purged'),
            $passed($until)                                     => $resume($until, 'restricted_until has passed'),
            default                                             => $wait($until, 'Manage reports the company ' . ($lifecycle !== '' ? $lifecycle : ($status !== '' ? $status : 'not active'))),
        };
    }

    /**
     * Manage's view of the company in the RECEIVING application (GET internal/company-status?ids=&app=,
     * Bearer MANAGE_PRODUCT_SERVICE_KEY).
     *
     * @return array{known:bool, status:?string, lifecycle:?string, restricted_until:?string, error:?string}
     */
    public function companyStatus(int $cmpId, string $receiverApp): array
    {
        $url = $this->config->companyStatusUrlFor($cmpId, $receiverApp);
        if ($this->config->serviceKey === null || $url === null) {
            return ['known' => false, 'status' => null, 'lifecycle' => null, 'restricted_until' => null, 'error' => 'MANAGE_PRODUCT_SERVICE_KEY or MANAGE_API_URL is not set'];
        }
        $res = ($this->http)('GET', $url, ['Accept: application/json', 'Authorization: Bearer ' . $this->config->serviceKey], null, 10);
        $status = (int) ($res['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return ['known' => false, 'status' => null, 'lifecycle' => null, 'restricted_until' => null, 'error' => $status === 0 ? 'no answer' : 'Manage answered ' . $status];
        }
        $decoded = json_decode((string) ($res['body'] ?? ''), true);
        $rows = is_array($decoded) ? ($decoded['data']['companies'] ?? null) : null;
        if (!is_array($rows)) {
            return ['known' => false, 'status' => null, 'lifecycle' => null, 'restricted_until' => null, 'error' => 'answer not understood'];
        }
        $row = $rows[(string) $cmpId] ?? null;
        if (!is_array($row)) {
            return ['known' => true, 'status' => 'unknown', 'lifecycle' => null, 'restricted_until' => null, 'error' => null];
        }
        $str = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? strtolower(trim($v)) : null;

        return [
            'known'            => true,
            'status'           => $str($row['status'] ?? null),
            'lifecycle'        => $str($row['lifecycle'] ?? null),
            'restricted_until' => is_string($row['restricted_until'] ?? null) && $row['restricted_until'] !== '' ? $row['restricted_until'] : null,
            'error'            => null,
        ];
    }

    /**
     * The hourly re-check of one deferred company: ask Manage, decide.
     *
     * @return array{action: string, restricted_until: ?string, next_check_at: ?int, reason: string}
     */
    public function review(int $cmpId, string $receiverApp, ?string $storedUntil): array
    {
        return self::decide($this->companyStatus($cmpId, $receiverApp), $storedUntil, ($this->clock)());
    }

    public static function timestamp(?string $iso): ?int
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }
        $t = strtotime($iso);

        return $t === false ? null : $t;
    }
}
