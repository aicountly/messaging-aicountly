<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Who is calling.
 *
 * Three ways in:
 *
 *  1. A human — `Authorization: Bearer <ses_key>`, validated at my.aicountly.com.
 *     The ses_key is KEPT, because it is what lets Messaging call Contacts,
 *     Books, Sales and Pay AS THAT USER. That is the whole reason an agent who
 *     may answer messages but not see money cannot see a balance through this
 *     product: the call to Books goes out under their own session and Books
 *     refuses it. Messaging does not re-implement another product's
 *     permissions, and it does not hold a key that could bypass them.
 *
 *  2. A trusted product backend — `X-Service-Key` and `X-AIC-Environment`,
 *     limited to the routes ServicePolicy lists for it. When a person is
 *     present the product forwards THEIR session as the Bearer and that is the
 *     verified actor (Manage decides the company). With no person (a reminder
 *     from cron) it acts as itself, only for companies bound to it. A bare
 *     `X-Actor-Uuid` is recorded as a claim and never acted on (G19#7).
 *
 *  3. A provider webhook — no Auth at all. A delivery receipt from Meta or
 *     Twilio is not a user and never will be. Those routes resolve no Auth,
 *     verify a provider signature instead, and resolve the tenant from
 *     SERVER-SIDE connection configuration — never from the payload. See
 *     Controllers/WebhookController.php.
 *
 * `sourceApp` is decided HERE and never read from a header: a service key
 * resolves to its product, a human session is always this product. A send
 * request that claims `source: JOURNEY` while arriving on a browser session is
 * a caller trying to launder the origin of a message, and the source it gets is
 * the one proven by its credential.
 */
final class Auth
{
    private function __construct(
        public readonly string $uuid,
        public readonly string $kind,      // 'user' | 'service' | 'provider'
        public readonly string $sourceApp,
        private readonly string $sesKey,
        private readonly ?array $session,
        /** A service call's unverified X-Actor-Uuid: recorded, never acted on. */
        public readonly ?string $claimedActor = null,
    ) {
    }

    /**
     * The caller a CLI test has stood in as.
     *
     * The same seam as ResponseSent, for the same reason: a controller resolves
     * its caller from HTTP headers, which a test has none of. Rather than let
     * tests reach past the controllers into the services — where the permission
     * checks are not — they adopt an identity and call the real endpoint.
     *
     * CLI ONLY. Under a web SAPI this is ignored outright, so it cannot become
     * an authentication bypass however it is called.
     */
    private static ?self $adopted = null;

    public static function adopt(?self $auth): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$adopted = $auth;
    }

    /** Build an identity for a test. CLI only, for the same reason as adopt(). */
    public static function forTesting(
        string $uuid,
        string $kind = 'user',
        string $sourceApp = 'messaging',
        array $session = [],
    ): self {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('Auth::forTesting is CLI only.');
        }

        return new self($uuid, $kind, $sourceApp, $kind === 'user' ? 'test-ses-key-' . $uuid : '', $session);
    }

    /**
     * The identity a provider webhook acts under.
     *
     * Not a credential and not a session: a delivery receipt from Meta is not a
     * user. This exists so consent and suppression have ONE notion of "who did
     * this" rather than a nullable Auth threaded through every method, and so a
     * customer's own opt-out is visibly distinct in the consent history from an
     * administrator suppressing them by hand.
     *
     * It holds no ses_key, so it cannot read Contacts or Books as anybody, and
     * Manage was never asked about it, so the owner shortcut in Permissions
     * can never fire for it. It grants nothing — the caller has already proved the
     * provider's signature before this is built.
     */
    public static function forProvider(string $provider): self
    {
        return new self(
            'provider:' . strtolower(trim($provider)),
            'provider',
            strtolower(trim($provider)),
            '',
            null,
        );
    }

    /** Resolve the caller, or answer 401 and stop. */
    public static function require(): self
    {
        if (PHP_SAPI === 'cli' && self::$adopted !== null) {
            return self::$adopted;
        }

        $resolved = self::resolve();
        if ($resolved === null) {
            Http::unauthorized();
        }
        if ($resolved->isService() && ServicePolicy::grants($resolved->sourceApp) === null) {
            Http::error(403, 'service_route_not_allowed',
                'This product\'s service key may not call this route. See ServicePolicy for what it may do.');
        }

        return $resolved;
    }

    public static function resolve(): ?self
    {
        $serviceKey = Http::header('X-Service-Key');
        if ($serviceKey !== '') {
            return self::resolveService($serviceKey);
        }

        $sesKey = self::bearer();
        if ($sesKey === '') {
            return null;
        }

        $session = Portal::validateSesKey($sesKey);
        if ($session === null) {
            return null;
        }

        return new self(
            (string) ($session['uuid_aictly'] ?? $session['uuid'] ?? ''),
            'user',
            Env::get('APP_PRODUCT_KEY', 'messaging'),
            $sesKey,
            $session,
        );
    }

    private static function resolveService(string $serviceKey): ?self
    {
        $app = ServiceKeys::resolveApp($serviceKey);
        if ($app === null) {
            return null;
        }
        if (!ServicePolicy::environmentMatches(Http::header('X-AIC-Environment'))) {
            Http::error(401, 'service_environment_mismatch',
                'A service call must say which environment it is for (X-AIC-Environment), and it must be this one.');
        }

        // Proven by the key, not claimed in a header. Recording it is what
        // stops us calling that product back inside its own request.
        CrossServiceCallContext::adoptAuthenticatedOrigin($app);
        $claimed = Http::header('X-Actor-Uuid');

        $sesKey = self::bearer();
        if ($sesKey === '') {
            return new self('service:' . $app, 'service', $app, '', null, $claimed !== '' ? $claimed : null);
        }

        $session = Portal::validateSesKey($sesKey);
        if ($session === null) {
            return null;
        }
        $uuid = (string) ($session['uuid_aictly'] ?? $session['uuid'] ?? '');
        if ($claimed !== '' && $claimed !== $uuid) {
            Http::error(401, 'actor_mismatch', 'X-Actor-Uuid does not match the session sent with it.');
        }

        // The verified person, acting through the product: their session is
        // kept so Manage decides whether they belong to the company.
        return new self($uuid, 'service', $app, $sesKey, $session);
    }

    /** A service call that carries the acting person's own, validated session. */
    public function hasVerifiedActor(): bool
    {
        return $this->isService() && $this->sesKey !== '';
    }

    public function isService(): bool
    {
        return $this->kind === 'service';
    }

    /**
     * The session key, for calling Contacts / Books / Sales / Pay as this user.
     *
     * Empty for a service caller, which is correct: a service acts with its own
     * key over there, not with a borrowed human session.
     */
    public function sesKey(): string
    {
        return $this->sesKey;
    }

    /** Stable per-session identifier for memo keys. Never the key itself, which must not reach a log or a cache key. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->kind . '|' . $this->uuid . '|' . $this->sesKey), 0, 32);
    }

    // There is deliberately no accessType(). The portal's validatesession never
    // returns `acs_type`; who owns a company is Manage's answer, read per company
    // in Context::assertAllowed and asked through Context::isOwner() (G19#8).

    public function displayName(): string
    {
        foreach (['name', 'full_name', 'user_name', 'email'] as $field) {
            $value = $this->session[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->uuid;
    }

    /**
     * The message origin this caller is entitled to claim.
     *
     * Decided by the credential, never by the request body. A Business Outcomes
     * panel that attributes collections to an automated payment reminder is
     * only worth reading if a browser cannot write that label onto a message an
     * agent typed by hand.
     */
    public function provenOrigin(): string
    {
        if (!$this->isService()) {
            return 'AGENT';
        }

        return match ($this->sourceApp) {
            'appointments' => 'APPOINTMENTS',
            'billing'      => 'BILLING',
            'books'        => 'BOOKS',
            'sales'        => 'SALES',
            'pos'          => 'POS',
            'reach'        => 'REACH',
            default        => 'API_INTEGRATION',
        };
    }

    private static function bearer(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || $header === '') {
            if (function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0) {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }
        if (!is_string($header) || preg_match('/Bearer\s+(.+)/i', $header, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }
}
