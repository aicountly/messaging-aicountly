<?php

declare(strict_types=1);

/**
 * The cross-product service contract — Appointments' client notices.
 *
 * Runs the REAL router and controllers against a real database, with the
 * dispatch pipeline pointed at tests/support/RecordingAdapter.php instead of a
 * provider. What this file proves, by name, is in docs/APPOINTMENTS_MESSAGING_CONTRACT.md:
 * company context, E.164, templates, consent at send time, idempotency per key,
 * not_after, delivery state, retry-safe refusals, caps.
 *
 * ## The fixtures
 *
 * tests/fixtures/service-api/ holds the exchanges this receiver ACTUALLY
 * produces, one per scenario, and `contract.json`. They are the other half of
 * the contract: Appointments' test suite replays them from its Messaging stub
 * and checks its own requests against contract.json, so neither repository's
 * fixtures agree merely with their own client (messaging-aicountly-F14).
 *
 *   server-php/tests/run.sh                                   compares live output to the committed fixtures
 *   REGENERATE_FIXTURES=1 php server-php/tests/service.php    rewrites them (then copy them to Appointments)
 *
 * A fixture that no longer matches is a contract change; the diff is the
 * review, and the same files must be copied to appointments-aicountly.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support/RecordingAdapter.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Domain\AppointmentTemplates;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Domain\DispatchService;
use Aicountly\Api\Domain\MessageState;
use Aicountly\Api\Domain\SecretarialTemplates;
use Aicountly\Api\Domain\ServiceConsent;
use Aicountly\Api\Domain\SourceReader;
use Aicountly\Api\Domain\TemplateService;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\RecordingAdapter;

const CMP = 9301;
const CONN_WA = '44444444-4444-4444-8444-444444444441';
const CONN_SMS = '44444444-4444-4444-8444-444444444442';
const KEY_APPOINTMENTS = 'test-appointments-inbound-key-0123456789';
const KEY_BILLING = 'test-billing-inbound-key-0123456789';
const KEY_SECRETARIAL = 'test-secretarial-inbound-key-0123456789';
const FIXTURES = __DIR__ . '/fixtures/service-api';

$regenerate = getenv('REGENERATE_FIXTURES') === '1';

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
        if (getenv('VERBOSE')) {
            echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failed++;
        $failures[] = $name;
    } finally {
        putenv('CONSENT_ACCEPT_UNVERIFIED');
        putenv('SERVICE_ADDRESS_DAILY_CAP');
        putenv('SERVICE_COMPANY_PER_MINUTE');
        putenv('CONSENT_SERVICE_APPS');
        Clock::freeze('2026-10-14T04:00:00Z');
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

/**
 * One call as a sibling product backend would make it: a service key, an
 * Idempotency-Key, and cmp_id IN THE BODY (never injected into $_GET — the
 * original suite did that, which is how messaging-aicountly-F1 passed CI).
 *
 * @param array<string, mixed> $body
 * @return array{status:int, body:array<string, mixed>}
 */
function service(Router $router, string $method, string $path, ?array $body, ?string $key = null, string $app = KEY_APPOINTMENTS, array $query = []): array
{
    $previous = Auth::resolve();
    Auth::adopt(null);

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_X_SERVICE_KEY'] = $app;
    // A service call names the environment it is for, and it must be this one (G19#7).
    $_SERVER['HTTP_X_AIC_ENVIRONMENT'] = 'local';
    $_GET = $query;
    Http::setBodyForTesting($body);
    if ($key === null) {
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    } else {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $key;
    }

    try {
        ob_start();
        $matched = $router->dispatch($method, $path);
        ob_end_clean();

        return ['status' => $matched ? 0 : 404, 'body' => []];
    } catch (ResponseSent $e) {
        return ['status' => $e->status, 'body' => $e->payload];
    } catch (\Throwable $e) {
        return ['status' => 500, 'body' => ['error' => ['message' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]]];
    } finally {
        Http::setBodyForTesting(null);
        unset($_SERVER['HTTP_X_SERVICE_KEY'], $_SERVER['HTTP_X_AIC_ENVIRONMENT'], $_SERVER['HTTP_IDEMPOTENCY_KEY']);
        Auth::adopt($previous ?? Auth::forTesting('user-svc', 'user', 'messaging', ['acs_type' => 1]));
    }
}

/** @return array<string, mixed> a request body Appointments would send */
function notice(string $to, array $over = []): array
{
    return array_replace_recursive([
        'cmp_id'          => CMP,
        'channel'         => 'whatsapp',
        'to'              => $to,
        'template'        => 'appointment_reminder',
        'language'        => 'en',
        'kind'            => 'reminder',
        'reference'       => '7d4f0b1e-52c3-4a8e-9f10-2b6a8c3d9e01',
        'reference_label' => 'AP-1042',
        'scheduled_for'   => '2026-10-14T03:50:00Z',
        'not_after'       => '2026-10-14T10:00:00+05:30',
        'variables'       => [
            'client_name'  => 'Priya Nair',
            'service_name' => 'Initial consultation',
            'when'         => 'Wed 14 Oct 2026, 10:00 IST',
            'reference'    => 'AP-1042',
        ],
        'consent'         => [
            'basis'            => 'staff_attestation',
            'source'           => 'staff:user-0001',
            'captured_at'      => '2026-10-13T11:00:00+05:30',
            'contact_verified' => true,
            'evidence_ref'     => '7d4f0b1e-52c3-4a8e-9f10-2b6a8c3d9e01',
        ],
    ], $over);
}

function idem(string $suffix): string
{
    return 'appt.7d4f0b1e-52c3-4a8e-9f10-2b6a8c3d9e01.reminder.' . $suffix;
}

/** Replace what changes between runs with stable tokens, so a fixture is comparable. */
function normalise(mixed $value, string $key = ''): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = normalise($v, is_string($k) ? $k : $key);
        }

        return $out;
    }
    if (is_string($value)) {
        if (in_array($key, ['message_uuid', 'message_id', 'conversation_uuid'], true) && Uuid::isValid($value)) {
            return '{{' . $key . '}}';
        }
        if ($key === 'provider_message_id') {
            return '{{provider_message_id}}';
        }
        if (preg_match('/(_at|^scheduled_for|^not_after|^received_at|^occurred_at)$/', $key) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return '{{timestamp}}';
        }
        if (preg_match('/messages? [0-9a-f-]{36}/', $value) === 1) {
            return preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '{{uuid}}', $value);
        }
    }

    return $value;
}

