<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Which deployment this is — production, sandbox or local — read from SERVER
 * CONFIGURATION ONLY.
 *
 * ## Why this is not derived from the request
 *
 * Every sibling host Messaging talks to — Manage (the tenant check), Contacts, Books,
 * Sales, Pay, Appointments, AI Pulse — used to be chosen from `HTTP_HOST`. Whatever a
 * request says its Host is, it is a claim: a default vhost, an IP request or a
 * misrouted proxy can present `localhost` or `*.gh.aicountly.com` to the
 * production deployment, and the tenant check would then be asked of SANDBOX
 * Manage, where the same cmp_id belongs to a different business. A CLI worker
 * has no Host at all, and an empty Host used to mean "sandbox" — so a
 * production journey-tick would have read sandbox Books (X-09).
 *
 * So the environment comes from `AIC_ENVIRONMENT` (the fleet-wide name), or
 * `APP_ENV` when that is not set. An unknown or missing value is NOT a
 * guess at production or sandbox: callers get null and refuse to reach any
 * sibling, which an administrator sees and fixes in minutes. Guessing is how
 * production data ends up in a sandbox.
 */
final class Environment
{
    public const PRODUCTION = 'production';
    public const SANDBOX    = 'sandbox';
    public const LOCAL      = 'local';

    /** production | sandbox | local, or null when the server does not say. */
    public static function current(): ?string
    {
        foreach (['AIC_ENVIRONMENT', 'APP_ENV'] as $key) {
            $raw = strtolower(trim(Env::get($key)));
            if ($raw === '') {
                continue;
            }

            // The first variable that is SET decides. A misspelt AIC_ENVIRONMENT
            // does not quietly fall through to APP_ENV: it is unconfigured.
            return self::normalise($raw);
        }

        return null;
    }

    /** The name a value maps to, or null when it is not one we recognise. */
    public static function normalise(string $raw): ?string
    {
        return match (strtolower(trim($raw))) {
            'production', 'prod', 'live'                         => self::PRODUCTION,
            'sandbox', 'staging', 'gh'                           => self::SANDBOX,
            'local', 'development', 'dev', 'test', 'testing'     => self::LOCAL,
            default                                              => null,
        };
    }

    public static function isProduction(): bool
    {
        return self::current() === self::PRODUCTION;
    }

    /**
     * Which of a sibling's two deployments this environment talks to.
     *
     * Local development talks to the sandbox siblings, as `npm run dev`
     * always has; an explicit *_API_BASE still wins over this (see
     * Clients\ApiClient::base()).
     *
     * @return 'production'|'sandbox'|null null when the environment is not configured
     */
    public static function siblingTier(): ?string
    {
        return match (self::current()) {
            self::PRODUCTION => 'production',
            self::SANDBOX, self::LOCAL => 'sandbox',
            default => null,
        };
    }

    /**
     * Does this request's Host CONTRADICT the configured environment?
     *
     * Refusal only. The Host never chooses anything; but a production server
     * being asked for `*.gh.aicountly.com`, or a sandbox server for a
     * production name, is either a probe or a server whose .env was copied
     * from the wrong template — and in both cases answering is wrong. An empty
     * Host (a CLI worker) or a loopback name contradicts nothing.
     */
    public static function hostContradicts(string $host): bool
    {
        $host = strtolower(trim($host));
        $host = explode(':', preg_replace('/^www\./', '', $host) ?? '')[0];
        if ($host === '' || $host === 'localhost' || str_starts_with($host, '127.')) {
            return false;
        }

        $sandboxHost = str_ends_with($host, '.gh.aicountly.com')
            || preg_match('/^gh-[a-z0-9-]+\.aicountly\.com$/', $host) === 1;
        $productionHost = !$sandboxHost && ($host === 'aicountly.com' || str_ends_with($host, '.aicountly.com'));

        return match (self::current()) {
            self::PRODUCTION => $sandboxHost,
            self::SANDBOX    => $productionHost,
            default          => false,
        };
    }

    /** For messages: what to set when nothing is configured. */
    public static function explainUnconfigured(): string
    {
        return 'This server does not say which environment it is. Set AIC_ENVIRONMENT (production, sandbox '
            . 'or local) in the server .env; until then no other Aicountly product is called.';
    }
}
