<?php

declare(strict_types=1);

namespace Aicountly\Api\Metering;

use Aicountly\Api\Env;
use Aicountly\Metering\UsageMeter;

require_once __DIR__ . '/AicountlyUsageMeter.php';

/**
 * What Messaging reports to Manage's central usage meter
 * (manage-aicountly docs/usage-metering/; the client is AicountlyUsageMeter.php, copied unchanged).
 *
 * ## Measures only
 *
 * Nothing here can refuse, delay or change a send. The meter writes to a local
 * spool and never throws, and it records nothing unless
 * AICOUNTLY_METERING_ENABLED=1.
 *
 * ## Why the attribution comes from the message row
 *
 * Messaging is a gateway: it pays Twilio, Meta and the RCS aggregator for
 * messages other products asked it to send. The adapter says what the provider
 * billed (SendResult::$usage, NormalisedEvent::$usage); this class adds who it
 * was for. The send runs in the dispatch worker and the price arrives on a
 * webhook — neither has a request to read a company or a caller from — so both
 * come from the stored message: its cmp_id, and `service_app`, which was
 * written from the calling product's service key (never from a request body).
 *
 * What is never sent: the recipient, the body, template variables.
 */
final class MessagingMeter
{
    /** Launcher catalog ids Manage accepts as origin_app. Anything else is booked to Messaging itself. */
    private const PRODUCTS = [
        'books', 'hrms', 'notes', 'billing', 'inventory', 'docs', 'remote', 'chat', 'buddy', 'calendar',
        'contacts', 'manage', 'insights', 'sales', 'purchases', 'email', 'auditor', 'fr', 'secretarial',
        'vault', 'pos', 'advisor', 'appointments', 'contracts', 'pay', 'crm', 'helpdesk', 'receptionist',
        'valuations', 'messaging', 'voice', 'edge', 'aspire', 'integrate', 'benefits', 'capital', 'apis',
    ];

    /** The names some products call themselves, folded onto the catalog id (as Manage does). */
    private const ALIASES = [
        'drive' => 'docs', 'connect' => 'chat', 'pulse' => 'buddy', 'lobby' => 'receptionist',
        'purchase' => 'purchases', 'ourpeople' => 'hrms',
    ];

    public static function meter(): UsageMeter
    {
        return UsageMeter::shared(static fn (string $key): string => Env::get($key));
    }

    /**
     * Report one provider operation for a stored message.
     *
     * $usage is what an adapter described (empty: nothing billed, nothing
     * recorded). A description with `corrects` is a price that arrived after
     * the send: it is recorded as a correction of the original event, and only
     * when the original went out without a price.
     *
     * @param array<string, mixed> $usage
     * @param array<string, mixed> $message a messaging_messages row
     */
    public static function recordForMessage(array $usage, array $message): void
    {
        if ($usage === [] || !isset($usage['idempotency_key'], $usage['meter'])) {
            return;
        }
        $meter = self::meter();
        if (!$meter->enabled()) {
            return;
        }

        $event = [
            'idempotency_key'     => (string) $usage['idempotency_key'],
            'category'            => (string) ($usage['category'] ?? 'other'),
            'provider'            => (string) ($usage['provider'] ?? ''),
            'service'             => $usage['service'] ?? null,
            'operation'           => $usage['operation'] ?? null,
            'provider_request_id' => $usage['provider_request_id'] ?? null,
            'vendor_cost'         => $usage['vendor_cost'] ?? null,
            'metadata'            => $usage['metadata'] ?? null,
            // A webhook can carry the same billing more than once (sent, then
            // delivered). Dating it by the send keeps every copy identical, so
            // Manage counts it once rather than refusing a "different" repeat.
            'occurred_at'         => self::iso($message['provider_accepted_at'] ?? null),
        ];

        if (isset($usage['corrects'])) {
            if (($message['provider_cost_minor'] ?? null) !== null) {
                return; // priced at send time: the original already carries the confirmed cost
            }
            // A correction keeps the original's company, user and product; Manage
            // refuses one that names different ones, so none are sent.
            $meter->recordAdjustment($event + [
                'corrects_event_id' => UsageMeter::eventIdFor(self::source(), (string) $usage['corrects']),
                'usage'             => [['meter' => (string) $usage['meter'], 'quantity' => 0]],
            ]);

            return;
        }

        $meter->recordProviderCall($event + [
            'meter'      => (string) $usage['meter'],
            'units'      => max(0, (int) ($usage['units'] ?? 1)),
            'origin_app' => self::originApp($message['service_app'] ?? null),
            'company_id' => (int) ($message['cmp_id'] ?? 0),
            'branch_id'  => (int) ($message['bo_id'] ?? 0),
            'user_uuid'  => self::userUuid($message['author_uuid'] ?? null),
        ]);
    }

    /** The catalog id of the product whose service key created the message; Messaging's own otherwise. */
    public static function originApp(mixed $serviceApp): string
    {
        $app = is_string($serviceApp) ? strtolower(trim($serviceApp)) : '';
        $app = self::ALIASES[$app] ?? $app;

        return in_array($app, self::PRODUCTS, true) ? $app : 'messaging';
    }

    /** A person's portal uuid; a synthetic actor ("service:appointments", "provider:twilio") is no one. */
    private static function userUuid(mixed $uuid): ?string
    {
        return is_string($uuid) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $uuid) === 1 ? $uuid : null;
    }

    private static function source(): string
    {
        return strtolower(trim(Env::get('AICOUNTLY_METERING_SOURCE', Env::get('APP_PRODUCT_KEY', 'messaging'))));
    }

    private static function iso(mixed $timestamp): ?string
    {
        $at = is_string($timestamp) && $timestamp !== '' ? date_create($timestamp) : false;

        return $at === false ? null : $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
