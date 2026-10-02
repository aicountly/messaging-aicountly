<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Domain\Settings;

/**
 * What another product's service key may do here (G19#7; D2(b)).
 *
 * A service key used to be a superuser: every /api/v1 route, any company named
 * in the query (the contract endpoints never even asked Manage), every
 * permission, and an X-Actor-Uuid nobody checked. The interim standard every
 * product's own service keys now follow (the same as Voice's):
 *
 *   ROUTE ALLOW-LIST  each product may call only the routes listed for it
 *                     below, and holds only the permissions listed per route.
 *                     Anything else is 403 service_route_not_allowed.
 *   COMPANY BINDING   the company must be one the acting person is a member of
 *                     (their own session, forwarded as the Bearer and asked of
 *                     Manage) — or, for a product acting with no person (a
 *                     reminder from cron), one that has allowed that product in
 *                     Messaging settings, or that ops listed for it in
 *                     SERVICE_KEY_COMPANIES. Never "any company the request names".
 *   VERIFIED ACTOR    a person is named only by their own session. A bare
 *                     X-Actor-Uuid is a claim: it is recorded as such and never
 *                     acted on. Bearer + X-Actor-Uuid that disagree are refused.
 *   ENVIRONMENT       the caller sends X-AIC-Environment and it must equal this
 *                     server's configured environment; with none configured,
 *                     no service key is accepted.
 */
final class ServicePolicy
{
    /** The published send contract (ServiceController), for products that message customers. */
    private const CONTRACT = [
        ['POST', 'v1/messages', ['messaging.messages.send']],
        ['GET', 'v1/messages/stats', ['messaging.outcomes.view']],
        ['GET', 'v1/messages/{message}', ['messaging.outcomes.view']],
        // Withdraw a message this product sent that has not gone yet: the same
        // authority as sending it, and ServiceController scopes it to the
        // calling product's own messages.
        ['POST', 'v1/messages/{message}/cancel', ['messaging.messages.send']],
    ];

    /**
     * product => list of [method, route pattern, permissions held on that route]
     *
     * Built from what each product's MessagingClient actually calls today.
     *
     * @return array<string, list<array{0: string, 1: string, 2: list<string>}>>
     */
    public static function routes(): array
    {
        return [
            'appointments' => self::CONTRACT,
            'billing'      => self::CONTRACT,
            'books'        => self::CONTRACT,
            'sales'        => self::CONTRACT,
            'pos'          => self::CONTRACT,
            'reach'        => self::CONTRACT,
            'crm'          => self::CONTRACT,
            'advisor'      => self::CONTRACT,
            'voice'        => self::CONTRACT,
            // Helpdesk's sync worker reads conversations awaiting a reply for
            // companies that bound their channel to it. Read only: replies go
            // out under the agent's own session, not this key.
            'helpdesk'     => [
                ['GET', 'v1/conversations', ['messaging.conversations.view', 'messaging.conversations.view_all']],
                ['GET', 'v1/conversations/{conversation}/messages', ['messaging.conversations.view', 'messaging.conversations.view_all']],
            ],
        ];
    }

    /** Products a company may allow to act for it with no person signed in. */
    public static function products(): array
    {
        return array_keys(self::routes());
    }

    /**
     * The permissions a product holds on the route being served, or null when
     * the route is not allowed for it at all (or no route is being served).
     *
     * @param array{0: string, 1: string}|null $route
     * @return list<string>|null
     */
    public static function grants(string $product, ?array $route = null): ?array
    {
        $route ??= Router::current();
        if ($route === null) {
            return null;
        }
        [$method, $pattern] = $route;
        foreach (self::routes()[strtolower($product)] ?? [] as [$allowedMethod, $allowedPattern, $permissions]) {
            if ($allowedMethod === $method && $allowedPattern === trim($pattern, '/')) {
                return $permissions;
            }
        }

        return null;
    }

    /**
     * May this product act for this company with no person signed in?
     *
     * Only when the company said so (Messaging settings, `service_products`,
     * changed by someone holding messaging.settings.manage and audited) or ops
     * bound it explicitly in SERVICE_KEY_COMPANIES (`app:1|2,app2:3`).
     */
    public static function companyBound(string $product, int $cmpId): bool
    {
        $product = strtolower($product);
        foreach (explode(',', Env::get('SERVICE_KEY_COMPANIES')) as $entry) {
            if (!str_contains($entry, ':')) {
                continue;
            }
            [$app, $list] = explode(':', $entry, 2);
            if (strtolower(trim($app)) !== $product) {
                continue;
            }
            foreach (explode('|', $list) as $id) {
                if (ctype_digit(trim($id)) && (int) trim($id) === $cmpId) {
                    return true;
                }
            }
        }

        $allowed = Settings::for(Context::forCompany($cmpId))['service_products'] ?? [];

        return is_array($allowed) && in_array($product, $allowed, true);
    }

    /** The environment a service call says it is for must be ours. */
    public static function environmentMatches(string $declared): bool
    {
        $mine = Environment::current();
        $theirs = Environment::normalise($declared);

        return $mine !== null && $theirs !== null && $mine === $theirs;
    }
}
