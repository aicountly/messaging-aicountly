<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Support\Clock;

/**
 * The company's plan for Messaging, as Manage states it, and the limits Messaging enforces from it.
 *
 * Manage's companyinfo (asked naming Messaging — X-Saas-Origin: messaging, Context::assertAllowed)
 * carries, for a Manage that has plans (CONTRACTS §3.5):
 *
 *   "plan": {"code","label","is_default","status","ends_at","entitlements": {"<code>": <value>, …}}
 *
 * (entitlements may also arrive as a list [{code, value}] — both are read). Values are data in
 * Engage, never code here (§3.6). Messaging enforces:
 *
 *   messaging.max_channels             active channel connections (WhatsApp/SMS/RCS senders) of the
 *                                      company — POST v1/channels
 *   messaging.max_messages_per_month   outbound messages put on the send queue this calendar month
 *                                      (Asia/Kolkata), whoever sent them — POST v1/conversations/{c}/send
 *
 * An over-limit write answers 402 PLAN_LIMIT_REACHED {code, limit, used}. Inbound delivery is never
 * limited (provider webhooks carry no plan and are never checked), nor are reads, exports, deletes,
 * drafts or a product's service-key sends (they carry no plan). Nothing existing is touched: a company
 * over a limit after a downgrade keeps every channel and message; only a new one beyond it is refused.
 *
 * Unlimited unless a limit is stated: no `plan` (a Manage that predates plans), no entitlements, an
 * absent code, or a value that is not a whole number ≥ 0 — today's behaviour.
 */
final class PlanEntitlements
{
    public const ERROR_CODE = 'PLAN_LIMIT_REACHED';

    public const MAX_CHANNELS = 'messaging.max_channels';
    public const MAX_MESSAGES_PER_MONTH = 'messaging.max_messages_per_month';

    /** The calendar a "month" is counted in. */
    public const MONTH_TIMEZONE = 'Asia/Kolkata';

    /** @var array<string, array{0: string, 1: string}> */
    private const NOUNS = [
        self::MAX_CHANNELS           => ['channel connection', 'channel connections'],
        self::MAX_MESSAGES_PER_MONTH => ['outgoing message a month', 'outgoing messages a month'],
    ];

    /**
     * The plan in a companyinfo answer (the whole body, or its `data` / `company` row), normalised;
     * null when Manage said nothing about plans.
     *
     * @param array<mixed>|null $body
     * @return array{code: string, label: string, is_default: bool, status: ?string, ends_at: ?string, entitlements: array<string, mixed>}|null
     */
    public static function fromCompanyInfo(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }
        $data = is_array($body['data'] ?? null) && !array_is_list($body['data']) ? $body['data'] : [];
        $company = is_array($body['company'] ?? null) ? $body['company'] : [];
        foreach ([$data, $company, $body] as $source) {
            if (array_key_exists('plan', $source)) {
                return self::normalize($source['plan']);
            }
        }

        return null;
    }

    /**
     * @return array{code: string, label: string, is_default: bool, status: ?string, ends_at: ?string, entitlements: array<string, mixed>}|null
     */
    public static function normalize(mixed $plan): ?array
    {
        if (!is_array($plan) || ($plan !== [] && array_is_list($plan))) {
            return null;
        }
        $entitlements = [];
        $raw = $plan['entitlements'] ?? [];
        if (is_array($raw)) {
            if (array_is_list($raw)) {
                foreach ($raw as $e) {
                    if (is_array($e) && is_string($e['code'] ?? null) && $e['code'] !== '') {
                        $entitlements[$e['code']] = $e['value'] ?? null;
                    }
                }
            } else {
                foreach ($raw as $code => $value) {
                    if (is_string($code) && $code !== '') {
                        $entitlements[$code] = $value;
                    }
                }
            }
        }

        return [
            'code'         => is_scalar($plan['code'] ?? null) ? (string) $plan['code'] : '',
            'label'        => is_scalar($plan['label'] ?? null) ? (string) $plan['label'] : '',
            'is_default'   => ($plan['is_default'] ?? false) === true,
            'status'       => is_string($plan['status'] ?? null) ? $plan['status'] : null,
            'ends_at'      => is_string($plan['ends_at'] ?? null) ? $plan['ends_at'] : null,
            'entitlements' => $entitlements,
        ];
    }

    /** The numeric limit for $code, or null for "no limit". */
    public static function limit(?array $plan, string $code): ?int
    {
        if ($plan === null || !is_array($plan['entitlements'] ?? null) || !array_key_exists($code, $plan['entitlements'])) {
            return null;
        }
        $value = $plan['entitlements'][$code];
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value) && $value >= 0 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * Whether a feature (`feature.<name>`) is on. Absent = on (§3.6); only an explicit false / 0 /
     * "false" / "off" switches it off.
     */
    public static function allows(?array $plan, string $featureCode): bool
    {
        if ($plan === null || !is_array($plan['entitlements'] ?? null) || !array_key_exists($featureCode, $plan['entitlements'])) {
            return true;
        }
        $value = $plan['entitlements'][$featureCode];
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['false', '0', 'off', 'no'], true);
        }

        return !($value === false || $value === 0 || $value === 0.0);
    }

    /**
     * The refusal's details when one more would go past the limit, otherwise null. $used is only
     * counted when a limit applies.
     *
     * @param int|callable(): int $used
     * @return array{code: string, limit: int, used: int}|null
     */
    public static function exceeded(?array $plan, string $code, int|callable $used): ?array
    {
        $limit = self::limit($plan, $code);
        if ($limit === null) {
            return null;
        }
        $count = is_callable($used) ? (int) $used() : $used;

        return $count >= $limit ? ['code' => $code, 'limit' => $limit, 'used' => $count] : null;
    }

    public static function message(string $code, int $limit): string
    {
        [$one, $many] = self::NOUNS[$code] ?? ['item', 'items'];

        return sprintf('Your plan allows %d %s — ask the account owner to upgrade in Manage.', $limit, $limit === 1 ? $one : $many);
    }

    /**
     * Ends the request with 402 PLAN_LIMIT_REACHED when the company's plan caps $code and one more
     * would go past it. A product's service key is never limited here (it carries no plan).
     *
     * @param callable(): int $used
     */
    public static function assertWithin(Context $ctx, Auth $auth, string $code, callable $used): void
    {
        if ($auth->isService()) {
            return;
        }
        $details = self::exceeded($ctx->plan($auth), $code, $used);
        if ($details !== null) {
            Http::error(402, self::ERROR_CODE, self::message($code, $details['limit']), $details);
        }
    }

    /** Active channel connections of the company (every branch). */
    public static function activeChannels(int $cmpId): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM messaging_channel_connections WHERE cmp_id = :cmp AND is_active = TRUE',
            ['cmp' => $cmpId],
        );
    }

    /**
     * Outbound messages of the company put on the send queue since the start of this calendar month
     * (MONTH_TIMEZONE): every origin — agents, journeys, other products — since they all reach a
     * customer. Inbound messages are never counted.
     */
    public static function messagesQueuedThisMonth(int $cmpId): int
    {
        $start = Clock::now()->setTimezone(new \DateTimeZone(self::MONTH_TIMEZONE))->modify('first day of this month')->setTime(0, 0);

        return (int) Db::scalar(
            "SELECT COUNT(*) FROM messaging_messages
              WHERE cmp_id = :cmp AND direction = 'outbound' AND queued_at IS NOT NULL
                AND queued_at >= CAST(:start AS timestamptz)",
            ['cmp' => $cmpId, 'start' => $start->format(\DATE_ATOM)],
        );
    }
}
