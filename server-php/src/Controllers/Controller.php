<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Shared entry work for every scoped endpoint.
 *
 * Authenticate, resolve the company scope, and CHECK THAT THIS SESSION MAY OPEN
 * THAT COMPANY — in that order, before a controller touches a row. The tenant
 * check is not optional and is not something an individual endpoint remembers
 * to do: it happens here, once, for all of them.
 *
 * A conversation is somebody's private correspondence with their customer.
 * Getting this wrong is not a degraded screen, it is a disclosure.
 */
abstract class Controller
{
    /** @return array{0: Auth, 1: Context} */
    protected static function enter(?string $permission = null): array
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        if ($permission !== null) {
            Permissions::assert($ctx, $auth, $permission);
        }

        return [$auth, $ctx];
    }

    /**
     * Turn a domain failure into the right HTTP answer.
     *
     * The distinctions that matter here:
     *
     *  - `version_conflict` is a 409, because the answer is "reload and look at
     *    what it says now", not "try again".
     *  - `no_consent` and `suppressed` are 422 rather than 403: the caller has
     *    permission, the CUSTOMER has said no, and conflating those two makes
     *    an agent think they need more access.
     *  - `channel_not_configured` is 422 and not 503 — nothing is broken,
     *    somebody has not finished setting it up, and only an administrator can.
     *  - `approval_stale` gets its own code so the UI can say "this was edited
     *    after approval" rather than a generic refusal.
     */
    protected static function fail(?string $code, ?string $detail): never
    {
        $message = $detail ?? 'That could not be done.';

        match ($code) {
            'not_found', 'conversation_missing', 'template_missing'
                => Http::notFound($message),

            'version_conflict'
                => Http::conflict($message, ['reason' => 'version_conflict', 'retryable' => false]),
            'already_queued', 'already_submitted', 'already_published', 'already_running'
                => Http::conflict($message, ['reason' => (string) $code, 'retryable' => false]),

            'context_unavailable', 'source_unavailable', 'provider_unreachable'
                => Http::error(503, (string) $code, $message, ['retryable' => true]),

            'no_consent', 'suppressed', 'withdrawn'
                => Http::validationFailed($message, ['reason' => (string) $code, 'customer_decision' => true]),

            'channel_not_configured', 'capability_missing', 'template_not_approved',
            'variables_missing', 'promised_resource_missing', 'not_approved',
            'approval_stale', 'not_editable', 'not_approvable', 'not_published',
            'validation_failed', 'empty', 'invalid_status', 'quiet_hours'
                => Http::validationFailed($message, ['reason' => (string) $code]),

            'forbidden' => Http::forbidden($message),

            default => Http::error(422, $code ?? 'failed', $message),
        };
    }

    /**
     * The row version a caller believes it is editing.
     *
     * Zero means "not supplied", which the services treat as "do not check".
     * That is deliberate for service callers and journeys, which have no
     * screen to have read a version from; a browser always sends one.
     */
    protected static function expectedVersion(): int
    {
        return max(0, Http::intParam('row_version', 0) ?? 0);
    }
}