/**
 * Compare (or write) one fixture.
 *
 * @param array<string, mixed> $request
 * @param array{status:int, body:array<string, mixed>} $response
 */
function fixture(string $name, array $request, array $response): void
{
    global $regenerate;

    $document = [
        'scenario' => $name,
        'request'  => normalise($request),
        'response' => ['status' => $response['status'], 'body' => normalise($response['body'])],
    ];
    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $path = FIXTURES . '/' . $name . '.json';

    if ($regenerate) {
        if (!is_dir(FIXTURES)) {
            mkdir(FIXTURES, 0775, true);
        }
        file_put_contents($path, $json);

        return;
    }

    assertTrue(is_file($path), 'fixture ' . $name . '.json is missing (REGENERATE_FIXTURES=1 writes it)');
    assertSame((string) file_get_contents($path), $json,
        'fixture ' . $name . '.json no longer matches what the receiver produces — a contract change; regenerate and copy to Appointments');
}

// ---------------------------------------------------------------------------
// Fixtures: a company with a WhatsApp and an SMS line and the four templates
// ---------------------------------------------------------------------------

$tables = [
    'messaging_delivery_events', 'messaging_dispatch_jobs', 'messaging_message_attachments',
    'messaging_messages', 'messaging_external_references', 'messaging_conversations',
    'messaging_consent_events', 'messaging_consent_records', 'messaging_suppressions',
    'messaging_template_versions', 'messaging_templates', 'messaging_channel_capabilities',
    'messaging_channel_connections', 'messaging_audit_events', 'messaging_idempotency_keys',
    'messaging_daily_metrics', 'messaging_settings',
];

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the test database: {$e->getMessage()}\n");
    exit(1);
}

$owner = Auth::forTesting('user-svc', 'user', 'messaging', ['acs_type' => 1]);
Auth::adopt($owner);
Context::trustForTesting(CMP, $owner);
Clock::freeze('2026-10-14T04:00:00Z');
Permissions::forget();
Domain\Settings::forget();

foreach ($tables as $table) {
    Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
}

$adapter = new RecordingAdapter();
ChannelRegistry::overrideForTesting('test_provider', $adapter);
putenv('MESSAGING_SERVICE_TEST_TOKEN=svc-test-token');

foreach ([[CONN_WA, 'whatsapp', '+919800000011', 'Sharma & Co'], [CONN_SMS, 'sms', 'SHARMA', 'Sharma & Co SMS']] as [$uuid, $channel, $sender, $display]) {
    Db::insert('messaging_channel_connections', [
        'connection_uuid' => $uuid, 'cmp_id' => CMP, 'bo_id' => 0, 'channel' => $channel,
        'provider' => 'test_provider', 'display_name' => $display, 'sender_address' => $sender,
        'provider_account_ref' => 'acct-svc', 'credential_ref' => 'MESSAGING_SERVICE_TEST_TOKEN',
        'webhook_secret_ref' => 'MESSAGING_SERVICE_TEST_WEBHOOK', 'status' => 'connected',
        'is_active' => true, 'created_at' => Clock::nowSql(), 'updated_at' => Clock::nowSql(),
    ], 'connection_uuid');
}

$ctx = Context::forCompany(CMP);
$provider = Auth::forProvider('service-test');

// The company allows these products to send for it with nobody signed in (G19#7):
// what an administrator does in Settings. Without it the contract refuses.
Domain\Settings::save($ctx, $owner, ['service_products' => ['appointments', 'billing']]);

// The catalogue creates the templates, drafts; the provider "approves" the
// WhatsApp ones. SMS stays unapproved on purpose (template_not_approved).
AppointmentTemplates::seed($ctx, $provider, ['whatsapp', 'sms'], true);
foreach (Db::all("SELECT t.template_uuid, t.channel FROM messaging_templates t WHERE t.cmp_id = :cmp", ['cmp' => CMP]) as $t) {
    if ($t['channel'] === 'whatsapp') {
        TemplateService::recordProviderStatus($ctx, (string) $t['template_uuid'], 1, 'en', 'approved', 'prov-tpl-' . substr((string) $t['template_uuid'], 0, 8));
    }
}

$router = new Router();
Routes::register($router);

echo "Service contract tests: Appointments notices\n";
echo str_repeat('=', 60) . "\n";

$count = static fn (string $sql, array $p = []): int => (int) Db::scalar($sql, $p);

// ---------------------------------------------------------------------------
// The catalogue and the shared contract.json
// ---------------------------------------------------------------------------

$contract = [
    'e164_pattern' => '^\\+[1-9][0-9]{7,14}$',
    'channels'     => ['whatsapp', 'sms'],
    'consent_bases' => ServiceConsent::BASES,
    'kinds'        => array_keys(AppointmentTemplates::catalogue()),
    'templates'    => array_map(static fn (array $t) => ['name' => $t['name'], 'variables' => $t['variables']], AppointmentTemplates::catalogue()),
    'built_in_variables' => AppointmentTemplates::BUILT_IN,
    'required_request_fields' => ['cmp_id', 'channel', 'to', 'template', 'kind', 'reference', 'scheduled_for', 'not_after', 'variables'],
    'idempotency_key_pattern' => '^[A-Za-z0-9._:-]{8,200}$',
    'delivery_states' => ['queued', 'sent', 'delivered', 'failed', 'suppressed', 'expired', 'cancelled', 'unknown'],
    'terminal_states' => ['delivered', 'failed', 'suppressed', 'expired', 'cancelled'],
];

check('contract.json is what this receiver implements (and what Appointments is held to)', static function () use ($contract, $regenerate): void {
    $json = json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $path = FIXTURES . '/contract.json';
    if ($regenerate) {
        if (!is_dir(FIXTURES)) {
            mkdir(FIXTURES, 0775, true);
        }
        file_put_contents($path, $json);

        return;
    }
    assertTrue(is_file($path), 'contract.json is missing');
    assertSame((string) file_get_contents($path), $json, 'contract.json differs from the receiver\'s own constants');
});

check('the E.164 pattern in contract.json is the one the controller enforces', static function () use ($contract): void {
    $controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ServiceController.php');
    assertTrue(str_contains($controller, "'/^\\+[1-9][0-9]{7,14}$/'"), 'ServiceController::E164_PATTERN drifted from contract.json');
    assertSame(1, preg_match('/' . $contract['e164_pattern'] . '/', '+919876543210'), 'a real E.164 number matches');
});

