<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Estate analytics — a one-line wrapper around the copy-don't-edit SDK in src/EstateAnalytics/
 * (analytics-aicountly sdk/README.md).
 *
 * TEMPLATE: this file is identical in every `Aicountly\Api` product (Sales, Purchases, Billing,
 * POS, Pay), which all share the same Auth / Context / Env shape. Copy it unchanged from
 * analytics-aicountly sdk/php/templates/.
 *
 * AICOUNTLY's own view of how the estate is used, for product, marketing and sales. Not the same
 * thing as Manage usage metering, which tells a company what it consumed from AI and third-party
 * vendors. Same shape, no shared data.
 *
 * Nothing here can fail a request: every call is wrapped, the SDK never throws, and with
 * AICOUNTLY_ANALYTICS_ENABLED unset nothing is recorded at all.
 *
 * RECORD POST-COMMIT. A spooled event is a file write and does not roll back with the database
 * transaction that produced it, so calling this inside a transaction records things that may
 * never have happened. Call it from the controller, after the service has returned.
 */
final class EstateAnalytics
{
    /**
     * Record one event. The name must be registered in the Analytics tracking plan and allowed
     * for this product, or the server refuses it (analytics-aicountly docs/04_event_taxonomy.md).
     *
     * @param array<string,mixed> $attributes company_id, user_uuid, properties, …
     */
    public static function track(string $event, array $attributes = []): void
    {
        try {
            require_once __DIR__ . '/EstateAnalytics/AicountlyAnalytics.php';

            \Aicountly\Analytics\Analytics::shared(
                static fn (string $key): ?string => ($v = Env::get($key)) === '' ? null : $v
            )->track($event, $attributes);
        } catch (\Throwable $e) {
            error_log('estate analytics: ' . $e->getMessage());
        }
    }

    /**
     * The usual call: company, branch and actor taken from the request's own verified context, so
     * no call site has to remember the attribution rules.
     *
     * A service principal acting for a company is recorded as `system`, because every report
     * excludes it from customer adoption — without that, automation traffic reads as customers
     * using the product. There is no `staff` case here on purpose: this family's Auth has no
     * AICOUNTLY-staff principal, because support reaches a company through Remote, which marks
     * its own events `staff`.
     *
     * @param array<string,mixed> $extra properties, idempotency_key, outcome, …
     */
    public static function forContext(Context $ctx, Auth $auth, string $event, array $extra = []): void
    {
        self::track($event, $extra + self::identity($ctx, $auth));
    }

    /**
     * A batch from the browser, recorded against the context the backend verified.
     *
     * Only AnalyticsCollectController calls this, and it is the one path in the product fed by a
     * payload the user controls. The identity is passed as the SDK's common fields, which the SDK
     * applies OVER each event — so company_id, branch_id, user_uuid and user_kind cannot be set
     * from the browser whatever the payload says. Everything else in an event (page_path,
     * duration_ms, properties, session_id) is the browser's to report.
     *
     * @param list<array<string,mixed>> $events
     */
    public static function trackManyForContext(Context $ctx, Auth $auth, array $events): void
    {
        try {
            require_once __DIR__ . '/EstateAnalytics/AicountlyAnalytics.php';

            \Aicountly\Analytics\Analytics::shared(
                static fn (string $key): ?string => ($v = Env::get($key)) === '' ? null : $v
            )->trackMany($events, self::identity($ctx, $auth) + ['origin_surface' => 'web']);
        } catch (\Throwable $e) {
            error_log('estate analytics: ' . $e->getMessage());
        }
    }

    /**
     * The attribution every event from this request carries.
     *
     * A service principal acting for a company is recorded as `system`, because every report
     * excludes it from customer adoption — without that, automation traffic reads as customers
     * using the product.
     *
     * @return array<string,mixed>
     */
    private static function identity(Context $ctx, Auth $auth): array
    {
        return [
            'company_id' => $ctx->cmpId,
            'branch_id'  => $ctx->boId > 0 ? $ctx->boId : null,
            'user_uuid'  => $auth->uuid,
            'user_kind'  => $auth->isService() ? 'system' : 'customer',
        ];
    }
}
