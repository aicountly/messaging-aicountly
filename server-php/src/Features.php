<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Which integrations and channels this deployment actually has.
 *
 * THE POINT OF THIS CLASS is to make it impossible to ship a placeholder that
 * looks connected. Messaging depends on two very different kinds of outside
 * thing, and both of them are absent on day one:
 *
 *   - CHANNELS. A messaging product with no WhatsApp credentials cannot send a
 *     WhatsApp message. The composer is disabled, the channel card says what is
 *     missing, and the rest of the screen still works.
 *   - SIBLING PRODUCTS. Contacts owns who the customer is, Books owns what they
 *     owe, Pay owns the payment link. Each has a real client here, a real
 *     contract, and a flag that is OFF until the other side answers — so the
 *     inbox says "Aicountly Pay is not connected" rather than drawing a draft
 *     that claims a payment link is attached.
 *
 * A flag is on only when it has been turned on AND the thing it gates is
 * configured. `MESSAGING_PAY_ENABLED=1` with no `PAY_SERVICE_KEY` is an
 * administrator who meant to finish and did not, and reading it as "on" would
 * put the failure in front of a customer mid-conversation instead of in front
 * of the administrator in Channels & Trust.
 *
 * Nothing here decides whether a message may be SENT — consent, capability and
 * approval do that, at dispatch time, in Domain/DispatchGuard.php. A flag only
 * says whether an integration exists at all.
 */
final class Features
{
    /**
     * Flag name => the env keys that must be present for it to count as configured.
     *
     * An empty requirement list means the flag alone decides — the feature is
     * ours and needs nothing from another product.
     *
     * @var array<string, list<string>>
     */
    private const REQUIREMENTS = [
        // Ours.
        'AI'          => ['CONSOLE_API_URL', 'CONSOLE_SERVICE_KEY'],
        'JOURNEYS'    => [],
        'OUTCOMES'    => [],
        'REALTIME'    => [],

        // Sibling products. Each is read live and never mirrored.
        'CONTACTS'     => ['CONTACTS_SERVICE_KEY'],
        'BOOKS'        => ['BOOKS_SERVICE_KEY'],
        'SALES'        => ['SALES_SERVICE_KEY'],
        'PAY'          => ['PAY_SERVICE_KEY'],
        'APPOINTMENTS' => ['APPOINTMENTS_SERVICE_KEY'],
        'CALENDAR'     => ['CALENDAR_SERVICE_KEY'],
        'DRIVE'        => ['DRIVE_SERVICE_KEY'],
        'REACH'        => ['REACH_SERVICE_KEY'],
        'BILLING'      => ['BILLING_SERVICE_KEY'],
    ];

    /**
     * Flags that are on unless a deployment turns them off.
     *
     * These gate features Messaging owns outright, so the only reason to
     * disable one is that a particular business does not want it. Note that no
     * INTEGRATION is on by default: an integration is on when somebody has
     * configured it, never because the code for it shipped.
     *
     * @var list<string>
     */
    private const ON_BY_DEFAULT = ['JOURNEYS', 'OUTCOMES', 'REALTIME'];

    /** @var array<string, bool>|null */
    private static ?array $memo = null;

    public static function enabled(string $flag): bool
    {
        return self::all()[strtoupper($flag)] ?? false;
    }

    /**
     * Every flag and its state, for Channels & Trust and /api/health.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $out = [];
        foreach (self::REQUIREMENTS as $flag => $required) {
            $out[$flag] = self::resolve($flag, $required);
        }

        return self::$memo = $out;
    }

    /**
     * Why a flag is off, in words an administrator can act on.
     *
     * Names the env key that is missing, because that is the one thing the
     * person reading Channels & Trust needs and cannot guess. It never names a
     * value, and the caller shows it only to somebody who could act on it.
     */
    public static function explain(string $flag): ?string
    {
        $flag = strtoupper($flag);
        if (self::enabled($flag)) {
            return null;
        }

        $required = self::REQUIREMENTS[$flag] ?? null;
        if ($required === null) {
            return 'Unknown feature.';
        }

        $switch = 'MESSAGING_' . $flag . '_ENABLED';
        if (!self::switchedOn($flag)) {
            return 'Turned off for this deployment. Set ' . $switch . '=1 in the server environment to enable it.';
        }

        $missing = array_values(array_filter($required, static fn (string $key) => Env::get($key) === ''));
        if ($missing !== []) {
            return $switch . ' is set, but ' . implode(' and ', $missing)
                . ' ' . (count($missing) === 1 ? 'is' : 'are') . ' missing from the server environment.';
        }

        return 'Not available.';
    }

    /** Test seam. CLI only, and it resets rather than accumulating. */
    public static function overrideForTesting(?array $flags): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        if ($flags === null) {
            self::$memo = null;

            return;
        }
        $out = [];
        foreach (array_keys(self::REQUIREMENTS) as $flag) {
            $out[$flag] = (bool) ($flags[$flag] ?? false);
        }
        self::$memo = $out;
    }

    /** @param list<string> $required */
    private static function resolve(string $flag, array $required): bool
    {
        if (!self::switchedOn($flag)) {
            return false;
        }

        foreach ($required as $key) {
            if (Env::get($key) === '') {
                return false;
            }
        }

        return true;
    }

    private static function switchedOn(string $flag): bool
    {
        $raw = strtolower(trim(Env::get('MESSAGING_' . $flag . '_ENABLED')));

        if ($raw === '') {
            return in_array($flag, self::ON_BY_DEFAULT, true);
        }

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