check('every kind has a seeded template whose declared variables are exactly the catalogue\'s', static function () use ($ctx): void {
    foreach (AppointmentTemplates::catalogue() as $kind => $template) {
        $row = Db::first(
            'SELECT v.variable_schema FROM messaging_templates t
             JOIN messaging_template_versions v ON v.template_uuid = t.template_uuid
             WHERE t.cmp_id = :cmp AND t.channel = \'whatsapp\' AND t.name = :name',
            ['cmp' => CMP, 'name' => $template['name']],
        );
        assertTrue($row !== null, 'template ' . $template['name'] . ' was seeded');
        $declared = array_column(Db::jsonColumn($row['variable_schema']), 'name');
        assertSame($template['variables'], $declared, $kind . ' variables');
    }
});

check('seeding is a dry run by default and never touches an existing template', static function () use ($ctx, $provider): void {
    $before = $GLOBALS['count']('SELECT COUNT(*) FROM messaging_templates WHERE cmp_id = :cmp', ['cmp' => CMP]);
    $report = AppointmentTemplates::seed($ctx, $provider, ['whatsapp', 'sms'], false);
    assertSame($before, $GLOBALS['count']('SELECT COUNT(*) FROM messaging_templates WHERE cmp_id = :cmp', ['cmp' => CMP]), 'nothing written');
    assertSame(['exists'], array_values(array_unique(array_column($report, 'action'))), 'all existing templates are left alone');
});

// ---------------------------------------------------------------------------
// Request validation: company context, address, channel
// ---------------------------------------------------------------------------

check('no cmp_id is a 400 context_required (and cmp_id in the BODY is enough)', static function () use ($router, &$regenerate): void {
    $body = notice('+919000000014');
    unset($body['cmp_id']);
    $response = service($router, 'POST', 'v1/messages', $body, idem('t1'));
    assertSame(400, $response['status'], 'a company is required');
    assertSame('context_required', $response['body']['error']['code'] ?? null, 'named');
    fixture('context_required', $body, $response);
});

check('a local-format phone number is refused invalid_address, never guessed', static function () use ($router, $adapter): void {
    foreach (['9876543210', '+91 98765 43210', '09876543210', '+0123456789', '+91987'] as $bad) {
        $before = count($adapter->sent);
        $response = service($router, 'POST', 'v1/messages', notice($bad), idem('bad' . md5($bad)));
        assertSame(422, $response['status'], $bad . ' is refused');
        assertSame('invalid_address', $response['body']['error']['code'] ?? null, $bad . ' named invalid_address');
        assertSame(false, $response['body']['error']['details']['retryable'] ?? null, 'and not retryable');
        assertSame($before, count($adapter->sent), 'nothing reached a provider');
    }
    $request = notice('9876543210');
    fixture('invalid_address', $request, service($router, 'POST', 'v1/messages', $request, idem('bad-fixture')));
});

check('email is not Messaging\'s: refused email_not_enabled, not retryable', static function () use ($router): void {
    $request = notice('+919000000015', ['channel' => 'email']);
    $response = service($router, 'POST', 'v1/messages', $request, idem('email1'));
    assertSame(422, $response['status'], 'email refused');
    assertSame('email_not_enabled', $response['body']['error']['code'] ?? null, 'named');
    assertSame(false, $response['body']['error']['details']['retryable'] ?? null, 'not retryable');
    fixture('email_not_enabled', $request, $response);
});

check('a browser session cannot use the service endpoint', static function () use ($router): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Http::setBodyForTesting(notice('+919000000016'));
    $_GET = [];
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = idem('browser1');
    try {
        $router->dispatch('POST', 'v1/messages');
        throw new \RuntimeException('expected a refusal');
    } catch (ResponseSent $e) {
        assertSame(403, $e->status, 'a user session is refused');
    } finally {
        Http::setBodyForTesting(null);
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    }
});

// ---------------------------------------------------------------------------
// Consent: recorded by the caller, enforced here
// ---------------------------------------------------------------------------

check('no consent at all is suppressed with a reason, nothing is sent, and the key is NOT consumed', static function () use ($router, $adapter): void {
    $request = notice('+919000000005');
    unset($request['consent']);
    $before = count($adapter->sent);

    $response = service($router, 'POST', 'v1/messages', $request, idem('nc1'));
    assertSame(422, $response['status'], 'refused');
    assertSame('no_consent', $response['body']['error']['code'] ?? null, 'named');
    assertSame('suppressed', $response['body']['data']['delivery_state'] ?? null, 'reported as suppressed');
    assertSame('no_consent', $response['body']['data']['reason_code'] ?? null, 'with the reason');
    assertSame($before, count($adapter->sent), 'nothing sent');
    fixture('no_consent', $request, $response);

    // Consent is then recorded (by an agent in Messaging, say) and the SAME
    // key goes through: a refusal that created nothing is not stored.
    ConsentService::record(Context::forCompany(CMP), Auth::forTesting('agent-1', 'user'), 'whatsapp', '+919000000005', 'transactional', 'granted', 'agent_recorded', 'Asked on the phone.');
    $again = service($router, 'POST', 'v1/messages', $request, idem('nc1'));
    assertSame(202, $again['status'], 'the same key now succeeds');
    assertSame(1, count($adapter->sent) - $before, 'and sends exactly once');
});

check('a booking-time consent from Appointments is recorded with its evidence and then enforced', static function () use ($router, $adapter): void {
    $request = notice('+919000000001');
    $response = service($router, 'POST', 'v1/messages', $request, idem('ok1'));
    assertSame(202, $response['status'], 'accepted');
    assertSame('sent', $response['body']['data']['delivery_state'] ?? null, 'the provider took it');
    assertSame('granted', $response['body']['data']['consent']['action'] ?? null, 'consent recorded');

    $row = Db::first('SELECT * FROM messaging_consent_records WHERE cmp_id = :cmp AND address = :a', ['cmp' => CMP, 'a' => '+919000000001']);
    assertSame('granted', $row['state'] ?? null, 'a granted record exists');
    assertSame('service_booking', $row['evidence_source'] ?? null, 'with the booking evidence source');
    assertTrue(str_contains((string) $row['evidence_detail'], 'basis=staff_attestation'), 'and the basis');
    assertTrue(str_contains((string) $row['evidence_detail'], 'evidence_ref=7d4f0b1e'), 'and the booking it came from');
    $event = Db::first('SELECT actor_kind, event FROM messaging_consent_events WHERE cmp_id = :cmp AND address = :a', ['cmp' => CMP, 'a' => '+919000000001']);
    assertSame('service', $event['actor_kind'] ?? null, 'the history says a service recorded it');
    fixture('accepted', $request, $response);
});

