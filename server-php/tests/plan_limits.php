<?php

declare(strict_types=1);

/**
 * Plan limits (CONTRACTS §3.5/§3.6): the company's plan for Messaging, read from Manage's companyinfo,
 * and the two limits Messaging enforces from it.
 *
 *   messaging.max_channels             POST v1/channels, and switching a connection back on
 *   messaging.max_messages_per_month   POST v1/conversations/{c}/send (outbound, queued this month)
 *
 * Pins: both plan shapes (map and Engage's [{code,value}] list), Manage's real companyinfo envelope,
 * "no plan / absent code / unreadable value = unlimited", the 402 PLAN_LIMIT_REACHED envelope with
 * {code, limit, used}, nothing written on a refusal, inbound and last month's messages never counted,
 * a message already queued never refused, a product's service key never limited.
 *
 *   php server-php/tests/plan_limits.php   (needs the migrated test database; run.sh runs it)
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

const PL_CMP = 9171;

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : "\n        " . $detail) . "\n";
    $ok ? $passed++ : $failed++;
};

/** @return array{status:int, body:array<string, mixed>} */
$call = static function (Router $router, string $method, string $path, ?array $body = null): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = ['cmp_id' => PL_CMP, 'bo_id' => 0];
    $_POST = [];
    Http::setBodyForTesting($body);
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'plan-key-' . bin2hex(random_bytes(8));
    try {
        ob_start();
        $router->dispatch($method, $path);
        ob_end_clean();

        return ['status' => 0, 'body' => []];
    } catch (ResponseSent $e) {
        ob_end_clean();

        return ['status' => $e->status, 'body' => $e->payload];
    } catch (\Throwable $e) {
        ob_end_clean();

        return ['status' => 500, 'body' => ['error' => ['message' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]]];
    } finally {
        Http::setBodyForTesting(null);
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    }
};

echo "Plan limits\n" . str_repeat('=', 60) . "\n";

// ---------------------------------------------------------------------------
// The plan, as Manage states it
// ---------------------------------------------------------------------------

$check('no plan in companyinfo: none (an older Manage = no limits)', PlanEntitlements::fromCompanyInfo(['data' => ['comp_id' => 5]]) === null);
// Manage's real companyinfo answer (asked naming Messaging): the six-key plan inside `data`.
$info = json_decode('{"status":1,"data":{"comp_id":501,"app":"messaging","app_access_status":"ACCESS_GRANTED","app_access_source":"workspace","app_role_key":"member","plan":{"code":"messaging-basic","label":"Basic","is_default":true,"status":"active","ends_at":null,"entitlements":{"messaging.max_channels":1,"messaging.max_messages_per_month":500}},"lifecycle":"active","restricted_until":null}}', true);
$plan = PlanEntitlements::fromCompanyInfo($info);
$check("Manage's companyinfo plan is read (label, is_default, both limits)", ($plan['label'] ?? null) === 'Basic' && ($plan['is_default'] ?? null) === true
    && PlanEntitlements::limit($plan, PlanEntitlements::MAX_CHANNELS) === 1 && PlanEntitlements::limit($plan, PlanEntitlements::MAX_MESSAGES_PER_MONTH) === 500, json_encode($plan));
$check('an unwrapped company row reads too', PlanEntitlements::limit(PlanEntitlements::fromCompanyInfo($info['data']), PlanEntitlements::MAX_CHANNELS) === 1);
$list = PlanEntitlements::normalize(['code' => 'messaging-pro', 'entitlements' => [['code' => 'messaging.max_channels', 'value' => '5'], ['code' => 'feature.messaging.rcs', 'value' => false]]]);
$check("Engage's list shape [{code,value}] reads too", PlanEntitlements::limit($list, PlanEntitlements::MAX_CHANNELS) === 5);
$check("Manage's built-in Basic ({} entitlements): no limit", PlanEntitlements::limit(PlanEntitlements::normalize(['code' => 'basic', 'is_default' => true, 'entitlements' => []]), PlanEntitlements::MAX_CHANNELS) === null);
foreach ([null, -1, 2.5, 'ten', '', true, [3]] as $v) {
    $check('no limit for the value ' . json_encode($v), PlanEntitlements::limit(PlanEntitlements::normalize(['entitlements' => [PlanEntitlements::MAX_CHANNELS => $v]]), PlanEntitlements::MAX_CHANNELS) === null);
}
$check('0 is a limit (nothing allowed), not "unlimited"', PlanEntitlements::limit(PlanEntitlements::normalize(['entitlements' => [PlanEntitlements::MAX_CHANNELS => 0]]), PlanEntitlements::MAX_CHANNELS) === 0);
$check('a feature switched off reads off', PlanEntitlements::allows($list, 'feature.messaging.rcs') === false);
$check('an absent feature, or no plan, is on', PlanEntitlements::allows($list, 'feature.messaging.other') && PlanEntitlements::allows(null, 'feature.messaging.rcs'));
$check('the sentence names the limit and who can change it', PlanEntitlements::message(PlanEntitlements::MAX_CHANNELS, 2) === 'Your plan allows 2 channel connections — ask the account owner to upgrade in Manage.');

