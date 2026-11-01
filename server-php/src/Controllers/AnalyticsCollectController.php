<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\EstateAnalytics;
use Aicountly\Api\Http;

/**
 * POST v1/analytics/collect — the browser's only destination for estate analytics.
 *
 * TEMPLATE: identical in every `Aicountly\Api` product, which all share the same
 * Auth / Context / Http shape. Copy it unchanged from analytics-aicountly
 * sdk/php/templates/, register the route, and wire the SPA.
 *
 * Why the browser posts here rather than straight to analytics.aicountly.org: the browser must
 * never be trusted with an identity. Here the request goes through exactly the gate every other
 * endpoint in this product goes through — a session Auth resolved, and a company Context verified
 * against Manage — so the company and actor attached to these events are ones the backend
 * established, not ones the payload asserted. There is no public write key and no cross-origin
 * collector, which removes a class of abuse rather than mitigating it.
 *
 * `cmp_id` / `fy_id` arrive in the body, which is how every endpoint in this family receives
 * them, and they are verified the same way: Context::assertAllowed() asks Manage whether this
 * session may open this company, answers 403 when Manage says no and 503 when Manage cannot be
 * reached. A browser naming a company it does not hold gets nothing recorded.
 *
 * Nothing here records anything a user can see. A signed-out or company-less browser is answered
 * 204 and ignored rather than told why, because analytics is never a reason for a screen to show
 * an error and a refusal here is not the user's problem to solve.
 */
final class AnalyticsCollectController extends Controller
{
    /** The browser may not send more than this in one beacon. */
    private const MAX_EVENTS = 50;

    /** Largest body accepted, before JSON parsing. */
    private const MAX_BYTES = 256 * 1024;

    public static function collect(): never
    {
        // A browser Origin, when the browser sends one, must be one this deployment allows.
        // It is NOT compared against HTTP_HOST: that is the request's own Host header, which the
        // caller sets, so an Origin checked against it proves nothing — and several products' test
        // suites forbid reading it for exactly that reason. The bearer token is the real credential
        // here, as on every other route; this is defence in depth.
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && !self::originAllowed($origin)) {
            self::noContent();
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BYTES) {
            self::noContent();
        }

        $body   = Http::body();
        $events = $body['events'] ?? null;
        if (!is_array($events) || $events === [] || !array_is_list($events)) {
            self::noContent();
        }
        $events = array_slice($events, 0, self::MAX_EVENTS);

        // Signed out: nothing to attribute, so nothing is recorded. resolve() rather than
        // require(), which would answer 401 and make a signed-out tab look like a failure.
        $auth = Auth::resolve();
        if ($auth === null) {
            self::noContent();
        }

        // No company chosen yet (the switcher has not run). Checked before Context::fromRequest(),
        // which would otherwise answer 400 for what is a perfectly ordinary browser state.
        //
        // Only cmp_id, deliberately. What a company context consists of differs across this
        // family: Billing, Sales, POS, Pay and Purchases carry a financial year and their Context
        // requires fy_id, while Appointments, CRM and Helpdesk have no financial year at all and
        // require only cmp_id. Demanding fy_id here would make those three answer 204 to every
        // event and record nothing, for ever, with nothing failing to show it. Whatever else a
        // product needs is Context::fromRequest()'s business, and it says so itself.
        if ((int) (Http::intParam('cmp_id', 0) ?? 0) <= 0) {
            self::noContent();
        }

        // The gate. assertAllowed() is what makes cmp_id a verified fact rather than a claim, and
        // it is deliberately left to answer 403 / 503 itself: a browser asking for a company it
        // does not hold is not an analytics condition to swallow.
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        EstateAnalytics::trackManyForContext($ctx, $auth, $events);

        self::noContent();
    }

    /**
     * 204, and nothing else. Http::json() writes `[]` as the body, which a 204 should not carry;
     * browsers ignore it, and going through Http keeps the CLI behaviour (ResponseSent) the rest
     * of the suite relies on.
     */
    private static function noContent(): never
    {
        Http::json(204, []);
    }

    /** The SPA is served from a different origin to the API; CORS_ALLOWED_ORIGINS names which. */
    /**
     * CORS_ALLOWED_ORIGINS, the deployment's own list — a configured value, not a request header.
     *
     * An empty list means there is nothing to check against, and the Origin is let through: a
     * deployment that has not set the variable would otherwise drop every browser event, since a
     * same-origin POST carries an Origin too. The bearer token still has to be valid.
     */
    private static function originAllowed(string $origin): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', \Aicountly\Api\Env::get('CORS_ALLOWED_ORIGINS'))));

        return $allowed === [] || in_array($origin, $allowed, true);
    }
}