check('an unverified public-form checkbox is PENDING: visible, not a grant, nothing sent', static function () use ($router, $adapter): void {
    $request = notice('+919000000006', ['consent' => ['basis' => 'public_form_checkbox', 'source' => 'public_booking_page:main', 'contact_verified' => false]]);
    $before = count($adapter->sent);
    $response = service($router, 'POST', 'v1/messages', $request, idem('pend1'));

    assertSame(422, $response['status'], 'refused');
    assertSame('suppressed', $response['body']['data']['delivery_state'] ?? null, 'suppressed');
    assertSame('consent_pending', $response['body']['data']['reason_code'] ?? null, 'because consent is pending');
    assertSame($before, count($adapter->sent), 'nothing sent');
    $record = Db::first('SELECT state FROM messaging_consent_records WHERE cmp_id = :cmp AND address = :a', ['cmp' => CMP, 'a' => '+919000000006']);
    assertSame('pending', $record['state'] ?? null, 'recorded as pending');
    assertSame(0, $GLOBALS['count']('SELECT COUNT(*) FROM messaging_consent_events WHERE cmp_id = :cmp AND address = :a', ['cmp' => CMP, 'a' => '+919000000006']),
        'a pending claim is not a history event (and certainly not a withdrawal)');
    fixture('consent_pending', $request, $response);
});

check('the deployment may choose to accept an unverified checkbox, and the evidence says so', static function () use ($router, $adapter): void {
    putenv('CONSENT_ACCEPT_UNVERIFIED=1');
    $request = notice('+919000000017', ['consent' => ['basis' => 'public_form_checkbox', 'source' => 'public_booking_page:main', 'contact_verified' => false]]);
    $response = service($router, 'POST', 'v1/messages', $request, idem('unv1'));
    assertSame(202, $response['status'], 'accepted by deployment policy');
    $row = Db::first('SELECT evidence_detail FROM messaging_consent_records WHERE cmp_id = :cmp AND address = :a', ['cmp' => CMP, 'a' => '+919000000017']);
    assertTrue(str_contains((string) $row['evidence_detail'], 'accepted unverified by deployment policy'), 'the audit trail names the policy');
});

check('a withdrawn consent is never overridden by a request carrying consent', static function () use ($router, $adapter): void {
    ConsentService::record(Context::forCompany(CMP), Auth::forTesting('agent-1', 'user'), 'whatsapp', '+919000000007', 'transactional', 'withdrawn', 'customer_message', 'Replied STOP.');
    $request = notice('+919000000007');
    $before = count($adapter->sent);
    $response = service($router, 'POST', 'v1/messages', $request, idem('wd1'));

    assertSame(422, $response['status'], 'refused');
    assertSame('suppressed', $response['body']['data']['delivery_state'] ?? null, 'suppressed');
    assertSame('withdrawn', $response['body']['data']['reason_code'] ?? null, 'withdrawn');
    assertSame($before, count($adapter->sent), 'nothing sent');
    $record = Db::first('SELECT state FROM messaging_consent_records WHERE cmp_id = :cmp AND address = :a', ['cmp' => CMP, 'a' => '+919000000007']);
    assertSame('withdrawn', $record['state'] ?? null, 'and the record is still withdrawn');
    fixture('withdrawn', $request, $response);
});

check('an active suppression is never overridden either', static function () use ($router, $adapter): void {
    ConsentService::suppress(Context::forCompany(CMP), Auth::forTesting('agent-1', 'user'), 'whatsapp', '+919000000008', 'hard_bounce', 'Number not on WhatsApp.');
    $request = notice('+919000000008');
    $response = service($router, 'POST', 'v1/messages', $request, idem('sup1'));

    assertSame(422, $response['status'], 'refused');
    assertSame('suppressed', $response['body']['error']['code'] ?? null, 'named');
    assertSame('suppressed', $response['body']['data']['delivery_state'] ?? null, 'suppressed');
    assertSame(0, $GLOBALS['count']('SELECT COUNT(*) FROM messaging_consent_records WHERE cmp_id = :cmp AND address = :a', ['cmp' => CMP, 'a' => '+919000000008']),
        'no consent was written for a suppressed address');
    fixture('suppressed', $request, $response);
});

check('a product that is not on the consent allow-list cannot create consent by sending it', static function () use ($router, $adapter): void {
    $request = notice('+919000000018');
    $response = service($router, 'POST', 'v1/messages', $request, idem('bill1'), KEY_BILLING);
    assertSame(422, $response['status'], 'billing is refused');
    assertSame('no_consent', $response['body']['error']['code'] ?? null, 'its consent object was ignored');
    assertSame(0, $GLOBALS['count']('SELECT COUNT(*) FROM messaging_consent_records WHERE cmp_id = :cmp AND address = :a', ['cmp' => CMP, 'a' => '+919000000018']), 'nothing recorded');
});

