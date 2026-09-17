<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * UUID v4 generation and validation.
 *
 * Validation matters as much as generation here. Every identifier this API
 * accepts from a URL or a body reaches a query, and a `uuid` column will reject
 * a malformed value with a PDOException that surfaces as a 503 — "the database
 * is down" for what is really "you sent nonsense". Checking the shape first
 * turns that into the 404 it actually is.
 */
final class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, trim($value)) === 1;
    }

    /** Normalise for storage and comparison. */
    public static function normalise(string $value): string
    {
        return strtolower(trim($value));
    }
}
