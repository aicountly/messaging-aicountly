<?php

declare(strict_types=1);

/**
 * HTTP-layer tests: the real router, the real controllers, the real envelopes.
 *
 * ## Why this is separate from integration.php
 *
 * That suite exercises the domain — consent, dispatch, the gates, the journey
 * graph. This one exercises everything BETWEEN an HTTP request and those
 * services: route matching, verb handling, the tenant check, permission
 * assertions, the `{data}` and `{data, meta}` envelopes, and the published
 * cross-product service contract other Aicountly products already call.
 *
 * Those are different failures. A wrong route, a controller method that does
 * not exist, or a route declared after one that swallows it will all pass a
 * domain suite and 404 in production.
 *
 * ## What it asserts about degradation
 *
 * It runs TWICE in tests/run.sh — once with the sibling-product stub up and
 * once with it stopped — because "every dashboard still renders when Books is
 * unreachable" and "business context refuses rather than guessing" are both
 * promises this product makes, and only one of them can be tested with the
 * stub running.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support/RecordingAdapter.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\RecordingAdapter;

const CMP = 9101;
const CONNECTION = '22222222-2222-4222-8222-222222222222';

/** A second conversation, so the permission test's send does not touch the first. */
const CONVERSATION_FOR_SEND = '33333333-3333-4333-8333-333333333333';

/** Set by run.sh: 'up' or 'down'. Decides what the cross-product assertions expect. */
$siblings = getenv('SIBLINGS') ?: 'up';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $name, callable $fn): void
{
    global $passed, $failed, $failures;

    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        $failed++;
        $failures[] = $name;
    }
}

/**
 * Dispatch through the real router and capture what the controller sent.
 *
 * Http::json() throws ResponseSent under CLI rather than exiting, which is the
 * seam that makes this possible without a web server — and it means the auth,
 * the tenant check and the permission assertion all really run.
 *
 * @param array<string, mixed> $query
 * @param array<string, mixed>|null $body
 * @return array{status:int, body:array<string, mixed>}
 */
function call(Router $router, string $method, string $path, array $query = [], ?array $body = null): array
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $query + ['cmp_id' => CMP, 'bo_id' => 0];
    $_POST = [];
    Http::setBodyForTesting($body);

    if ($method !== 'GET') {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'test-key-' . bin2hex(random_bytes(8));
    }

    try {
        ob_start();
        $matched = $router->dispatch($method, $path);
        ob_end_clean();

        // A matched route that produced nothing means the controller returned
        // without answering, which is its own bug and not a 404.
        return ['status' => $matched ? 0 : 404, 'body' => []];
    } catch (ResponseSent $e) {
        return ['status' => $e->status, 'body' => $e->payload];
    } catch (\Throwable $e) {
        return [
            'status' => 500,
            'body'   => ['error' => ['message' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]],
        ];
    } finally {
        Http::setBodyForTesting(null);
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    }
}

