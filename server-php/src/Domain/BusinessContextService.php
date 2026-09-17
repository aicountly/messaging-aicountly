<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\AppointmentsClient;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Clients\PayClient;
use Aicountly\Api\Clients\SalesClient;
use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\Permissions;

/**
 * The Unified Inbox's right-hand panel: live business context.
 *
 * ## Every panel is independent, and says where it came from and when
 *
 * Contacts, Books, Sales, Pay and Appointments are five separate calls with
 * five separate outcomes. One of them failing degrades ONE panel — the others
 * still render, and the failed one says what is wrong in words an agent can
 * act on. A single `context` call that either works or does not would take the
 * whole panel down because Pay is not configured yet.
 *
 * Each panel carries `source`, `fetched_at` and `state`. The screen shows
 * "Books · read 4 seconds ago" because an agent about to promise a customer
 * something needs to know how fresh the number is.
 *
 * ## Permission is checked twice, and the outer check is not the real one
 *
 * `messaging.context.financial` gates the Books and Pay panels here. But the
 * call itself goes out under the AGENT'S OWN SESSION, so Books applies their
 * permissions independently and answers 403 if they may not see it. The local
 * grant exists so the UI can omit the panel instead of rendering an error —
 * it is not what keeps the balance private.
 *
 * ## Nothing is stored
 *
 * Not one field. This method returns a payload that is rendered and discarded.
 * A `no-store` header goes on the response (Http::json), and there is no
 * conversation column, no cache table and no local copy anywhere.
 */
