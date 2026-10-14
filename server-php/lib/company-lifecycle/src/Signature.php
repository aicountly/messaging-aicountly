<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * The HMAC both directions of the lifecycle contract use (Manage docs/company-lifecycle/README.md §3):
 *
 *   X-Manage-Timestamp: <unix seconds>
 *   X-Manage-Signature: sha256=<hex HMAC-SHA256 of "{timestamp}.{raw body}" with LIFECYCLE_WEBHOOK_SECRET>
 *
 * Manage signs its events with the product's secret; the product signs its ack with the same secret
 * and names itself in X-Product-App. Verification is constant time (hash_equals) and refuses a
 * timestamp more than TOLERANCE seconds from this server's clock either way, so a captured request
 * cannot be replayed later. With no usable secret nothing verifies (NOT_CONFIGURED → 503), never an
 * allow.
 *
 * Kept byte-compatible with Manage's LifecycleSignature: Manage's
 * server-php/tests/company_lifecycle_kit_drift_test.php fails if the two ever disagree.
 */
final class Signature
{
    public const TOLERANCE = 300;

    /** Manage ignores a shorter secret (LifecycleConfig::MIN_SECRET_LENGTH); so does the kit. */
    public const MIN_SECRET_LENGTH = 32;

    public const HEADER_TIMESTAMP = 'X-Manage-Timestamp';

    public const HEADER_SIGNATURE = 'X-Manage-Signature';

    public const HEADER_PRODUCT = 'X-Product-App';

    public const OK = 'ok';
    public const NOT_CONFIGURED = 'lifecycle_not_configured';
    public const MISSING = 'signature_missing';
    public const BAD_TIMESTAMP = 'timestamp_invalid';
    public const STALE = 'timestamp_out_of_window';
    public const BAD_SIGNATURE = 'signature_invalid';

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Headers for an outbound signed POST (the ack), as "Name: value" lines.
     *
     * @return list<string>
     */
    public static function headerLines(string $secret, string $body, int $timestamp): array
    {
        return [
            self::HEADER_TIMESTAMP . ': ' . $timestamp,
            self::HEADER_SIGNATURE . ': ' . self::sign($secret, $timestamp, $body),
        ];
    }

    public static function usableSecret(?string $secret): bool
    {
        return $secret !== null && strlen(trim($secret)) >= self::MIN_SECRET_LENGTH && !str_starts_with(trim($secret), 'CHANGE_ME');
    }

    /**
     * One of the constants above; only OK admits the request. Never echoes the secret or the
     * expected value.
     */
    public static function verify(?string $secret, string $timestampHeader, string $body, string $signatureHeader, ?int $now = null): string
    {
        if (!self::usableSecret($secret)) {
            return self::NOT_CONFIGURED;
        }
        $timestampHeader = trim($timestampHeader);
        $signatureHeader = trim($signatureHeader);
        if ($timestampHeader === '' || $signatureHeader === '') {
            return self::MISSING;
        }
        if (preg_match('/^[0-9]{9,11}$/', $timestampHeader) !== 1) {
            return self::BAD_TIMESTAMP;
        }
        $ts = (int) $timestampHeader;
        if (abs(($now ?? time()) - $ts) > self::TOLERANCE) {
            return self::STALE;
        }
        if (preg_match('/^sha256=[0-9a-f]{64}$/i', $signatureHeader) !== 1) {
            return self::BAD_SIGNATURE;
        }

        return hash_equals(self::sign(trim((string) $secret), $ts, $body), strtolower($signatureHeader)) ? self::OK : self::BAD_SIGNATURE;
    }
}
