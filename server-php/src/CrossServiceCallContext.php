<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Who issued the request this process is serving, so we never call them back
 * inside it.
 *
 * NOT a recursion guard — nothing recurses. Each AICOUNTLY product runs in its
 * own PHP-FPM pool with a small pm.max_children, and a synchronous HTTP call
 * parks the calling worker for the whole round trip. When A calls B and B calls
 * A back, three workers across two pools serve one user action and two of them
 * are sitting in read(). At a handful of concurrent users every child in both
 * pools is blocked on the other and the queue answers 504. A depth counter
 * inside one process cannot see this, and raising pm.max_children only makes
 * the deadlock more expensive to reach.
 *
 * See books-react-app/docs/CROSS_SERVICE_CALL_RULES.md — this is the fleet-wide
 * invariant that document records.
 *
 * Messaging is unusually exposed to this because almost every product in the
 * fleet has a reason to send a message. Appointments posts a reminder here and
 * Messaging draws a reminder-performance panel from Appointments; Billing asks
 * for a dues reminder and Messaging reads the invoice from Books. Without this
 * guard a reminder arriving from Appointments would have Messaging calling
 * Appointments back inside the request Appointments is still blocked on.
 *
 * The header GRANTS NOTHING. It can only ever suppress one of our outbound
 * calls, so forging it costs the forger enrichment they wanted; it cannot
 * create access, a row or a permission. Unknown values are ignored.
 */
final class CrossServiceCallContext
{
    public const HEADER = 'X-Saas-Origin';

    /** Products whose name we honour in the header. */
    private const KNOWN = [
        'books', 'inventory', 'manage', 'contacts', 'pos', 'sales', 'purchases',
        'billing', 'crm', 'calendar', 'appointments', 'receptionist', 'pay',
        'connect', 'messaging', 'reach', 'voice', 'console', 'docs', 'vault',
        'email', 'lobby', 'remote',
    ];

    private static ?string $inbound = null;
    private static bool $resolved = false;

    /** The calling product, or null when a browser (or an unknown name) called us. */
    public static function inbound(): ?string
    {
        if (self::$resolved) {
            return self::$inbound;
        }
        self::$resolved = true;

        $claimed = strtolower(Http::header(self::HEADER));
        self::$inbound = in_array($claimed, self::KNOWN, true) ? $claimed : null;

        return self::$inbound;
    }

    public static function isInboundFrom(string $app): bool
    {
        return self::inbound() === strtolower($app);
    }

    /**
     * Record the caller proven by its service key.
     *
     * Stronger than the header, and the reason a forged header buys nothing:
     * where a service key is presented, the key decides.
     */
    public static function adoptAuthenticatedOrigin(string $app): void
    {
        $app = strtolower(trim($app));
        if (in_array($app, self::KNOWN, true)) {
            self::$inbound = $app;
            self::$resolved = true;
        }
    }

    /**
     * Forget the resolved origin. CLI ONLY.
     *
     * One test has to prove the re-entry guard fires, and without this the
     * origin it set would still be in place for every test after it — the guard
     * would then suppress calls those tests need, and the failure would look
     * like a broken client rather than a leaked fixture.
     */
    public static function resetForTesting(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$inbound = null;
        self::$resolved = false;
    }
}