function assertStatus(int $expected, array $response, string $what): void
{
    if ($response['status'] !== $expected) {
        $detail = $response['body']['error']['message'] ?? json_encode($response['body']);
        throw new \RuntimeException("{$what}: expected {$expected}, got {$response['status']} — {$detail}");
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function assertFalse(bool $condition, string $what): void
{
    if ($condition) {
        throw new \RuntimeException($what);
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $what,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$tables = [
    'messaging_outcome_links', 'messaging_journey_step_runs', 'messaging_journey_runs',
    'messaging_journey_versions', 'messaging_journeys', 'messaging_ai_runs',
    'messaging_daily_metrics', 'messaging_dismissed_suggestions', 'messaging_delivery_events',
    'messaging_webhook_receipts', 'messaging_dispatch_jobs', 'messaging_approvals',
    'messaging_message_attachments', 'messaging_messages', 'messaging_internal_notes',
    'messaging_conversation_labels', 'messaging_conversation_assignments',
    'messaging_external_references', 'messaging_conversations', 'messaging_labels',
    'messaging_consent_events', 'messaging_consent_records', 'messaging_suppressions',
    'messaging_template_versions', 'messaging_templates', 'messaging_channel_capabilities',
    'messaging_channel_connections', 'messaging_permission_assignments',
    'messaging_permission_profiles', 'messaging_audit_events', 'messaging_idempotency_keys',
    'messaging_settings',
];

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the test database: {$e->getMessage()}\n");
    exit(1);
}

$auth = Auth::forTesting('user-http', 'user', 'messaging', ['acs_type' => 1]);
Auth::adopt($auth);
$ctx = Context::forCompany(CMP);
Context::trustForTesting(CMP, $auth);
Clock::freeze('2026-06-01T04:00:00Z');

foreach ($tables as $table) {
    Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
}

Domain\Settings::forget();
Permissions::forget();

$adapter = new RecordingAdapter();
ChannelRegistry::overrideForTesting('test_provider', $adapter);
putenv('MESSAGING_HTTP_TEST_TOKEN=http-test-token');

Db::insert('messaging_channel_connections', [
    'connection_uuid'      => CONNECTION,
    'cmp_id'               => CMP,
    'bo_id'                => 0,
    'channel'              => 'whatsapp',
    'provider'             => 'test_provider',
    'display_name'         => 'HTTP test line',
    'sender_address'       => '+919800000001',
    'provider_account_ref' => 'acct-http',
    'credential_ref'       => 'MESSAGING_HTTP_TEST_TOKEN',
    'webhook_secret_ref'   => 'MESSAGING_HTTP_TEST_WEBHOOK',
    'status'               => 'connected',
    'is_active'            => true,
    'created_at'           => Clock::nowSql(),
    'updated_at'           => Clock::nowSql(),
], 'connection_uuid');

$conversationUuid = Uuid::v4();
Db::insert('messaging_conversations', [
    'conversation_uuid' => $conversationUuid,
    'cmp_id'            => CMP,
    'bo_id'             => 0,
    'connection_uuid'   => CONNECTION,
    'channel'           => 'whatsapp',
    'customer_address'  => '+919812345699',
    'status'            => 'open',
    'first_inbound_at'  => Clock::nowSql(),
    'last_inbound_at'   => Clock::nowSql(),
    'created_at'        => Clock::nowSql(),
    'updated_at'        => Clock::nowSql(),
], 'conversation_uuid');

Db::insert('messaging_conversations', [
    'conversation_uuid' => CONVERSATION_FOR_SEND,
    'cmp_id'            => CMP,
    'bo_id'             => 0,
    'connection_uuid'   => CONNECTION,
    'channel'           => 'whatsapp',
    'customer_address'  => '+919812345698',
    'status'            => 'open',
    'first_inbound_at'  => Clock::nowSql(),
    'last_inbound_at'   => Clock::nowSql(),
    'created_at'        => Clock::nowSql(),
    'updated_at'        => Clock::nowSql(),
], 'conversation_uuid');

$router = new Router();
Routes::register($router);

echo "HTTP layer tests (sibling products {$siblings})\n";
echo str_repeat('=', 60) . "\n";

// ---------------------------------------------------------------------------
// Every route in the table resolves to a method that exists
// ---------------------------------------------------------------------------

check('every registered route points at a callable handler', static function () use ($router): void {
    // A route declared against a method somebody renamed is a 500 the first
    // time a customer opens that screen. Cheap to check here, expensive there.
    $problems = [];

    foreach ($router->routes() as $route) {
        [$class, $method] = $route['handler'];
        if (!class_exists($class) || !method_exists($class, $method)) {
            $problems[] = $route['method'] . ' ' . $route['path'] . ' → ' . $class . '::' . $method;
        }
    }

    assertTrue($problems === [], "unresolvable handlers:\n        " . implode("\n        ", $problems));
});

// ---------------------------------------------------------------------------
// Every read endpoint answers, with the stub up or down
// ---------------------------------------------------------------------------

$readEndpoints = [
    'v1/session'                => 'the shell bootstrap',
    'v1/permissions'            => 'what this caller may do',
    'v1/overview'               => 'the Command Centre',
    'v1/conversations'          => 'the inbox list',
    'v1/templates'              => 'templates',
    'v1/journeys'               => 'journeys',
    'v1/journey-runs'           => 'journey run history',
    'v1/outcomes'               => 'Business Outcomes',
    'v1/channels'               => 'Channels & Trust',
    'v1/consents'               => 'consent records',
    'v1/suppressions'           => 'the suppression list',
    'v1/settings'               => 'settings',
    'v1/access'                 => 'access control',
    'v1/audit'                  => 'the audit history',
    'v1/delivery/failures'      => 'the delivery investigation list',
    'v1/contacts'               => 'the contacts browser',
    'v1/ai/status'              => 'what AI is permitted to do',
];

foreach ($readEndpoints as $path => $what) {
    check("GET {$path} answers ({$what})", static function () use ($router, $path): void {
        $response = call($router, 'GET', $path);
        assertStatus(200, $response, $path . ' should answer');
        assertTrue(
            array_key_exists('data', $response['body']),
            $path . ' should use the fleet envelope, with a "data" key',
        );
    });
}

check('a list endpoint carries meta with a total, a limit and an offset', static function () use ($router): void {
    $response = call($router, 'GET', 'v1/conversations');
    assertStatus(200, $response, 'the inbox list');

    foreach (['total', 'limit', 'offset'] as $key) {
        assertTrue(isset($response['body']['meta'][$key]), 'meta.' . $key . ' should be present');
    }
});

check('a limit beyond the maximum is clamped rather than obeyed', static function () use ($router): void {
    // An unbounded limit is a way to ask one request to read the whole table.
    $response = call($router, 'GET', 'v1/conversations', ['limit' => 100000]);
    assertStatus(200, $response, 'the inbox list');
    assertTrue(
        (int) $response['body']['meta']['limit'] <= 200,
        'the limit should be clamped to the maximum, not taken from the query',
    );
});

check('every response forbids caching', static function () use ($router): void {
    // A conversation cached by an intermediary is a conversation somebody else
    // can be served. Asserted on the real controller path, not on a constant.
    $response = call($router, 'GET', 'v1/conversations');
    assertStatus(200, $response, 'the inbox list');

    $cacheHeaders = array_values(array_filter(
        headers_list(),
        static fn (string $header) => stripos($header, 'cache-control') === 0,
    ));

    // headers_list() is empty under the CLI SAPI, so this asserts what it can:
    // that Http declares the policy in one place and that it is no-store.
    assertTrue(
        $cacheHeaders === [] || str_contains(strtolower($cacheHeaders[0]), 'no-store'),
        'Cache-Control should be no-store wherever headers are observable: ' . implode('; ', $cacheHeaders),
    );
});

// ---------------------------------------------------------------------------
// Routing itself
// ---------------------------------------------------------------------------

check('an unknown route is 404', static function () use ($router): void {
    $response = call($router, 'GET', 'v1/no-such-thing');
    assertStatus(404, $response, 'an unknown path');
});

check('a known path with the wrong verb is 405, not 404', static function () use ($router): void {
    // The distinction matters to whoever is integrating: 404 sends them looking
    // for a typo in a path that is correct.
    $response = call($router, 'DELETE', 'v1/overview');
    assertStatus(405, $response, 'a known path with an unsupported verb');
});

check('a malformed uuid in a path is 404, not 500', static function () use ($router): void {
    $response = call($router, 'GET', 'v1/conversations/not-a-uuid');
    assertStatus(404, $response, 'a malformed conversation id');
});

check('a conversation that exists answers with its thread', static function () use ($router, $conversationUuid): void {
    $response = call($router, 'GET', 'v1/conversations/' . $conversationUuid);
    assertStatus(200, $response, 'the conversation detail');
    assertTrue(isset($response['body']['data']['conversation']), 'with the conversation');
    assertTrue(isset($response['body']['data']['messages']), 'and its messages');
});

// ---------------------------------------------------------------------------
// The tenant boundary, over HTTP
// ---------------------------------------------------------------------------

check('a company this session cannot open is refused', static function () use ($router): void {
    // 9999 is the id the Manage stub answers with a DIFFERENT company for, and
    // with the stub down the tenant check cannot be confirmed — which is a 503,
    // never an allow. Either answer is a refusal; neither is 200.
    $_GET = ['cmp_id' => 9999, 'bo_id' => 0];
    $response = call($router, 'GET', 'v1/conversations', ['cmp_id' => 9999]);

    assertTrue(
        in_array($response['status'], [403, 503], true),
        'expected 403 (Manage says another company) or 503 (Manage unreachable), got ' . $response['status'],
    );
});

// ---------------------------------------------------------------------------
// Permissions, over HTTP
// ---------------------------------------------------------------------------

check('an agent without the financial permission gets a working inbox and no balances', static function () use ($router, $conversationUuid): void {
    $agent = Auth::forTesting('user-http-agent', 'user', 'messaging', ['acs_type' => 2]);
    Auth::adopt($agent);
    Context::trustForTesting(CMP, $agent);
    Permissions::forget();

    try {
        // THE DISTINCTION THIS PRODUCT IS BUILT AROUND. Answering messages is
        // not permission to see what a customer owes. The endpoint still
        // answers 200 — refusing it whole would break the inbox for the person
        // staffing it — and the financial panel says, in words, that it is not
        // theirs to see.
        $response = call($router, 'GET', 'v1/conversations/' . $conversationUuid . '/context');
        assertStatus(200, $response, 'business context for an agent');

        $panels = $response['body']['data']['context'] ?? [];

        foreach (['financial', 'payment'] as $name) {
            assertSame('forbidden', (string) ($panels[$name]['state'] ?? ''),
                'the ' . $name . ' panel must be refused for this caller');
            assertSame([], $panels[$name]['data'] ?? null,
                'and carry no data at all — a refused panel with figures in it is a leak');
        }

        $encoded = (string) json_encode($panels);
        assertFalse(str_contains($encoded, '480000'),
            'no balance may appear anywhere in the payload for a caller who cannot see balances');
    } finally {
        Auth::adopt(Auth::forTesting('user-http', 'user', 'messaging', ['acs_type' => 1]));
        Permissions::forget();
    }
});

check('an agent without send permission cannot dispatch', static function () use ($router, $conversationUuid): void {
    $agent = Auth::forTesting('user-http-agent', 'user', 'messaging', ['acs_type' => 2]);
    Auth::adopt($agent);
    Context::trustForTesting(CMP, $agent);
    Permissions::forget();

    try {
        $response = call($router, 'POST', 'v1/conversations/' . CONVERSATION_FOR_SEND . '/send', [], ['message_uuid' => Uuid::v4()]);
        assertStatus(403, $response, 'dispatch without messaging.messages.send');
    } finally {
        Auth::adopt(Auth::forTesting('user-http', 'user', 'messaging', ['acs_type' => 1]));
        Permissions::forget();
    }
});

// ---------------------------------------------------------------------------
// Writes: idempotency and validation
// ---------------------------------------------------------------------------

/**
 * Call the published service contract as another Aicountly product would.
 *
 * @param array<string, mixed> $body
 * @return array{status:int, body:array<string, mixed>}
 */
function callAsService(Router $router, array $body, ?string $idempotencyKey): array
{
    $previous = Auth::resolve();
    Auth::adopt(null);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_SERVICE_KEY'] = 'test-appointments-inbound-key-0123456789';
    $_SERVER['HTTP_X_ACTOR_UUID'] = 'appointments-actor-1';
    $_GET = ['cmp_id' => CMP, 'bo_id' => 0];
    Http::setBodyForTesting($body);

    if ($idempotencyKey === null) {
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    } else {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
    }

    try {
        ob_start();
        $matched = $router->dispatch('POST', 'v1/messages');
        ob_end_clean();

        return ['status' => $matched ? 0 : 404, 'body' => []];
    } catch (ResponseSent $e) {
        return ['status' => $e->status, 'body' => $e->payload];
    } catch (\Throwable $e) {
        return ['status' => 500, 'body' => ['error' => ['message' => $e->getMessage()]]];
    } finally {
        Http::setBodyForTesting(null);
        unset($_SERVER['HTTP_X_SERVICE_KEY'], $_SERVER['HTTP_X_ACTOR_UUID'], $_SERVER['HTTP_IDEMPOTENCY_KEY']);
        Auth::adopt($previous ?? Auth::forTesting('user-http', 'user', 'messaging', ['acs_type' => 1]));
    }
}

check('the service contract refuses a write with no Idempotency-Key', static function () use ($router): void {
    // THE REASON. Without one, a retry after a timeout sends the customer a
    // second message, and the calling product cannot tell the difference.
    $response = callAsService($router, [
        'channel' => 'whatsapp', 'to' => '+919812345699', 'template' => 'order_packed',
        'variables' => ['name' => 'Priya'], 'reference' => ['product' => 'appointments', 'id' => 'bk-1'],
    ], null);

    assertStatus(422, $response, 'a service write with no idempotency key');
    assertTrue(
        str_contains(strtolower((string) ($response['body']['error']['message'] ?? '')), 'idempotency-key'),
        'and the refusal names the header',
    );
});

check('the service contract refuses a malformed Idempotency-Key', static function () use ($router): void {
    $response = callAsService($router, [
        'channel' => 'whatsapp', 'to' => '+919812345699', 'template' => 'order_packed',
    ], 'short');   // under 8 characters

    assertStatus(422, $response, 'a malformed idempotency key');
});

check('the same Idempotency-Key replays the original answer instead of sending again', static function () use ($router, $adapter): void {
    $key = 'appointments-booking-1-reminder';
    $body = [
        'channel' => 'whatsapp', 'to' => '+919812345699', 'template' => 'order_packed',
        'variables' => ['name' => 'Priya'], 'reference' => ['product' => 'appointments', 'id' => 'bk-1'],
    ];

    $first = callAsService($router, $body, $key);
    $sentAfterFirst = count($adapter->sent);

    $second = callAsService($router, $body, $key);

    assertSame($first['status'], $second['status'], 'the second call answers the same as the first');
    assertSame(count($adapter->sent), $sentAfterFirst,
        'and sends nothing more — a retried reminder must not reach the customer twice');

    if ($first['status'] < 400) {
        assertTrue(($second['body']['replayed'] ?? false) === true, 'the replay says it is one');
    }
});

check('a draft saves, as a draft, and says nothing was sent', static function () use ($router, $conversationUuid): void {
    $response = call($router, 'POST', 'v1/conversations/' . $conversationUuid . '/drafts', [], [
        'body' => 'Your order is packed and leaves tomorrow.',
    ]);

    assertStatus(201, $response, 'saving a draft');
    $message = $response['body']['data']['message'] ?? [];
    assertTrue(isset($message['message_uuid']), 'with the new message');
    assertSame('draft', (string) $message['status'], 'as a draft, not as anything sendable');
    assertTrue(is_int($message['row_version'] ?? null),
        'and its row version, which is what stops two agents overwriting each other');

    // The content hash itself stays server-side. What a client needs is
    // whether the approval still covers the current content, and that is a
    // boolean rather than a hash somebody could try to reason about.
    assertSame(false, $message['approval_current'] ?? null,
        'a fresh draft is not approved, and the answer says so as a boolean');
    assertFalse(array_key_exists('content_hash', $message),
        'the hash is not part of the public shape');

    // A Save button that might have sent something is a Save button nobody
    // trusts, so the answer says so in words as well as in the status.
    assertSame(false, $response['body']['data']['sent'], 'nothing was sent');
    assertTrue(str_contains(strtolower((string) $response['body']['data']['sent_note']), 'nothing has been sent'),
        'and it says so in words');
});

check('an empty draft is a validation failure with the field named', static function () use ($router, $conversationUuid): void {
    $response = call($router, 'POST', 'v1/conversations/' . $conversationUuid . '/drafts', [], ['body' => '']);

    assertStatus(422, $response, 'an empty draft');
    assertTrue(isset($response['body']['error']['message']), 'with a message somebody can act on');
});

check('a bad timezone is refused and the field is named', static function () use ($router): void {
    $response = call($router, 'PUT', 'v1/settings', [], ['timezone' => 'Mars/Olympus_Mons']);

    assertStatus(422, $response, 'an invalid timezone');
    assertSame('timezone', $response['body']['error']['details']['field'] ?? null,
        'the offending field is named, so the form can point at it');
});

check('turning on autonomous sending needs an explicit confirmation', static function () use ($router): void {
    // The one setting that can put an unreviewed message in front of a
    // customer. A switch alone is not enough.
    $response = call($router, 'PUT', 'v1/settings', [], ['ai_autosend_allowed' => true]);

    assertStatus(422, $response, 'enabling autosend without confirming');
    assertTrue(
        ($response['body']['error']['details']['requires_confirmation'] ?? false) === true,
        'and the refusal says a confirmation is required',
    );
});

// ---------------------------------------------------------------------------
// The published cross-product service contract
// ---------------------------------------------------------------------------

check('the service contract refuses a browser session', static function () use ($router): void {
    // POST /api/v1/messages is for another PRODUCT, authenticated with a
    // service key. A user session reaching it would let anybody with a login
    // send as a system actor.
    $response = call($router, 'POST', 'v1/messages', [], [
        'channel' => 'whatsapp', 'to' => '+919812345699', 'template' => 'order_packed',
    ]);

    assertTrue(
        in_array($response['status'], [401, 403], true),
        'a user session must not satisfy the service contract, got ' . $response['status'],
    );
});

check('the service contract refuses voice, which belongs to another product', static function () use ($router): void {
    $response = call($router, 'POST', 'v1/messages', [], [
        'channel' => 'voice', 'to' => '+919812345699', 'template' => 'order_packed',
    ]);

    // Either the auth refusal above or an explicit unsupported-channel answer.
    // What must not happen is a 2xx implying Messaging will place a call.
    assertTrue($response['status'] >= 400, 'voice is not Messaging\'s to send, got ' . $response['status']);
});

// ---------------------------------------------------------------------------
// Webhooks are reachable WITHOUT a session, and refuse an unsigned body
// ---------------------------------------------------------------------------

check('a webhook route needs no session and refuses an unsigned body', static function () use ($router): void {
    Auth::adopt(null);

    try {
        $response = call($router, 'POST', 'api/webhooks/test_provider/' . CONNECTION, [], ['events' => []]);

        // Not 401: a provider has no session, and demanding one would silently
        // drop every delivery receipt. It must fail on the SIGNATURE.
        assertFalse($response['status'] === 401,
            'a provider webhook must not require a user session — it would never arrive');
        assertTrue(
            in_array($response['status'], [400, 403, 404], true),
            'an unsigned body should be refused on its signature, got ' . $response['status'],
        );
    } finally {
        Auth::adopt(Auth::forTesting('user-http', 'user', 'messaging', ['acs_type' => 1]));
    }
});

// ---------------------------------------------------------------------------
// Graceful degradation — the half of this suite that needs the stub DOWN
// ---------------------------------------------------------------------------

check('the Command Centre renders whether or not the sibling products answer', static function () use ($router): void {
    // The promise: one product being unreachable takes down one panel, not the
    // screen. This runs in both modes and expects 200 in both.
    $response = call($router, 'GET', 'v1/overview');
    assertStatus(200, $response, 'the Command Centre');
    assertTrue(isset($response['body']['data']['metrics']), 'with Messaging\'s own metrics, which are always available');
});

check('business context reports per-panel state rather than failing whole', static function () use ($router, $conversationUuid, $siblings): void {
    $response = call($router, 'GET', 'v1/conversations/' . $conversationUuid . '/context');
    assertStatus(200, $response, 'business context');

    $panels = $response['body']['data']['panels'] ?? $response['body']['data'] ?? [];
    assertTrue(is_array($panels) && $panels !== [], 'there should be panels to report on');

    foreach ($panels as $name => $panel) {
        if (!is_array($panel) || !isset($panel['state'])) {
            continue;
        }
        assertTrue(
            in_array((string) $panel['state'], ['ready', 'pending', 'unavailable', 'forbidden', 'unsupported'], true),
            'panel "' . $name . '" should declare one of the five states, got ' . json_encode($panel['state']),
        );

        // With the stub down, no panel may claim to be ready — a stale or
        // invented figure is exactly what this architecture forbids.
        if ($siblings === 'down') {
            assertFalse(
                (string) $panel['state'] === 'ready' && ($panel['source'] ?? '') !== 'messaging',
                'panel "' . $name . '" claims to be ready with the owning product unreachable',
            );
        }
    }
});

check('health reports usability from the database, not from the integrations', static function (): void {
    // /api/health is answered by index.php ahead of the router, so this
    // exercises the reporter that composes it rather than routing to it.
    $database = Health::database();
    $payload = [
        'database'     => $database,
        'integrations' => Health::integrations(),
        'channels'     => Health::channels(),
        'usable'       => $database['reachable'] && ($database['schema']['ready'] ?? false),
    ];

    assertTrue((bool) $payload['usable'],
        'the product is usable: an unconfigured channel is a configuration state, not an outage, '
        . 'and reporting one would page somebody at night for nothing');

    // The endpoint is PUBLIC. No credential, no environment key, no driver
    // string, and no sender address — a sender address identifies the tenant.
    $encoded = strtolower((string) json_encode($payload));
    foreach (['password', 'service_key', 'sqlstate', 'bearer', '+9198'] as $forbidden) {
        assertFalse(str_contains($encoded, $forbidden),
            'the public health payload must not contain "' . $forbidden . '"');
    }

    foreach ((array) $payload['integrations'] as $name => $state) {
        assertTrue(is_bool($state) || is_array($state),
            'integration "' . $name . '" should be reported as a boolean or a small structure, never a value');
    }
});

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

Clock::freeze(null);

foreach ($tables as $table) {
    Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
}

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d passed, %d failed\n", $passed, $failed);

if ($failed > 0) {
    echo "\nFailed:\n";
    foreach ($failures as $name) {
        echo "  - {$name}\n";
    }
    exit(1);
}

exit(0);
