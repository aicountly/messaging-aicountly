<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;

/**
 * A date range, resolved in the company's timezone.
 *
 * THE TIMEZONE IS NOT DECORATION. "Today" for a business in Asia/Kolkata starts
 * at 18:30 UTC the previous day. A dashboard that ranges over UTC days shows a
 * business its own afternoon split across two columns, and an attribution window
 * described as "same day" means nothing at all without saying whose day.
 *
 * So every period carries the timezone it was resolved in, and every screen that
 * shows a period says so. The comparison period is the same length immediately
 * before, which is what "vs previous period" on the Command Centre means — not
 * "the same week last month", which is a different claim.
 */
final class Period
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly \DateTimeZone $timezone,
    ) {
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return ['today', 'yesterday', '7d', '30d', '90d', 'this_month', 'last_month'];
    }

    public static function fromRequest(Context $ctx, string $default = '30d'): self
    {
        $requested = (string) (Http::param('period') ?? $default);

        return self::named($ctx, in_array($requested, self::keys(), true) ? $requested : $default);
    }

    public static function named(Context $ctx, string $key): self
    {
        $tz = Settings::timezone($ctx);
        $nowLocal = Clock::now()->setTimezone($tz);
        $startOfToday = $nowLocal->setTime(0, 0);

        [$from, $to, $label] = match ($key) {
            'today'      => [$startOfToday, $startOfToday->modify('+1 day'), 'Today'],
            'yesterday'  => [$startOfToday->modify('-1 day'), $startOfToday, 'Yesterday'],
            '7d'         => [$startOfToday->modify('-6 days'), $startOfToday->modify('+1 day'), 'Last 7 days'],
            '90d'        => [$startOfToday->modify('-89 days'), $startOfToday->modify('+1 day'), 'Last 90 days'],
            'this_month' => [$startOfToday->modify('first day of this month'), $startOfToday->modify('+1 day'), 'This month'],
            'last_month' => [
                $startOfToday->modify('first day of last month'),
                $startOfToday->modify('first day of this month'),
                'Last month',
            ],
            default      => [$startOfToday->modify('-29 days'), $startOfToday->modify('+1 day'), 'Last 30 days'],
        };

        return new self($key, $label, $from, $to, $tz);
    }

    /**
     * The immediately preceding range of the same length.
     *
     * Same length, not "the same period last month". A comparison whose
     * denominator is a different number of days is a comparison that is wrong,
     * and the Command Centre labels this one explicitly as "vs previous period"
     * for that reason.
     */
    public function previous(): self
    {
        $seconds = $this->to->getTimestamp() - $this->from->getTimestamp();

        return new self(
            $this->key . '_previous',
            'Previous ' . strtolower($this->label),
            $this->from->modify('-' . $seconds . ' seconds'),
            $this->from,
            $this->timezone,
        );
    }

    /** UTC bounds for a TIMESTAMPTZ comparison. */
    public function fromSql(): string
    {
        return $this->from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }

    public function toSql(): string
    {
        return $this->to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }

    /** Local dates, for grouping messaging_daily_metrics. */
    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->modify('-1 second')->format('Y-m-d');
    }

    public function days(): int
    {
        return max(1, (int) ceil(($this->to->getTimestamp() - $this->from->getTimestamp()) / 86400));
    }

    /**
     * How a screen describes this period, timezone included.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'key'       => $this->key,
            'label'     => $this->label,
            'from'      => $this->from->format('c'),
            'to'        => $this->to->format('c'),
            'timezone'  => $this->timezone->getName(),
            'days'      => $this->days(),
        ];
    }
}