// ---------------------------------------------------------------------------
// Over HTTP, through the real router and controllers
// ---------------------------------------------------------------------------

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the test database: {$e->getMessage()}\n");
    exit(1);
}

Db::run("SET messaging.audit_purge = 'on'");
foreach (['messaging_dispatch_jobs', 'messaging_approvals', 'messaging_messages', 'messaging_conversations',
    'messaging_channel_capabilities', 'messaging_channel_connections', 'messaging_audit_events', 'messaging_idempotency_keys'] as $table) {
    Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => PL_CMP]);
}

Clock::freeze('2026-06-15T04:00:00Z');
$auth = Auth::forTesting('user-plan', 'user', 'messaging');
Auth::adopt($auth);
Permissions::forget();
ChannelRegistry::overrideForTesting('test_provider', new RecordingAdapter());
putenv('MESSAGING_PLAN_TEST_TOKEN=plan-test-token');
$router = new Router();
Routes::register($router);

/** The person is the owner (every permission); the plan is what Manage's companyinfo said. */
$withPlan = static function (?array $entitlements) use ($auth): void {
    Context::resetForTesting();
    Context::trustForTesting(PL_CMP, $auth, true, [], 0, $entitlements === null ? null : ['code' => 'messaging-test', 'label' => 'Test', 'entitlements' => $entitlements]);
};

$connection = static function (string $uuid, string $sender, bool $active): void {
    Db::insert('messaging_channel_connections', [
        'connection_uuid' => $uuid, 'cmp_id' => PL_CMP, 'bo_id' => 0, 'channel' => 'whatsapp', 'provider' => 'test_provider',
        'display_name' => 'Plan line', 'sender_address' => $sender, 'provider_account_ref' => 'acct-plan',
        'credential_ref' => 'MESSAGING_PLAN_TEST_TOKEN', 'status' => 'connected', 'is_active' => $active,
        'created_at' => Clock::nowSql(), 'updated_at' => Clock::nowSql(),
    ], 'connection_uuid');
};
$active = '71000000-0000-4000-8000-000000000001';
$inactive = '71000000-0000-4000-8000-000000000002';
$connection($active, '+919800000171', true);
$connection($inactive, '+919800000172', false);
$newChannel = static fn (string $sender): array => ['channel' => 'whatsapp', 'provider' => 'test_provider', 'display_name' => 'Another line',
    'sender_address' => $sender, 'credential_ref' => 'MESSAGING_PLAN_TEST_TOKEN'];

$withPlan([PlanEntitlements::MAX_CHANNELS => 1]);
$r = $call($router, 'POST', 'v1/channels', $newChannel('+919800000173'));
$check('a channel beyond messaging.max_channels is 402 PLAN_LIMIT_REACHED', $r['status'] === 402 && ($r['body']['error']['code'] ?? '') === 'PLAN_LIMIT_REACHED', json_encode($r));
$check('…with {code, limit, used} (the switched-off connection is not counted)', ($r['body']['error']['details'] ?? null) === ['code' => 'messaging.max_channels', 'limit' => 1, 'used' => 1], json_encode($r['body']['error']['details'] ?? null));
$check('…the usual envelope keeps its top-level message', str_starts_with((string) ($r['body']['message'] ?? ''), 'Your plan allows 1 channel connection'), (string) ($r['body']['message'] ?? ''));
$check('…and nothing was created', (int) Db::scalar("SELECT COUNT(*) FROM messaging_channel_connections WHERE cmp_id = :c AND sender_address = '+919800000173'", ['c' => PL_CMP]) === 0);

$r = $call($router, 'PUT', 'v1/channels/' . $inactive, ['is_active' => true]);
$check('switching a connection back on at the limit is 402 too', $r['status'] === 402 && ($r['body']['error']['details']['code'] ?? '') === 'messaging.max_channels', json_encode($r));
$check('…and it stays off', (int) Db::scalar('SELECT COUNT(*) FROM messaging_channel_connections WHERE connection_uuid = :u AND is_active = FALSE', ['u' => $inactive]) === 1);
$r = $call($router, 'PUT', 'v1/channels/' . $inactive, ['display_name' => 'Renamed while off']);
$check('editing a switched-off connection is never limited', $r['status'] === 200, json_encode($r));

$withPlan([PlanEntitlements::MAX_CHANNELS => 2]);
$r = $call($router, 'POST', 'v1/channels', $newChannel('+919800000174'));
$check('under the limit, a channel is created (201)', $r['status'] === 201, json_encode($r));
$r = $call($router, 'PUT', 'v1/channels/' . $active, ['is_active' => false]);
$check('switching one off is never limited (the company is now over nothing)', $r['status'] === 200, json_encode($r));

$withPlan(null);
$r = $call($router, 'POST', 'v1/channels', $newChannel('+919800000175'));
$check('no plan (an older Manage): no limit — created', $r['status'] === 201, json_encode($r));
$withPlan(['feature.messaging.something' => true]);
$r = $call($router, 'POST', 'v1/channels', $newChannel('+919800000176'));
$check('a plan without the code: no limit — created', $r['status'] === 201, json_encode($r));

