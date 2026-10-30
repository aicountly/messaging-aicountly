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
     * A service principal acting for a company is recorded as `system`, and AICOUNTLY staff as
     * `staff`, because every report excludes both from customer adoption — without that, support
     * and automation traffic reads as customers using the product.
     *
     * @param array<string,mixed> $extra properties, idempotency_key, outcome, …
     */
    public static function forContext(Context $ctx, Auth $auth, string $event, array $extra = []): void
    {
        self::track($event, $extra + [
            'company_id' => $ctx->cmpId,
            'branch_id'  => $ctx->boId > 0 ? $ctx->boId : null,
            'user_uuid'  => $auth->uuid,
            'user_kind'  => $auth->isService() ? 'system' : 'customer',
        ]);
    }
}
