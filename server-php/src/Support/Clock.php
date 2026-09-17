<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * The current time, in one place so a test can move it.
 *
 * Messaging turns on time in ways that are awkward to test against the real
 * clock: a quiet-hours window that must not be sent into, a response-target
 * breach, an attribution window that closes, a journey delay step. Freezing the
 * clock is how those become assertions rather than sleeps.
 *
 * Everything stored is UTC and every column is `TIMESTAMPTZ`. A business
 * timezone (`Asia/Kolkata` by default) is a *presentation* and *policy* concern
 * — which day a metric falls in, whether 21:30 local is inside quiet hours —
 * and it is applied explicitly where it matters, never by storing local time.
 */
final class Clock
{
    private static ?\DateTimeImmutable $frozen = null;

    public static function now(): \DateTimeImmutable
    {
        return self::$frozen ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** `2026-09-17 11:42:03+00` — the form every TIMESTAMPTZ binding uses. */
    public static function nowSql(): string
    {
        return self::now()->format('Y-m-d H:i:sP');
    }

    public static function iso(?\DateTimeInterface $at = null): string
    {
        return ($at ?? self::now())->format('c');
    }

    /** CLI only. A frozen clock in a web process would be a bug that only shows up at midnight. */
    public static function freeze(?string $iso): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$frozen = $iso === null ? null : new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    /** Parse an instant from a request, or null when it is not a usable one. */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
