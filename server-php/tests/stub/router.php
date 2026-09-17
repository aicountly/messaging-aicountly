<?php

declare(strict_types=1);

/**
 * A stand-in for the Aicountly products Messaging reads from.
 *
 * ## Why a real HTTP server rather than a mocked client
 *
 * The point of these tests is that cross-product data is fetched LIVE. A mocked
 * ApiClient would test the mock: it would not exercise the envelope parsing,
 * the header handling, the 401-is-forbidden-not-unavailable rule, the re-entry
 * guard or the `no-store` outbound cache header. Those are the parts that
 * decide whether a screen says "unavailable" or quietly shows a stale number,
 * so they are tested against something that actually speaks HTTP.
 *
 * ## What it serves
 *
 *   Manage     /api/companyinfo, /api/companies
 *   Contacts   /api/contacts, /api/contacts/{uuid}
 *   Books      /api/registers, /api/reports/bill-by-bill, /api/vouchers/{id}
 *   Sales      /api/v1/orders, /api/v1/orders/{id}
 *   Pay        /api/v1/payment-links, /api/v1/payments
 *   Appts      /api/v1/bookings
 *   Drive      /api/v1/documents
 *   Reach      /api/v1/campaigns
 *
 * ## Failure is switched by the request, not by a setup step
 *
 * `?stub=down` answers 503, `?stub=forbidden` answers 403 and `?stub=missing`
 * answers 404 on any route. That lets one test ask for the case it wants
 * without a stateful fixture, and it is how "Books refused this user" is told
 * apart from "Books was unreachable" — a distinction the UI shows differently
 * and must therefore be able to produce.
 *
 * ## It is NOT a database Messaging reads from
 *
 * Nothing here is ever written to Messaging's schema by the tests that use it.
 * It exists to be called on the request that renders a screen and forgotten
 * afterwards, which is the architecture under test.
 *
 * Run by tests/run.sh; never deployed.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// ApiClient::apiRoot() leaves a loopback base alone rather than appending
// "/api" — so the same client that calls https://books.aicountly.com/api/... in
// production calls http://127.0.0.1:PORT/... here. Both shapes are served, so
// the stub does not quietly decide which one the client under test must use.
if (!str_starts_with($path, '/api/')) {
    $path = '/api' . $path;
}
$query = [];
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

function send(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $mode): never
{
    match ($mode) {
        'down'      => send(503, ['error' => ['code' => 'unavailable', 'message' => 'The stub is pretending to be down.']]),
        'forbidden' => send(403, ['error' => ['code' => 'forbidden', 'message' => 'This user may not read that.']]),
        'missing'   => send(404, ['error' => ['code' => 'not_found', 'message' => 'No such route here.']]),
        'slow'      => (static function (): never {
            // Longer than the client's timeout, so the `timeout` outcome is
            // reachable without waiting on a real network.
            sleep(20);
            send(200, ['data' => []]);
        })(),
        default     => send(500, ['error' => ['code' => 'stub_mode_unknown', 'message' => $mode]]),
    };
}

$mode = (string) ($query['stub'] ?? '');
if ($mode !== '') {
    fail($mode);
}

// The readiness probe run.sh waits on. Deliberately not under /api/v1 so it
// cannot be mistaken for a product route.
if ($path === '/api/health') {
    send(200, ['data' => ['status' => 'ok', 'stub' => true]]);
}

// ---------------------------------------------------------------------------
// Manage — the tenant boundary
// ---------------------------------------------------------------------------

if ($path === '/api/companyinfo' || preg_match('#^/api/companies/(\d+)$#', $path, $m) === 1) {
    $cmpId = (int) ($query['cmp_id'] ?? $m[1] ?? 0);

    // A company id the tests use to prove the boundary holds: Manage says it
    // is a DIFFERENT company, which must produce a 403 rather than a silent
    // cross-tenant read.
    if ($cmpId === 9999) {
        send(200, ['data' => ['cmp_id' => 1234, 'cmp_name' => 'Somebody Else Pvt Ltd']]);
    }

    send(200, [
        'data' => [
            'cmp_id'    => $cmpId,
            'cmp_name'  => 'Stub Trading Co',
            'currency'  => 'INR',
            'timezone'  => 'Asia/Kolkata',
            'branches'  => [['bo_id' => 0, 'bo_name' => 'All locations']],
        ],
    ]);
}

if ($path === '/api/companies') {
    send(200, [
        'data' => [
            ['cmp_id' => 9001, 'cmp_name' => 'Stub Trading Co'],
            ['cmp_id' => 9002, 'cmp_name' => 'Stub Services LLP'],
        ],
        'meta' => ['total' => 2, 'limit' => 200, 'offset' => 0],
    ]);
}

// ---------------------------------------------------------------------------
// Contacts — identity. Messaging holds a reference; this is the record.
// ---------------------------------------------------------------------------

if (preg_match('#^/api/contacts/([^/]+)$#', $path, $m) === 1) {
    send(200, [
        'data' => [
            'contact_uuid' => $m[1],
            'name'         => 'Priya Sharma',
            'mobile'       => '+919812345678',
            'email'        => 'priya@example.test',
            'city'         => 'Pune',
            'tags'         => ['retail'],
        ],
    ]);
}

if ($path === '/api/contacts') {
    $wanted = (string) ($query['mobile'] ?? $query['q'] ?? '');

    // An address nobody has matched to a contact. NULL is the normal, permanent
    // answer for a walk-in number and the tests assert the UI copes with it
    // rather than inventing a name.
    if (str_contains($wanted, '900000')) {
        send(200, ['data' => [], 'meta' => ['total' => 0, 'limit' => 20, 'offset' => 0]]);
    }

    send(200, [
        'data' => [[
            'contact_uuid' => 'c0ffee00-0000-4000-8000-000000000001',
            'name'         => 'Priya Sharma',
            'mobile'       => '+919812345678',
            'email'        => 'priya@example.test',
        ]],
        'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0],
    ]);
}

// ---------------------------------------------------------------------------
// Books — the only authority for a balance
// ---------------------------------------------------------------------------

if ($path === '/api/reports/bill-by-bill') {
    send(200, [
        'data' => [
            [
                'vch_txn_id'  => 55501,
                'voucher_no'  => 'INV-2026-0091',
                'date'        => '2026-05-02',
                'amount'      => 1250000,
                'outstanding' => 480000,
                'currency'    => 'INR',
                'days_overdue' => 14,
            ],
            [
                'vch_txn_id'  => 55502,
                'voucher_no'  => 'INV-2026-0104',
                'date'        => '2026-05-20',
                'amount'      => 320000,
                'outstanding' => 320000,
                'currency'    => 'INR',
                'days_overdue' => 0,
            ],
        ],
        'meta' => ['total' => 2, 'limit' => 50, 'offset' => 0, 'currency' => 'INR'],
    ]);
}

if ($path === '/api/registers') {
    send(200, [
        'data' => [[
            'vch_txn_id' => 55501,
            'voucher_no' => 'INV-2026-0091',
            'date'       => '2026-05-02',
            'amount'     => 1250000,
            'currency'   => 'INR',
            'party'      => 'Priya Sharma',
        ]],
        'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0],
    ]);
}

if (preg_match('#^/api/vouchers/(\d+)$#', $path, $m) === 1) {
    send(200, [
        'data' => [
            'vch_txn_id'  => (int) $m[1],
            'voucher_no'  => 'INV-2026-0091',
            'amount'      => 1250000,
            'outstanding' => 480000,
            'currency'    => 'INR',
        ],
    ]);
}

// ---------------------------------------------------------------------------
// Sales, Pay, Appointments, Drive, Reach
// ---------------------------------------------------------------------------

if (preg_match('#^/api/v1/orders/([^/]+)$#', $path, $m) === 1) {
    send(200, ['data' => ['order_uuid' => $m[1], 'order_no' => 'SO-8841', 'status' => 'packed', 'total' => 640000, 'currency' => 'INR']]);
}

if ($path === '/api/v1/orders') {
    send(200, [
        'data' => [['order_uuid' => 'aa000000-0000-4000-8000-000000000001', 'order_no' => 'SO-8841', 'status' => 'packed', 'total' => 640000, 'currency' => 'INR']],
        'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0],
    ]);
}

if ($path === '/api/v1/payment-links' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Pay owns the link and the ledger. Messaging asks for one and keeps the
    // URL it is given; it never mints a link or records a payment itself.
    send(201, [
        'data' => [
            'payment_link_uuid' => 'pay00000-0000-4000-8000-000000000001',
            'url'               => 'https://pay.aicountly.test/l/stub-link',
            'amount'            => 480000,
            'currency'          => 'INR',
            'expires_at'        => '2026-06-30T00:00:00Z',
        ],
    ]);
}

if (preg_match('#^/api/v1/payment-links/([^/]+)$#', $path, $m) === 1) {
    send(200, ['data' => ['payment_link_uuid' => $m[1], 'status' => 'pending', 'url' => 'https://pay.aicountly.test/l/stub-link']]);
}

if ($path === '/api/v1/payments') {
    send(200, [
        'data' => [['payment_uuid' => 'pmt00000-0000-4000-8000-000000000001', 'amount' => 480000, 'currency' => 'INR', 'status' => 'captured', 'captured_at' => '2026-06-01T05:00:00Z']],
        'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0],
    ]);
}

if (preg_match('#^/api/v1/bookings/([^/]+)$#', $path, $m) === 1) {
    send(200, ['data' => ['booking_uuid' => $m[1], 'starts_at' => '2026-06-03T05:30:00Z', 'service_name' => 'Service call', 'status' => 'confirmed']]);
}

if ($path === '/api/v1/bookings') {
    send(200, [
        'data' => [['booking_uuid' => 'bk000000-0000-4000-8000-000000000001', 'starts_at' => '2026-06-03T05:30:00Z', 'service_name' => 'Service call', 'status' => 'confirmed']],
        'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0],
    ]);
}

if (preg_match('#^/api/v1/documents/([^/]+)$#', $path, $m) === 1) {
    send(200, ['data' => ['document_uuid' => $m[1], 'name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'size' => 84210, 'scan_state' => 'clean']]);
}

if ($path === '/api/v1/documents') {
    send(200, [
        'data' => [['document_uuid' => 'dc000000-0000-4000-8000-000000000001', 'name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'scan_state' => 'clean']],
        'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0],
    ]);
}

if ($path === '/api/v1/campaigns') {
    send(200, [
        'data' => [['campaign_uuid' => 'cm000000-0000-4000-8000-000000000001', 'name' => 'Monsoon offer', 'status' => 'running']],
        'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0],
    ]);
}

send(404, ['error' => ['code' => 'not_found', 'message' => 'The stub does not serve ' . $path]]);