// --- messages a month -------------------------------------------------------

$conversation = Uuid::v4();
Db::insert('messaging_conversations', [
    'conversation_uuid' => $conversation, 'cmp_id' => PL_CMP, 'bo_id' => 0, 'connection_uuid' => $active, 'channel' => 'whatsapp',
    'customer_address' => '+919812300171', 'status' => 'open', 'first_inbound_at' => Clock::nowSql(), 'last_inbound_at' => Clock::nowSql(),
    'created_at' => Clock::nowSql(), 'updated_at' => Clock::nowSql(),
], 'conversation_uuid');
$message = static function (string $direction, string $status, ?string $queuedAt, string $createdAt = '2026-06-10T10:00:00Z') use ($conversation, $active): string {
    $uuid = Uuid::v4();
    Db::insert('messaging_messages', [
        'message_uuid' => $uuid, 'cmp_id' => PL_CMP, 'bo_id' => 0, 'conversation_uuid' => $conversation, 'connection_uuid' => $active,
        'channel' => 'whatsapp', 'direction' => $direction, 'status' => $status, 'body' => 'Plan test ' . $uuid,
        'origin' => $direction === 'inbound' ? 'CUSTOMER' : 'AGENT', 'queued_at' => $queuedAt, 'created_at' => $createdAt,
    ], 'message_uuid');

    return $uuid;
};
// This month (Asia/Kolkata): two sent, one from a journey. 1 June 00:10 IST is still June.
$message('outbound', 'delivered', '2026-06-02T09:00:00Z');
$alreadyQueued = $message('outbound', 'queued', '2026-05-31T18:40:00Z', '2026-05-31T18:30:00Z');
// Not counted: last month (31 May 23:00 IST), inbound, and drafts never queued.
$message('outbound', 'delivered', '2026-05-31T17:30:00Z', '2026-05-31T17:00:00Z');
$message('inbound', 'delivered', null);
$message('inbound', 'delivered', null);
$draft = $message('outbound', 'draft', null);

$check('this month counts outbound messages queued since 1st 00:00 IST only', PlanEntitlements::messagesQueuedThisMonth(PL_CMP) === 2, (string) PlanEntitlements::messagesQueuedThisMonth(PL_CMP));

$withPlan([PlanEntitlements::MAX_MESSAGES_PER_MONTH => 2]);
$r = $call($router, 'POST', 'v1/conversations/' . $conversation . '/send', ['message_uuid' => $draft]);
$check('a send beyond messaging.max_messages_per_month is 402 PLAN_LIMIT_REACHED', $r['status'] === 402
    && ($r['body']['error']['details'] ?? null) === ['code' => 'messaging.max_messages_per_month', 'limit' => 2, 'used' => 2], json_encode($r));
$row = Db::first('SELECT status, approved_at, queued_at FROM messaging_messages WHERE message_uuid = :u', ['u' => $draft]);
$check('…and the draft is untouched: not approved, not queued', ($row['status'] ?? '') === 'draft' && $row['approved_at'] === null && $row['queued_at'] === null, json_encode($row));
$r = $call($router, 'POST', 'v1/conversations/' . $conversation . '/send', ['message_uuid' => $alreadyQueued]);
$check('a message already on the queue is never refused by the plan', $r['status'] !== 402, json_encode($r));
$r = $call($router, 'POST', 'v1/conversations/' . $conversation . '/drafts', ['body' => 'Saving a draft is never limited.']);
$check('saving a draft at the limit is never limited (201)', $r['status'] === 201, json_encode($r));
$r = $call($router, 'GET', 'v1/conversations/' . $conversation . '/messages');
$check('reading at the limit is never limited (200)', $r['status'] === 200, json_encode($r['body']['error'] ?? null));

$withPlan([PlanEntitlements::MAX_MESSAGES_PER_MONTH => 3]);
$r = $call($router, 'POST', 'v1/conversations/' . $conversation . '/send', ['message_uuid' => $draft]);
$check('under the limit the send goes on to the dispatch gates (no 402)', $r['status'] !== 402 && $r['status'] !== 500, $r['status'] . ' ' . json_encode($r['body']['error'] ?? null));

$service = Auth::forTesting('appointments', 'service', 'appointments');
$refused = false;
try {
    PlanEntitlements::assertWithin(Context::forCompany(PL_CMP), $service, PlanEntitlements::MAX_MESSAGES_PER_MONTH, static fn (): int => 999);
} catch (ResponseSent) {
    $refused = true;
}
$check("a product's service key is never limited (it carries no plan)", !$refused && Context::forCompany(PL_CMP)->plan($service) === null);

Context::resetForTesting();
Clock::freeze(null);
Db::run("SET messaging.audit_purge = 'on'");
foreach (['messaging_dispatch_jobs', 'messaging_approvals', 'messaging_messages', 'messaging_conversations',
    'messaging_channel_capabilities', 'messaging_channel_connections', 'messaging_audit_events', 'messaging_idempotency_keys'] as $table) {
    Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => PL_CMP]);
}

echo "\n" . str_repeat('-', 60) . "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