final class BusinessContextService
{
    /**
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    public static function for(Context $ctx, Auth $auth, array $conversation): array
    {
        $contactUuid = (string) ($conversation['contact_uuid'] ?? '');
        $sesKey = $auth->sesKey();

        $maySeeFinancial = Permissions::allows($ctx, $auth, 'messaging.context.financial');
        $maySeeCommercial = Permissions::allows($ctx, $auth, 'messaging.context.commercial');

        return [
            'contact'      => self::contactPanel($ctx, $auth, $conversation, $sesKey),
            'financial'    => $maySeeFinancial
                ? self::financialPanel($ctx, $auth, $contactUuid, $sesKey)
                : self::denied('books', 'You do not have permission to view financial context.'),
            'payment'      => $maySeeFinancial
                ? self::paymentPanel($ctx, $conversation)
                : self::denied('pay', 'You do not have permission to view payment context.'),
            'orders'       => $maySeeCommercial
                ? self::ordersPanel($ctx, $auth, $contactUuid, $sesKey)
                : self::denied('sales', 'You do not have permission to view order context.'),
            'appointments' => $maySeeCommercial
                ? self::appointmentsPanel($ctx, $auth, $contactUuid, $sesKey)
                : self::denied('appointments', 'You do not have permission to view appointment context.'),
            'links'        => self::sourceLinks($ctx, $auth, $conversation, $maySeeFinancial, $maySeeCommercial),
        ];
    }

    /**
     * Who the customer is, from Contacts.
     *
     * When Contacts has no match, the panel says so and offers the transport
     * address — which is all Messaging knows. It does NOT invent a name from
     * the WhatsApp profile and present it as the customer's identity; that is
     * shown separately and labelled as the profile name.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    private static function contactPanel(Context $ctx, Auth $auth, array $conversation, string $sesKey): array
    {
        $client = new ContactsClient();
        if ($sesKey !== '') {
            $client = $client->withSession($sesKey);
        }

        if (!$client->configured()) {
            return self::pending('contacts', $client->unavailableMessage(), [
                'address'              => (string) ($conversation['customer_address'] ?? ''),
                'provider_profile_name' => (string) ($conversation['provider_profile_name'] ?? ''),
            ]);
        }

        $contactUuid = (string) ($conversation['contact_uuid'] ?? '');

        // No match yet: ask Contacts by phone number. This is how an inbound
        // message from an unknown number becomes a named customer.
        $result = $contactUuid !== ''
            ? $client->contact($contactUuid)
            : $client->findByPhone((string) ($conversation['customer_address'] ?? ''));

        if (!$result['ok']) {
            return self::fromEnvelope($result, [
                'address'               => (string) ($conversation['customer_address'] ?? ''),
                'provider_profile_name' => (string) ($conversation['provider_profile_name'] ?? ''),
            ]);
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];
        $contact = $contactUuid !== '' ? $body : (is_array($body[0] ?? null) ? $body[0] : null);

        if ($contact === null || $contact === []) {
            return [
                'state'      => 'ready',
                'source'     => 'contacts',
                'fetched_at' => $result['fetched_at'],
                'message'    => 'No contact in Aicountly Contacts matches this number yet.',
                'data'       => [
                    'matched'               => false,
                    'address'               => (string) ($conversation['customer_address'] ?? ''),
                    'provider_profile_name' => (string) ($conversation['provider_profile_name'] ?? ''),
                ],
            ];
        }

        return [
            'state'      => 'ready',
            'source'     => 'contacts',
            'fetched_at' => $result['fetched_at'],
            'message'    => '',
            'data'       => [
                'matched'      => true,
                'contact_uuid' => (string) ($contact['contact_uuid'] ?? $contactUuid),
                'name'         => (string) ($contact['name'] ?? ''),
                'mobile'       => (string) ($contact['mobile'] ?? ''),
                'email'        => (string) ($contact['email'] ?? ''),
                'language'     => (string) ($contact['preferred_language'] ?? ''),
                'address'      => (string) ($conversation['customer_address'] ?? ''),
                'provider_profile_name' => (string) ($conversation['provider_profile_name'] ?? ''),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function financialPanel(Context $ctx, Auth $auth, string $contactUuid, string $sesKey): array
    {
        $client = new BooksClient();
        if (!$client->configured()) {
            return self::pending('books', $client->unavailableMessage());
        }
        if ($contactUuid === '') {
            return [
                'state' => 'unsupported', 'source' => 'books', 'fetched_at' => gmdate('c'),
                'message' => 'This conversation is not matched to a contact yet, so Books cannot be asked what they owe.',
                'data' => [],
            ];
        }

        $client = $sesKey !== '' ? $client->withSession($sesKey) : $client->withService($auth->uuid);
        $result = $client->outstandingForContact($ctx, $contactUuid);

        if (!$result['ok']) {
            return self::fromEnvelope($result);
        }

        $rows = (array) ($result['body']['data'] ?? []);

        // Grouped by currency, never summed across them.
        $byCurrency = [];
        $invoices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $currency = strtoupper((string) ($row['currency'] ?? Settings::currency($ctx)));
            $outstanding = isset($row['outstanding_amount'])
                ? (int) round((float) $row['outstanding_amount'] * 100)
                : (int) ($row['outstanding_minor'] ?? 0);

            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $outstanding;

            if (count($invoices) < 10) {
                $invoices[] = [
                    'reference'         => (string) ($row['voucher_no'] ?? $row['reference'] ?? ''),
                    'outstanding_minor' => $outstanding,
                    'currency'          => $currency,
                    'due_date'          => (string) ($row['due_date'] ?? ''),
                    'overdue_days'      => isset($row['overdue_days']) ? (int) $row['overdue_days'] : null,
                ];
            }
        }

        $totals = [];
        foreach ($byCurrency as $currency => $minor) {
            $totals[] = ['currency' => $currency, 'outstanding_minor' => $minor];
        }

        return [
            'state'      => 'ready',
            'source'     => 'books',
            'fetched_at' => $result['fetched_at'],
            'message'    => '',
            'data'       => [
                'outstanding_by_currency' => $totals,
                'invoice_count'           => count($rows),
                'invoices'                => $invoices,
                'combined_total'          => null,
                'combined_note'           => count($totals) > 1
                    ? 'This customer has balances in more than one currency. They are shown separately because no '
                        . 'conversion source is configured.'
                    : null,
            ],
        ];
    }

    /**
     * The payment-link panel.
     *
     * THE ONE THAT MATTERS MOST. When Pay is not connected this returns
     * `pending` with a clear message, and the composer's "create payment link"
     * action is disabled off the back of it. The assistant is told the same
     * thing (see Ai/DraftAssistant), so it drafts a reply that offers the
     * invoice and says the link is unavailable — rather than one that claims a
     * link is attached.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    private static function paymentPanel(Context $ctx, array $conversation): array
    {
        $client = new PayClient();
        if (!$client->configured()) {
            return self::pending('pay', $client->unavailableMessage(), [
                'can_create_link' => false,
                'reason'          => 'Aicountly Pay is not connected for this deployment.',
            ]);
        }

        // An existing link for this conversation, if one was created.
        $reference = null;
        foreach (ConversationService::externalReferences($ctx, (string) $conversation['conversation_uuid']) as $ref) {
            if ((string) $ref['owner_product'] === 'pay' && (string) $ref['relationship'] === 'payment_request') {
                $reference = (string) $ref['external_id'];
                break;
            }
        }

        if ($reference === null) {
            return [
                'state'      => 'ready',
                'source'     => 'pay',
                'fetched_at' => gmdate('c'),
                'message'    => '',
                'data'       => ['can_create_link' => true, 'link' => null],
            ];
        }

        $result = $client->paymentLink($ctx, $reference);
        if (!$result['ok']) {
            return self::fromEnvelope($result, ['can_create_link' => true, 'link' => null]);
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];

        return [
            'state'      => 'ready',
            'source'     => 'pay',
            'fetched_at' => $result['fetched_at'],
            'message'    => '',
            'data'       => [
                'can_create_link' => true,
                'link' => [
                    'reference'    => (string) ($body['reference'] ?? $reference),
                    'status'       => strtoupper((string) ($body['status'] ?? 'UNKNOWN')),
                    'url'          => (string) ($body['url'] ?? ''),
                    'amount_minor' => (int) ($body['amount_minor'] ?? 0),
                    'currency'     => strtoupper((string) ($body['currency'] ?? Settings::currency($ctx))),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function ordersPanel(Context $ctx, Auth $auth, string $contactUuid, string $sesKey): array
    {
        $client = new SalesClient();
        if (!$client->configured()) {
            return self::pending('sales', $client->unavailableMessage());
        }
        if ($contactUuid === '') {
            return [
                'state' => 'unsupported', 'source' => 'sales', 'fetched_at' => gmdate('c'),
                'message' => 'This conversation is not matched to a contact yet, so their orders cannot be found.',
                'data' => [],
            ];
        }

        $client = $sesKey !== '' ? $client->withSession($sesKey) : $client->withService($auth->uuid);
        $result = $client->ordersForContact($ctx, $contactUuid, 5);

        if (!$result['ok']) {
            return self::fromEnvelope($result);
        }

        $orders = [];
        foreach ((array) ($result['body']['data'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orders[] = [
                'reference'    => (string) ($row['order_no'] ?? $row['reference'] ?? ''),
                'status'       => strtoupper((string) ($row['status'] ?? '')),
                'order_date'   => (string) ($row['order_date'] ?? ''),
                'total_minor'  => isset($row['total_amount']) ? (int) round((float) $row['total_amount'] * 100) : 0,
                'currency'     => strtoupper((string) ($row['currency'] ?? Settings::currency($ctx))),
            ];
        }

        return [
            'state'      => 'ready',
            'source'     => 'sales',
            'fetched_at' => $result['fetched_at'],
            'message'    => '',
            'data'       => ['orders' => $orders],
        ];
    }

    /** @return array<string, mixed> */
    private static function appointmentsPanel(Context $ctx, Auth $auth, string $contactUuid, string $sesKey): array
    {
        $client = new AppointmentsClient();
        if (!$client->configured()) {
            return self::pending('appointments', $client->unavailableMessage());
        }
        if ($contactUuid === '') {
            return [
                'state' => 'unsupported', 'source' => 'appointments', 'fetched_at' => gmdate('c'),
                'message' => 'This conversation is not matched to a contact yet.',
                'data' => [],
            ];
        }

        $client = $sesKey !== '' ? $client->withSession($sesKey) : $client->withService($auth->uuid);
        $result = $client->upcomingForContact($ctx, $contactUuid, 3);

        if (!$result['ok']) {
            return self::fromEnvelope($result);
        }

        $bookings = [];
        foreach ((array) ($result['body']['data'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bookings[] = [
                'reference' => (string) ($row['reference'] ?? ''),
                'status'    => strtoupper((string) ($row['status'] ?? '')),
                // Read from Appointments every time. Messaging keeps no copy.
                'starts_at' => (string) ($row['starts_at'] ?? ''),
                'timezone'  => (string) ($row['timezone'] ?? ''),
                'service'   => (string) ($row['service_name'] ?? ''),
            ];
        }

        return [
            'state'      => 'ready',
            'source'     => 'appointments',
            'fetched_at' => $result['fetched_at'],
            'message'    => '',
            'data'       => ['bookings' => $bookings],
        ];
    }

    /**
     * Permission-aware deep links into the owning products.
     *
     * Built from a fixed map of product hostnames, never from a URL that
     * arrived in an API response. A link composed from a foreign payload is a
     * redirect somebody else controls.
     *
     * @param array<string, mixed> $conversation
     * @return list<array<string, string>>
     */
    private static function sourceLinks(
        Context $ctx,
        Auth $auth,
        array $conversation,
        bool $maySeeFinancial,
        bool $maySeeCommercial,
    ): array {
        $sandbox = self::isSandbox();
        $hosts = [
            'contacts'     => $sandbox ? 'contacts.gh.aicountly.com' : 'contacts.aicountly.com',
            'books'        => $sandbox ? 'books.gh.aicountly.com' : 'books.aicountly.com',
            'sales'        => $sandbox ? 'sales.gh.aicountly.com' : 'sales.aicountly.com',
            'appointments' => $sandbox ? 'appointments.gh.aicountly.com' : 'appointments.aicountly.com',
            'pay'          => $sandbox ? 'pay.gh.aicountly.com' : 'pay.aicountly.com',
        ];

        $links = [];
        $contactUuid = (string) ($conversation['contact_uuid'] ?? '');

        if ($contactUuid !== '') {
            $links[] = [
                'product' => 'contacts',
                'label'   => 'Open in Aicountly Contacts',
                'url'     => 'https://' . $hosts['contacts'] . '/contacts/' . rawurlencode($contactUuid),
            ];
        }

        foreach (ConversationService::externalReferences($ctx, (string) $conversation['conversation_uuid']) as $ref) {
            $product = (string) $ref['owner_product'];
            if (!isset($hosts[$product])) {
                continue;
            }
            if (in_array($product, ['books', 'pay'], true) && !$maySeeFinancial) {
                continue;
            }
            if (in_array($product, ['sales', 'appointments'], true) && !$maySeeCommercial) {
                continue;
            }

            $links[] = [
                'product' => $product,
                'label'   => 'Open ' . ((string) $ref['external_label'] !== ''
                    ? (string) $ref['external_label']
                    : (string) $ref['external_id']) . ' in Aicountly ' . ucfirst($product),
                'url'     => 'https://' . $hosts[$product] . '/?ref=' . rawurlencode((string) $ref['external_id'])
                    . '&cmp_id=' . $ctx->cmpId,
            ];
        }

        return $links;
    }

    private static function isSandbox(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (Env::get('APP_ENV') === 'production') {
            return false;
        }

        return $host === '' || str_contains($host, '.gh.aicountly.com')
            || str_contains($host, 'localhost') || str_starts_with($host, '127.');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function pending(string $source, string $message, array $data = []): array
    {
        return [
            'state'      => 'pending',
            'source'     => $source,
            'fetched_at' => gmdate('c'),
            'message'    => $message,
            'data'       => $data,
        ];
    }

    /** @return array<string, mixed> */
    private static function denied(string $source, string $message): array
    {
        return [
            'state'      => 'forbidden',
            'source'     => $source,
            'fetched_at' => gmdate('c'),
            'message'    => $message,
            'data'       => [],
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function fromEnvelope(array $envelope, array $data = []): array
    {
        return [
            'state'      => (string) ($envelope['state'] ?? 'unavailable'),
            'source'     => (string) ($envelope['source'] ?? ''),
            'fetched_at' => (string) ($envelope['fetched_at'] ?? gmdate('c')),
            'message'    => (string) ($envelope['message'] ?? 'This could not be read right now.'),
            'retryable'  => (bool) ($envelope['retryable'] ?? false),
            'data'       => $data,
        ];
    }
}