check('consent that is malformed or from the future is refused as a validation error', static function () use ($router): void {
    foreach ([
        ['basis' => 'because'],
        ['captured_at' => 'yesterday'],
        ['captured_at' => '2027-01-01T00:00:00Z'],
        ['source' => ''],
    ] as $index => $bad) {
        $response = service($router, 'POST', 'v1/messages', notice('+919000000019', ['consent' => $bad]), idem('badc' . $index));
        assertSame(422, $response['status'], 'bad consent #' . $index);
        assertSame('validation_failed', $response['body']['error']['code'] ?? null, 'named');
    }
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

check('the same key twice sends once and the replay says so, with the current state', static function () use ($router, $adapter): void {
    $request = notice('+919000000020');
    $before = count($adapter->sent);
    $first = service($router, 'POST', 'v1/messages', $request, idem('rep1'));
    assertSame(202, $first['status'], 'first accepted');
    $uuid = (string) $first['body']['data']['message_uuid'];

    // The provider reports delivery in between.
    Db::run("UPDATE messaging_messages SET status = 'delivered', delivered_at = NOW() WHERE message_uuid = :u", ['u' => $uuid]);

    $second = service($router, 'POST', 'v1/messages', $request, idem('rep1'));
    assertSame(1, count($adapter->sent) - $before, 'one provider send for two requests');
    assertSame(true, $second['body']['replayed'] ?? null, 'the second is a replay');
    assertSame($uuid, $second['body']['data']['message_uuid'] ?? null, 'the same message');
    assertSame('delivered', $second['body']['data']['delivery_state'] ?? null, 'with its CURRENT state, not the first answer\'s');
    assertSame(200, $second['status'], 'and the status that state implies');
});

check('the same key with a different request is a conflict, not a wrong answer', static function () use ($router): void {
    $one = service($router, 'POST', 'v1/messages', notice('+919000000021'), idem('conf1'));
    assertSame(202, $one['status'], 'first accepted');
    $two = service($router, 'POST', 'v1/messages', notice('+919000000021', ['variables' => ['when' => 'Thu 15 Oct 2026, 11:00 IST']]), idem('conf1'));
    assertSame(409, $two['status'], 'a reused key with another body is a conflict');
});

check('a refusal before any message existed does not poison the key (template approved later)', static function () use ($router, $adapter, $ctx): void {
    $request = notice('+919000000010', ['channel' => 'sms']);
    $first = service($router, 'POST', 'v1/messages', $request, idem('tpl1'));
    assertSame(422, $first['status'], 'SMS template is not approved yet');
    assertSame('template_not_approved', $first['body']['error']['code'] ?? null, 'named');
    assertSame(true, $first['body']['error']['details']['retryable'] ?? null, 'and retryable: an administrator can fix it');
    fixture('template_not_approved', $request, $first);

    $row = Db::first("SELECT t.template_uuid FROM messaging_templates t WHERE t.cmp_id = :cmp AND t.channel = 'sms' AND t.name = 'appointment_reminder'", ['cmp' => CMP]);
    TemplateService::recordProviderStatus($ctx, (string) $row['template_uuid'], 1, 'en', 'approved', 'prov-sms-1');

    $before = count($adapter->sent);
    $second = service($router, 'POST', 'v1/messages', $request, idem('tpl1'));
    assertSame(202, $second['status'], 'the same key now succeeds');
    assertSame(1, count($adapter->sent) - $before, 'exactly one send');
    assertTrue(!isset($second['body']['replayed']), 'it was not a replay of the refusal');
});

check('a missing template is a retryable 404 and is not stored either', static function () use ($router): void {
    $request = notice('+919000000022', ['template' => 'appointment_no_such']);
    $response = service($router, 'POST', 'v1/messages', $request, idem('tm1'));
    assertSame(404, $response['status'], 'template_missing');
    assertSame('template_missing', $response['body']['error']['code'] ?? null, 'named');
    assertSame(0, $GLOBALS['count']("SELECT COUNT(*) FROM messaging_idempotency_keys WHERE cmp_id = :cmp AND idempotency_key = :k", ['cmp' => CMP, 'k' => idem('tm1')]), 'no stored key');
});

// ---------------------------------------------------------------------------
// What a 2xx means: provider outcomes
// ---------------------------------------------------------------------------

check('a provider failure is NOT a 2xx: 502 send_failed, with the message and the reason', static function () use ($router, $adapter): void {
    $adapter->script = ['failed'];
    $request = notice('+919000000004');
    $response = service($router, 'POST', 'v1/messages', $request, idem('fail1'));
    assertSame(502, $response['status'], 'a failed send is not a success');
    assertSame('send_failed', $response['body']['error']['code'] ?? null, 'named');
    assertSame('failed', $response['body']['data']['delivery_state'] ?? null, 'failed');
    assertSame('provider_rejected', $response['body']['data']['reason_code'] ?? null, 'with the provider\'s reason');
    assertTrue(is_string($response['body']['data']['message_uuid'] ?? null), 'and the message uuid, so staff can see it');
    assertSame(true, $response['body']['data']['terminal'] ?? null, 'terminal');
    fixture('failed', $request, $response);
});

check('a retryable provider error is queued (202), not sent and not failed', static function () use ($router, $adapter): void {
    $adapter->script = ['retry'];
    $request = notice('+919000000002');
    $response = service($router, 'POST', 'v1/messages', $request, idem('retry1'));
    assertSame(202, $response['status'], 'accepted into Messaging\'s own retry queue');
    assertSame('queued', $response['body']['data']['delivery_state'] ?? null, 'queued');
    assertSame(false, $response['body']['data']['delivered'] ?? null, 'not delivered');
    fixture('queued', $request, $response);
});

check('a send that timed out is unknown (202), never failed and never resent', static function () use ($router, $adapter): void {
    $adapter->script = ['unknown'];
    $request = notice('+919000000003');
    $before = count($adapter->sent);
    $response = service($router, 'POST', 'v1/messages', $request, idem('unk1'));
    assertSame(202, $response['status'], 'accepted for investigation');
    assertSame('unknown', $response['body']['data']['delivery_state'] ?? null, 'unknown');
    $again = service($router, 'POST', 'v1/messages', $request, idem('unk1'));
    assertSame(1, count($adapter->sent) - $before, 'and the retry did not send again');
    assertSame('unknown', $again['body']['data']['delivery_state'] ?? null, 'still unknown');
    fixture('unknown', $request, $response);
});

// ---------------------------------------------------------------------------
// not_after
// ---------------------------------------------------------------------------

check('not_after in the past is refused expired and nothing is created or sent', static function () use ($router, $adapter): void {
    $request = notice('+919000000009', ['not_after' => '2026-10-14T03:00:00Z']);
    $before = count($adapter->sent);
    $response = service($router, 'POST', 'v1/messages', $request, idem('exp1'));
    assertSame(422, $response['status'], 'refused');
    assertSame('expired', $response['body']['error']['code'] ?? null, 'named');
    assertSame('expired', $response['body']['data']['delivery_state'] ?? null, 'expired');
    assertSame($before, count($adapter->sent), 'nothing sent');
    fixture('expired', $request, $response);
});

check('a queued retry that outlives not_after is cancelled expired by the dispatcher, never sent late', static function () use ($router, $adapter): void {
    $adapter->script = ['retry'];
    $request = notice('+919000000023', ['not_after' => '2026-10-14T04:30:00Z']);
    $first = service($router, 'POST', 'v1/messages', $request, idem('late1'));
    assertSame('queued', $first['body']['data']['delivery_state'] ?? null, 'queued behind a provider error');
    $uuid = (string) $first['body']['data']['message_uuid'];
    $sentBefore = count($adapter->sent);

    // The provider incident lasts past the appointment.
    Clock::freeze('2026-10-14T05:00:00Z');
    Db::run("UPDATE messaging_dispatch_jobs SET available_at = '2026-10-14T04:01:00Z' WHERE message_uuid = :u", ['u' => $uuid]);
    foreach (DispatchService::claim('worker-1', 10) as $job) {
        DispatchService::process($job);
    }

    assertSame($sentBefore, count($adapter->sent), 'no late send');
    $status = service($router, 'GET', 'v1/messages/' . $uuid, null, null, KEY_APPOINTMENTS, ['cmp_id' => CMP]);
    assertSame(200, $status['status'], 'status readable');
    assertSame('expired', $status['body']['data']['delivery_state'] ?? null, 'ended expired');
    assertSame('expired', $status['body']['data']['reason_code'] ?? null, 'with the reason');
});

// ---------------------------------------------------------------------------
// Delivery state read
// ---------------------------------------------------------------------------

check('GET v1/messages/{id} reports delivery_state as the provider reports it', static function () use ($router, $adapter): void {
    $request = notice('+919000000024');
    $post = service($router, 'POST', 'v1/messages', $request, idem('st1'));
    $uuid = (string) $post['body']['data']['message_uuid'];
    $get = static fn () => service($router, 'GET', 'v1/messages/' . $uuid, null, null, KEY_APPOINTMENTS, ['cmp_id' => CMP]);

    $sent = $get();
    assertSame('sent', $sent['body']['data']['delivery_state'] ?? null, 'accepted by the provider is "sent"');
    assertSame(false, $sent['body']['data']['delivered'] ?? null, 'and is not delivered');
    assertSame($uuid, $sent['body']['data']['message_id'] ?? null, 'message_id is an alias');
    assertSame('7d4f0b1e-52c3-4a8e-9f10-2b6a8c3d9e01', $sent['body']['data']['reference'] ?? null, 'the booking reference is kept');
    assertSame('AP-1042', $sent['body']['data']['reference_label'] ?? null, 'and its label');
    assertSame('reminder', $sent['body']['data']['kind'] ?? null, 'and the kind');
    fixture('status_sent', ['GET' => 'v1/messages/{message_uuid}?cmp_id=' . CMP], $sent);

    Db::run("UPDATE messaging_messages SET status = 'delivered', delivered_at = NOW() WHERE message_uuid = :u", ['u' => $uuid]);
    $delivered = $get();
    assertSame('delivered', $delivered['body']['data']['delivery_state'] ?? null, 'delivered');
    assertSame(true, $delivered['body']['data']['delivered'] ?? null, 'and delivered says so');
    fixture('status_delivered', ['GET' => 'v1/messages/{message_uuid}?cmp_id=' . CMP], $delivered);

    Db::run("UPDATE messaging_messages SET status = 'failed', failed_at = NOW(), failure_code = 'recipient_unreachable', failure_detail = 'Number is not on WhatsApp.' WHERE message_uuid = :u", ['u' => $uuid]);
    $failed = $get();
    assertSame('failed', $failed['body']['data']['delivery_state'] ?? null, 'failed');
    assertSame('recipient_unreachable', $failed['body']['data']['reason_code'] ?? null, 'with the provider\'s code');
    fixture('status_failed', ['GET' => 'v1/messages/{message_uuid}?cmp_id=' . CMP], $failed);

    Db::run("UPDATE messaging_messages SET status = 'failed', failure_code = 'no_consent', failure_detail = 'Consent for this address was withdrawn.' WHERE message_uuid = :u", ['u' => $uuid]);
    assertSame('suppressed', $get()['body']['data']['delivery_state'] ?? null, 'a consent failure found at dispatch is reported suppressed');

    Db::run("UPDATE messaging_messages SET status = 'queued', failure_code = NULL, failure_detail = NULL WHERE message_uuid = :u", ['u' => $uuid]);
    $queued = $get();
    fixture('status_queued', ['GET' => 'v1/messages/{message_uuid}?cmp_id=' . CMP], $queued);
    Db::run("UPDATE messaging_messages SET status = 'submission_unknown' WHERE message_uuid = :u", ['u' => $uuid]);
    fixture('status_unknown', ['GET' => 'v1/messages/{message_uuid}?cmp_id=' . CMP], $get());
});

check('status needs the company, and a product reads only what it sent', static function () use ($router): void {
    $post = service($router, 'POST', 'v1/messages', notice('+919000000025'), idem('own1'));
    $uuid = (string) $post['body']['data']['message_uuid'];

    $noCmp = service($router, 'GET', 'v1/messages/' . $uuid, null, null, KEY_APPOINTMENTS, []);
    assertSame(400, $noCmp['status'], 'no cmp_id, no answer');

    $other = service($router, 'GET', 'v1/messages/' . $uuid, null, null, KEY_BILLING, ['cmp_id' => CMP]);
    assertSame(404, $other['status'], 'another product\'s key sees nothing (and cannot tell it exists)');

    $cancelOther = service($router, 'POST', 'v1/messages/' . $uuid . '/cancel', ['cmp_id' => CMP], null, KEY_BILLING);
    assertSame(404, $cancelOther['status'], 'and cannot cancel it');
});

check('stats are scoped to the calling product for every product', static function () use ($router): void {
    $mine = service($router, 'GET', 'v1/messages/stats', null, null, KEY_APPOINTMENTS, ['cmp_id' => CMP, 'range' => '30d']);
    assertSame(200, $mine['status'], 'appointments reads its stats');
    assertTrue(($mine['body']['data']['totals']['accepted'] ?? 0) + ($mine['body']['data']['totals']['failed'] ?? 0) > 0, 'it sees its own traffic');

    $billing = service($router, 'GET', 'v1/messages/stats', null, null, KEY_BILLING, ['cmp_id' => CMP, 'range' => '30d']);
    assertSame(0, ($billing['body']['data']['totals']['accepted'] ?? -1) + ($billing['body']['data']['totals']['failed'] ?? 0) + ($billing['body']['data']['totals']['cancelled'] ?? 0),
        'billing sees none of Appointments\' messages');
});

// ---------------------------------------------------------------------------
// Cancel
// ---------------------------------------------------------------------------

check('a queued message can be withdrawn; one the provider holds cannot', static function () use ($router, $adapter): void {
    $adapter->script = ['retry'];
    $queued = service($router, 'POST', 'v1/messages', notice('+919000000026'), idem('can1'));
    assertSame('queued', $queued['body']['data']['delivery_state'] ?? null, 'queued');
    $uuid = (string) $queued['body']['data']['message_uuid'];

    $cancel = service($router, 'POST', 'v1/messages/' . $uuid . '/cancel', ['cmp_id' => CMP, 'reason' => 'Booking cancelled.'], null);
    assertSame(200, $cancel['status'], 'withdrawn');
    assertSame('cancelled', $cancel['body']['data']['delivery_state'] ?? null, 'cancelled');
    fixture('cancel_ok', ['POST' => 'v1/messages/{message_uuid}/cancel', 'body' => ['cmp_id' => CMP, 'reason' => 'Booking cancelled.']], $cancel);

    $before = count($adapter->sent);
    Clock::freeze('2026-10-14T04:20:00Z');
    Db::run("UPDATE messaging_dispatch_jobs SET available_at = '2026-10-14T04:00:00Z' WHERE message_uuid = :u", ['u' => $uuid]);
    foreach (DispatchService::claim('worker-2', 10) as $job) {
        DispatchService::process($job);
    }
    assertSame($before, count($adapter->sent), 'the dispatcher never sends a withdrawn message');

    $sent = service($router, 'POST', 'v1/messages', notice('+919000000027'), idem('can2'));
    assertSame(202, $sent['status'], 'a second message goes through: ' . json_encode($sent['body']));
    $sentUuid = (string) $sent['body']['data']['message_uuid'];
    $tooLate = service($router, 'POST', 'v1/messages/' . $sentUuid . '/cancel', ['cmp_id' => CMP], null);
    assertSame(409, $tooLate['status'], 'too late');
    assertSame('already_dispatched', $tooLate['body']['error']['code'] ?? null, 'says so');
    fixture('cancel_too_late', ['POST' => 'v1/messages/{message_uuid}/cancel', 'body' => ['cmp_id' => CMP]], $tooLate);
});

// ---------------------------------------------------------------------------
// Variables and the sender
// ---------------------------------------------------------------------------

check('variables render verbatim (the caller formats the time in the booking\'s zone) and sender_name is the company\'s sender', static function () use ($router): void {
    foreach ([
        ['+919000000028', 'Wed 14 Oct 2026, 10:00 IST'],
        ['+919000000029', 'Wed 14 Oct 2026, 08:30 GST'],
        ['+919000000030', 'Wed 14 Oct 2026, 04:00 UTC'],
    ] as [$to, $when]) {
        $response = service($router, 'POST', 'v1/messages', notice($to, ['variables' => ['when' => $when]]), idem('var' . substr($to, -2)));
        assertSame(202, $response['status'], 'accepted ' . json_encode($response['body']));
        $row = Db::first('SELECT body, template_variables FROM messaging_messages WHERE message_uuid = :u', ['u' => $response['body']['data']['message_uuid']]);
        assertTrue(str_contains((string) $row['body'], $when), 'the body carries "' . $when . '" exactly');
        assertTrue(str_contains((string) $row['body'], 'Sharma & Co'), 'and the sending company\'s identity from its connection');
        assertTrue(!str_contains((string) $row['body'], '{{'), 'with no placeholder left');
    }
});

check('a caller cannot choose the sender identity', static function () use ($router): void {
    $response = service($router, 'POST', 'v1/messages', notice('+919000000031', ['variables' => ['sender_name' => 'Somebody Else Ltd']]), idem('snd1'));
    $row = Db::first('SELECT body FROM messaging_messages WHERE message_uuid = :u', ['u' => $response['body']['data']['message_uuid']]);
    assertTrue(!str_contains((string) $row['body'], 'Somebody Else'), 'the sender is Messaging\'s to name');
});

check('a declared variable with no value is variables_missing, not a literal {{placeholder}}', static function () use ($router, $adapter): void {
    $request = notice('+919000000032');
    unset($request['variables']['when']);
    $before = count($adapter->sent);
    $response = service($router, 'POST', 'v1/messages', $request, idem('vm1'));
    assertSame(422, $response['status'], 'refused');
    assertSame('variables_missing', $response['body']['error']['code'] ?? null, 'named');
    assertSame(['when'], $response['body']['error']['details']['missing'] ?? null, 'naming the variable');
    assertSame($before, count($adapter->sent), 'nothing sent');
});

check('the reschedule template takes old_when and when', static function () use ($router): void {
    $response = service($router, 'POST', 'v1/messages', notice('+919000000033', [
        'template' => 'appointment_reschedule', 'kind' => 'reschedule',
        'variables' => ['old_when' => 'Mon 13 Oct 2026, 15:30 IST', 'when' => 'Wed 14 Oct 2026, 10:00 IST'],
    ]), idem('rs1'));
    assertSame(202, $response['status'], 'accepted');
    $row = Db::first('SELECT body FROM messaging_messages WHERE message_uuid = :u', ['u' => $response['body']['data']['message_uuid']]);
    assertTrue(str_contains((string) $row['body'], 'from Mon 13 Oct 2026, 15:30 IST to Wed 14 Oct 2026, 10:00 IST'), 'both times are in the notice');
});

// ---------------------------------------------------------------------------
// Rate limits
// ---------------------------------------------------------------------------

check('a per-address cap stops a loop, answers 429 with Retry-After, and does not poison the key', static function () use ($router, $adapter): void {
    putenv('SERVICE_ADDRESS_DAILY_CAP=2');
    $to = '+919000000013';
    assertSame(202, service($router, 'POST', 'v1/messages', notice($to), idem('rl1'))['status'], 'first');
    assertSame(202, service($router, 'POST', 'v1/messages', notice($to), idem('rl2'))['status'], 'second');
    $before = count($adapter->sent);
    $request = notice($to);
    $third = service($router, 'POST', 'v1/messages', $request, idem('rl3'));
    assertSame(429, $third['status'], 'the third is refused');
    assertSame('rate_limited', $third['body']['error']['code'] ?? null, 'named');
    assertTrue(($third['body']['error']['details']['retry_after'] ?? 0) > 0, 'with a retry_after');
    assertSame($before, count($adapter->sent), 'nothing sent');
    fixture('rate_limited', $request, $third);

    putenv('SERVICE_ADDRESS_DAILY_CAP=50');
    assertSame(202, service($router, 'POST', 'v1/messages', $request, idem('rl3'))['status'], 'the same key succeeds once the cap allows it');
});

check('a per-company burst cap protects the sender', static function () use ($router): void {
    putenv('SERVICE_COMPANY_PER_MINUTE=1');
    $response = service($router, 'POST', 'v1/messages', notice('+919000000034'), idem('burst1'));
    assertSame(429, $response['status'], 'refused over the burst cap');
    assertSame('company', $response['body']['error']['details']['scope'] ?? null, 'and says which cap');
});

// ---------------------------------------------------------------------------
// Messaging reading Appointments: the real shape (messaging-aicountly-F6)
// ---------------------------------------------------------------------------

check('Appointments\' real booking shape is read: status, time, service, contact', static function (): void {
    $path = __DIR__ . '/fixtures/appointments/booking-show.json';
    assertTrue(is_file($path), 'the fixture captured from Appointments is present');
    $body = json_decode((string) file_get_contents($path), true);
    $booking = SourceReader::normaliseAppointmentsBooking($body['data']);

    assertSame('CONFIRMED', $booking['status'], 'status is read, not defaulted to UNKNOWN');
    assertTrue($booking['starts_at'] !== '', 'starts_at read');
    assertTrue($booking['service'] !== '', 'service name read from booking.service.name');
    assertSame('Asia/Kolkata', $booking['timezone'], 'timezone read');
});

check('a booking with no status is unavailable, never a cancellation', static function (): void {
    $booking = SourceReader::normaliseAppointmentsBooking(['booking' => ['reference' => 'AP-1']]);
    assertSame('', $booking['status'], 'no status is empty, so the caller treats it as unavailable');
});

// ---------------------------------------------------------------------------
// Secretarial notices (NOTIFY, LR-40): the same contract, its own template
// ---------------------------------------------------------------------------

/** @return array<string, mixed> a request body Secretarial's ledger sends */
function secretarialNotice(string $to, array $over = []): array
{
    return array_replace_recursive([
        'cmp_id'          => CMP,
        'channel'         => 'whatsapp',
        'to'              => $to,
        'template'        => SecretarialTemplates::NAME,
        'kind'            => 'compliance_reminder',
        'reference'       => 'ntf-3f2a9c0d1e4b5a6978c0d1e2',
        'reference_label' => 'Reminder: MGT-7 annual return',
        'not_after'       => '2026-10-16T18:30:00Z',
        'variables'       => [
            'recipient_name' => 'Asha Rao',
            'subject'        => 'Reminder: MGT-7 annual return due 29 Nov 2026',
            'summary'        => 'Compliance reminder from AICOUNTLY Secretarial.',
            'reference'      => 'ntf-3f2a9c0d1e4b5a6978c0d1e2',
        ],
    ], $over);
}

check('Secretarial is refused for a company that has not allowed it', static function () use ($router): void {
    $response = service($router, 'POST', 'v1/messages', secretarialNotice('+919000000071'), 'ntf-sec-unbound-0001', KEY_SECRETARIAL);
    assertSame(403, $response['status'], 'not bound');
    assertSame('service_company_not_bound', $response['body']['error']['code'] ?? null, 'named');
});

check('Secretarial reaches only the four contract routes', static function () use ($router): void {
    $response = service($router, 'GET', 'v1/conversations', null, null, KEY_SECRETARIAL, ['cmp_id' => CMP]);
    assertSame(403, $response['status'], 'conversations are not on its allow-list');
    assertSame('service_route_not_allowed', $response['body']['error']['code'] ?? null, 'named');
});

Domain\Settings::save($ctx, $owner, ['service_products' => ['appointments', 'billing', 'secretarial']]);
SecretarialTemplates::seed($ctx, $provider, ['whatsapp'], true);
foreach (Db::all("SELECT template_uuid FROM messaging_templates WHERE cmp_id = :cmp AND name = :n", ['cmp' => CMP, 'n' => SecretarialTemplates::NAME]) as $t) {
    TemplateService::recordProviderStatus($ctx, (string) $t['template_uuid'], 1, 'en', 'approved', 'prov-sec-' . substr((string) $t['template_uuid'], 0, 8));
}

check('Secretarial never records consent: without the director\'s consent the notice is suppressed, not sent', static function () use ($router, $adapter): void {
    $before = count($adapter->sent ?? []);
    $response = service($router, 'POST', 'v1/messages', secretarialNotice('+919000000072'), 'ntf-sec-noconsent-0001', KEY_SECRETARIAL);
    assertSame(422, $response['status'], 'refused');
    assertSame('suppressed', $response['body']['data']['delivery_state'] ?? null, 'suppressed — Secretarial records it failed');
    assertSame($before, count($adapter->sent ?? []), 'nothing went to the provider');
});

check('with consent the notice is accepted, and the answer has the fields Secretarial\'s contract fixture names', static function () use ($router): void {
    ConsentService::record(Context::forCompany(CMP), Auth::forTesting('agent-1', 'user'), 'whatsapp', '+919000000073', 'transactional', 'granted', 'agent_recorded', 'Director agreed to WhatsApp notices.');
    $response = service($router, 'POST', 'v1/messages', secretarialNotice('+919000000073'), 'ntf-sec-consented-0001', KEY_SECRETARIAL);
    assertTrue(in_array($response['status'], [200, 202], true), 'accepted (got ' . $response['status'] . ': ' . json_encode($response['body']) . ')');
    $data = $response['body']['data'] ?? [];
    assertTrue(in_array($data['delivery_state'] ?? '', ['queued', 'sent', 'delivered'], true), 'a delivery state Secretarial maps');
    $keys = array_keys(array_diff_key($data, array_flip(['outcome', 'detail', 'delivery_note', 'consent'])));
    sort($keys);
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/secretarial/messaging-service-messages.v1.json'), true);
    assertSame($fixture['data_keys'], $keys, 'the message shape Secretarial reads');

    $replay = service($router, 'POST', 'v1/messages', secretarialNotice('+919000000073'), 'ntf-sec-consented-0001', KEY_SECRETARIAL);
    assertSame(true, $replay['body']['replayed'] ?? null, 'the same key replays and sends nothing');

    $status = service($router, 'GET', 'v1/messages/' . $data['message_uuid'], null, null, KEY_SECRETARIAL, ['cmp_id' => CMP]);
    assertSame(200, $status['status'], 'Secretarial reads its own message');
    $other = service($router, 'GET', 'v1/messages/' . $data['message_uuid'], null, null, KEY_BILLING, ['cmp_id' => CMP]);
    assertSame(404, $other['status'], 'another product cannot');
});

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
