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
 *   Contacts   /api/companies/{cmp}/contacts[/lookup|/resolve|/{id}/resolve|/{id}/references] (contract v1)
 *   Books      /api/registers, /api/reports/bill-by-bill (acc_id+fy_id), /api/reports/dues, /api/vouchers/{id}
 *   Sales      /api/v1/orders, /api/v1/orders/{id}
 *   Pay        /api/v1/payment-links, /api/v1/payments
 *   Appts      /api/v1/bookings
 *   Drive      /api/v1/documents
 *   Reach      /api/v1/campaigns
 *   AI Pulse   /api/ai/v1/status, /api/ai/v1/generate — the AI gateway, with a
 *              canned answer; it checks the headers Pulse checks
 *   Console    /api/ai/credentials/resolve — channel secrets only (`channel:*`);
 *              an AI module is refused the way Console refuses a product whose
 *              AI runs through Pulse
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

// The portal's validatesession, in its REAL shape (my-aicountly-com
// AuthController::validateSession): status, uuid_aictly and the key — no
// name, no acs_type. A test session "test-ses-key-<uuid>" is that user.
if ($path === '/api/validatesession') {
    $bearer = preg_match('/Bearer\s+(.+)/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $b) === 1 ? trim($b[1]) : '';
    if (!str_starts_with($bearer, 'test-ses-key-')) {
        send(401, ['status' => 0, 'message' => 'Invalid session']);
    }
    send(200, ['status' => 1, 'uuid_aictly' => substr($bearer, strlen('test-ses-key-')), 'ses_key' => $bearer]);
}

// Manage's REAL companyinfo shape (manage-aicountly CompanyModel::companyInfo):
// asked with the caller's own ses_key and `comp_id`; 404 "not found or access
// denied" for a company the session cannot open. Ownership is in THIS answer
// (ownership / is_creator / access_type) — the portal session carries none.
if ($path === '/api/companyinfo' || preg_match('#^/api/companies/(\d+)$#', $path, $m) === 1) {
    $cmpId = (int) ($query['comp_id'] ?? $query['cmp_id'] ?? $m[1] ?? 0);
    $bearer = preg_match('/Bearer\s+(.+)/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $b) === 1 ? trim($b[1]) : '';

    // 9999: Manage answers about a DIFFERENT company — never read as a yes (503).
    if ($cmpId === 9999) {
        send(200, ['success' => '1', 'data' => ['comp_id' => 1234, 'cmp_id' => 1234, 'comp_name' => 'Somebody Else Pvt Ltd']]);
    }
    // 9998: this session may not open it — Manage's 404 (→ 403 here).
    if ($cmpId === 9998) {
        send(404, ['success' => false, 'message' => 'Company not found or access denied']);
    }
    // 9997: Manage itself failing (→ 503, never an allow).
    if ($cmpId === 9997) {
        send(502, ['message' => 'Bad gateway']);
    }

    // The suites' owners own every stub company; everybody else is a member.
    $owner = in_array($bearer, ['test-ses-key-user-owner', 'test-ses-key-user-http'], true);
    send(200, [
        'success' => '1',
        'data' => [
            'comp_id'     => $cmpId,
            'cmp_id'      => $cmpId,
            'comp_name'   => 'Stub Trading Co',
            'currency'    => 'INR',
            'timezone'    => 'Asia/Kolkata',
            'branch_list' => [['bo_id' => 0, 'bo_name' => 'All locations']],
            'fy_list'     => [['fy_id' => 7, 'fy_start' => '2026-04-01', 'fy_end' => '2027-03-31', 'fy_name' => '2026-27']],
            'is_creator'  => $owner,
            'ownership'   => $owner ? 'owner' : 'shared',
            'access_type' => $owner ? 1 : 2,
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

// Contract v1 COMPANY endpoints, in the shape the real serializer emits
// (ContactApiSerializer::companyContactToApi: displayName, phones[{value}],
// emails[{value}], cmpId). The run against the REAL Contacts handlers is
// tests/contacts-conformance.php (e2e harness); this only lets the suite run.
//   +919812345678  Priya Sharma (c0ffee…01) — exactly one match
//   +919812399999  two contacts share it — ambiguous
//   anything else  nobody
function stub_contact(string $id, string $name, array $phones, int $cmp, array $emails = []): array
{
    return [
        'id' => $id, 'displayName' => $name, 'organizationName' => '', 'contactKind' => 'person',
        'phones' => array_map(static fn ($p) => ['value' => $p], $phones),
        'emails' => array_map(static fn ($e) => ['value' => $e], $emails),
        'state' => 'active', 'mergedIntoId' => null, 'archivedAt' => null, 'cmpId' => $cmp, 'visibility' => 'company',
    ];
}

if (preg_match('#^/api/companies/(\d+)/contacts(/.*)?$#', $path, $cm) === 1) {
    $cmp = (int) $cm[1];
    $rest = trim((string) ($cm[2] ?? ''), '/');
    $priya = stub_contact('c0ffee00-0000-4000-8000-000000000001', 'Priya Sharma', ['+919812345678'], $cmp, ['priya@example.test']);
    $directory = [
        $priya['id'] => $priya,
        'c0ffee00-0000-4000-8000-000000000002' => stub_contact('c0ffee00-0000-4000-8000-000000000002', 'Shared Desk A', ['+919812399999'], $cmp),
        'c0ffee00-0000-4000-8000-000000000003' => stub_contact('c0ffee00-0000-4000-8000-000000000003', 'Shared Desk B', ['09812399999'], $cmp),
        // Books knows this one: an explicit ledger link in Contacts.
        'c0ffee00-0000-4000-8000-0000000000b1' => stub_contact('c0ffee00-0000-4000-8000-0000000000b1', 'Ledger Linked', ['+919812300001'], $cmp),
    ];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($rest === 'lookup') {
        $unknown = array_diff(array_keys($query), ['email', 'phone', 'tax_id']);
        if ($unknown !== []) {
            send(400, ['status' => 0, 'error' => ['code' => 'unsupported_parameter', 'message' => implode(', ', $unknown)]]);
        }
        $digits = substr(preg_replace('/\D/', '', (string) ($query['phone'] ?? '')) ?? '', -10);
        $hits = array_values(array_filter($directory, static function (array $c) use ($digits): bool {
            foreach ($c['phones'] as $p) {
                if ($digits !== '' && substr(preg_replace('/\D/', '', $p['value']) ?? '', -10) === $digits) {
                    return true;
                }
            }

            return false;
        }));
        send(200, ['status' => 1, 'data' => $hits, 'meta' => ['matchCount' => count($hits)]]);
    }
    if ($rest === 'resolve' && $method === 'POST') {
        $ids = (array) (json_decode((string) file_get_contents('php://input'), true)['ids'] ?? []);
        $out = [];
        foreach ($ids as $id) {
            $out[] = isset($directory[$id])
                ? ['id' => $id, 'state' => 'active', 'survivorId' => $id, 'contact' => $directory[$id]]
                : ['id' => $id, 'state' => 'not_found', 'survivorId' => null, 'contact' => null];
        }
        send(200, ['status' => 1, 'data' => $out]);
    }
    if (preg_match('#^([^/]+)/resolve$#', $rest, $rm) === 1) {
        // Merged: …0099 was merged into Priya.
        if ($rm[1] === 'c0ffee00-0000-4000-8000-000000000099') {
            send(200, ['status' => 1, 'data' => ['id' => $rm[1], 'state' => 'merged', 'survivorId' => $priya['id'], 'contact' => $priya]]);
        }
        if (!isset($directory[$rm[1]])) {
            send(404, ['status' => 0, 'error' => ['code' => 'not_found', 'message' => 'Contact not found.']]);
        }
        send(200, ['status' => 1, 'data' => ['id' => $rm[1], 'state' => 'active', 'survivorId' => $rm[1], 'contact' => $directory[$rm[1]]]]);
    }
    if (preg_match('#^([^/]+)/references$#', $rest, $rm) === 1) {
        $refs = $rm[1] === 'c0ffee00-0000-4000-8000-0000000000b1'
            ? [['id' => 'ref-1', 'contactId' => $rm[1], 'cmpId' => $cmp, 'product' => 'books', 'refType' => 'ledger_account', 'ref' => '7001']]
            : [];
        send(200, ['status' => 1, 'data' => $refs]);
    }
    if ($rest === '') {
        $unknown = array_diff(array_keys($query), ['q', 'email', 'phone', 'type', 'category', 'source', 'tags', 'scope', 'page', 'per_page', 'include_archived', 'product', 'ecosystem_role', 'company_id', 'company', 'ids', 'sort', 'tax_id']);
        if ($unknown !== []) {
            send(400, ['status' => 0, 'error' => ['code' => 'unsupported_parameter', 'message' => implode(', ', $unknown)]]);
        }
        $q = strtolower(trim((string) ($query['q'] ?? '')));
        $rows = array_values(array_filter($directory, static fn (array $c) => $q === '' || str_contains(strtolower($c['displayName']), $q)
            || str_contains(preg_replace('/\D/', '', json_encode($c['phones'])) ?? '', preg_replace('/\D/', '', $q) ?: '#')));
        send(200, ['status' => 1, 'data' => $rows, 'meta' => ['total' => count($rows), 'page' => (int) ($query['page'] ?? 1), 'perPage' => (int) ($query['per_page'] ?? 25)]]);
    }
    send(404, ['status' => 0, 'error' => ['code' => 'not_found', 'message' => 'Contact not found.']]);
}

// The personal book is NOT the inbox's directory: a call here is a bug (G19#3).
if (preg_match('#^/api/contacts(/.*)?$#', $path) === 1) {
    send(410, ['status' => 0, 'error' => ['code' => 'personal_book_not_for_messaging', 'message' => 'Messaging reads the company directory.']]);
}

// ---------------------------------------------------------------------------
// Books — the only authority for a balance
// ---------------------------------------------------------------------------

// Books' REAL report shapes (ReportsController::billByBill / ::dues). Both
// need a financial year; bill-by-bill needs the LEDGER (acc_id) — Books has no
// idea what a Contacts id is, and answers 400 without one. Ledger 7001 is the
// one Contacts links to contact …b1.
if ($path === '/api/reports/bill-by-bill') {
    if ((int) ($query['acc_id'] ?? 0) <= 0) {
        send(400, ['status' => 400, 'error' => 400, 'messages' => ['error' => 'acc_id required']]);
    }
    if ((int) ($query['fy_id'] ?? 0) <= 0) {
        send(400, ['status' => 400, 'error' => 400, 'messages' => ['error' => 'fy_id required']]);
    }
    $rows = (int) $query['acc_id'] === 7001 ? [
        ['bill_id' => 1, 'bill_ref' => 'INV-2026-0091', 'bill_date' => '2026-05-02', 'due_date' => '2026-05-17', 'bill_amount' => '12500.0000',
            'pending_amount' => '4800.0000', 'dr_cr' => 1, 'overdue_days' => 14, 'source_vch_number' => 'INV-2026-0091'],
        ['bill_id' => 2, 'bill_ref' => 'INV-2026-0104', 'bill_date' => '2026-05-20', 'due_date' => '2026-06-19', 'bill_amount' => '3200.0000',
            'pending_amount' => '3200.0000', 'dr_cr' => 1, 'overdue_days' => 0, 'source_vch_number' => 'INV-2026-0104'],
        ['bill_id' => 3, 'bill_ref' => 'On Account', 'bill_date' => null, 'due_date' => null, 'bill_amount' => '1000.0000',
            'pending_amount' => '1000.0000', 'dr_cr' => 2, 'overdue_days' => 0, 'is_on_account' => 1],
    ] : [];
    send(200, ['data' => ['report' => 'bill_by_bill', 'acc_id' => (int) $query['acc_id'], 'rows' => $rows,
        'totals' => ['pending' => 9000.0, 'overdue' => 4800.0]]]);
}

if ($path === '/api/reports/dues') {
    foreach (['party_type', 'as_on', 'fy_id'] as $required) {
        if (trim((string) ($query[$required] ?? '')) === '') {
            send(422, ['status' => 422, 'error' => 422, 'messages' => [$required => $required . ' is required']]);
        }
    }
    send(200, ['data' => ['as_on' => $query['as_on'], 'party_type' => 'debtor', 'group_by' => 'bill', 'rows' => [
        ['bill_id' => 1, 'acc_id' => 7001, 'acc_name' => 'Ledger Linked', 'bill_ref' => 'INV-2026-0091', 'due_date' => '2026-05-17',
            'pending_amount' => '4800.0000', 'dr_cr' => 1, 'days_overdue' => 14],
        ['bill_id' => 9, 'acc_id' => 7999, 'acc_name' => 'Nobody In Contacts', 'bill_ref' => 'INV-2026-0120', 'due_date' => '2026-05-10',
            'pending_amount' => '700.0000', 'dr_cr' => 1, 'days_overdue' => 21],
    ], 'totals' => ['outstanding' => 5500.0]]]);
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

// Sales' GET v1/orders (OrdersController::index → OrderService::search): rows
// are sales_orders (order_no, status, order_date, total_amount, currency_code,
// contact_id — the Contacts person the order is for). `contact_uuid` narrows
// them to that contact, case-insensitively; a blank one, or a parameter Sales
// does not know, is a 422 there — never the whole company's orders.
// Contact c0ffee00-…-0000000000ff plays a Sales from before it read
// contact_uuid: the company's latest orders, whoever they are for.
if ($path === '/api/v1/orders') {
    $salesOrders = [
        ['order_id' => 1, 'order_uuid' => 'aa000000-0000-4000-8000-000000000001', 'order_no' => 'SO-8841', 'status' => 'CONFIRMED', 'order_date' => '2026-05-28',
            'total_amount' => '6400.0000', 'currency_code' => 'USD', 'contact_id' => 'c0ffee00-0000-4000-8000-000000000001'],
        ['order_id' => 2, 'order_uuid' => 'aa000000-0000-4000-8000-000000000002', 'order_no' => 'SO-8842', 'status' => 'DRAFT', 'order_date' => '2026-05-30',
            'total_amount' => '1200.0000', 'currency_code' => 'INR', 'contact_id' => 'c0ffee00-0000-4000-8000-000000000002'],
        // Another company's order (cmp_id 1234) for a contact id: never shown in this company.
        ['order_id' => 3, 'order_uuid' => 'aa000000-0000-4000-8000-000000000003', 'order_no' => 'SO-9001', 'status' => 'CONFIRMED', 'order_date' => '2026-05-31',
            'total_amount' => '999.0000', 'currency_code' => 'INR', 'contact_id' => 'c0ffee00-0000-4000-8000-000000000004', 'cmp_id' => 1234],
    ];
    $known = ['status', 'customer_account_id', 'contact_uuid', 'contact_id', 'salesperson_id', 'territory_id', 'channel_id', 'from', 'to', 'as_of', 'q',
        'open_only', 'committed', 'late', 'limit', 'offset', 'page', 'sort', 'order', 'cmp_id', 'fy_id', 'bo_id'];
    $unknown = array_diff(array_keys($query), $known);
    if ($unknown !== []) {
        send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Orders cannot be filtered by ' . implode(', ', $unknown) . '.']]);
    }
    if (array_key_exists('contact_uuid', $query) && strcasecmp((string) $query['contact_uuid'], 'c0ffee00-0000-4000-8000-0000000000ff') !== 0) {
        if (trim((string) $query['contact_uuid']) === '') {
            send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Name the contact whose orders to list.']]);
        }
        $salesOrders = array_values(array_filter($salesOrders, static fn (array $o) => strcasecmp($o['contact_id'], (string) $query['contact_uuid']) === 0));
    }
    send(200, ['data' => $salesOrders, 'meta' => ['total' => count($salesOrders), 'limit' => (int) ($query['limit'] ?? 50), 'offset' => 0]]);
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

// ---------------------------------------------------------------------------
// AI Pulse — the AI gateway. Server to server: the product names itself and
// sends the user's session (or, with no user, the service key).
// ---------------------------------------------------------------------------

/** A request header, case-insensitively. */
function header_value(string $name): string
{
    foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $key => $value) {
        if (strcasecmp((string) $key, $name) === 0) {
            return trim((string) $value);
        }
    }

    return '';
}

function pulse_caller(): void
{
    if (header_value('X-Pulse-Product') !== 'messaging') {
        send(400, ['status' => 0, 'code' => 'product_required', 'message' => 'Send X-Pulse-Product.', 'retryable' => false]);
    }
    if (preg_match('/^Bearer\s+\S+/', header_value('Authorization')) !== 1 && header_value('X-Pulse-Service-Key') === '') {
        send(401, ['status' => 0, 'code' => 'unauthenticated', 'message' => 'Send a session or a service key.', 'retryable' => false]);
    }
}

if ($path === '/api/ai/v1/status') {
    pulse_caller();
    send(200, ['status' => 1, 'data' => [
        'enabled'   => true,
        'available' => true,
        'reason'    => null,
        'tiers'     => ['economy' => true, 'strong' => false],
        'caller'    => ['product' => 'messaging', 'auth' => header_value('Authorization') !== '' ? 'user' : 'service'],
    ]]);
}

if ($path === '/api/ai/v1/generate' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    pulse_caller();
    $request = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($request) || !is_string($request['feature'] ?? null) || preg_match('/^[a-z0-9][a-z0-9_.:-]{1,63}$/', $request['feature']) !== 1) {
        send(422, ['status' => 0, 'code' => 'invalid_request', 'message' => 'feature is required.', 'retryable' => false, 'detail' => ['field' => 'feature']]);
    }

    $wantsJson = ($request['response_format']['type'] ?? 'text') === 'json';
    send(200, ['status' => 1, 'data' => [
        'id'          => 'a11ce000-0000-4000-8000-00000000c0de',
        // Plain, fact-free text, so a draft built from it trips none of the
        // product's own checks. The tests are about the route, not the prose.
        'text'        => $wantsJson ? '{}' : 'Thank you for your message. We are looking into it and will reply shortly.',
        'json'        => $wantsJson ? new stdClass() : null,
        'tool_calls'  => [],
        'stop_reason' => 'end',
        'model'       => 'stub-flash',
        'provider'    => 'stub',
        'tier'        => (string) ($request['tier'] ?? 'strong'),
        'usage'       => ['input_tokens' => 100, 'output_tokens' => 20, 'cached_input_tokens' => 0],
        'cost_usd'    => null,
        'latency_ms'  => 5,
        'attempts'    => 1,
        'replayed'    => false,
        'cached'      => false,
    ]]);
}

// ---------------------------------------------------------------------------
// Console — a channel secret kept by reference. Never an AI key: Messaging's
// AI runs through Pulse, and Console refuses the AI modules of such a product.
// ---------------------------------------------------------------------------

if ($path === '/api/ai/credentials/resolve') {
    if (header_value('Authorization') !== 'Bearer test-console-service-key-0123456789') {
        send(401, ['status' => 0, 'message' => 'Unauthorised.']);
    }
    $module = (string) ($query['module'] ?? '');
    if (!str_starts_with($module, 'channel:')) {
        send(409, ['status' => 0, 'code' => 'uses_ai_pulse', 'message' => 'This product runs its AI through AI Pulse.']);
    }

    send(200, ['status' => 1, 'data' => [
        'credentials' => [['api_key' => 'stub-secret-for-' . substr($module, 8)]],
        'ttl_seconds' => 300,
    ]]);
}

send(404, ['error' => ['code' => 'not_found', 'message' => 'The stub does not serve ' . $path]]);
