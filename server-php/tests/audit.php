<?php

declare(strict_types=1);

/**
 * The audit trail (migration 009, src/Audit.php, GET v1/audit*).
 *
 * Writer context and diff, immutability at the database, the rows the core mutations leave behind
 * (settings, access, channels, consent, suppression, approval), and the read API: filters, company
 * scoping, permission gating and the CSV export.
 *
 *   server-php/tests/run.sh   (runs this after integration.php)
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support/RecordingAdapter.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Tests\RecordingAdapter;

const CMP = 9301;
const OTHER_CMP = 9302;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        $failed++;
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

/** @return array{status:int, body:array<string, mixed>} */
function call(Router $router, string $method, string $path, array $query = [], ?array $body = null, int $cmp = CMP): array
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $query + ['cmp_id' => $cmp, 'bo_id' => 0];
    Http::setBodyForTesting($body);
    if ($method !== 'GET') {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'test-key-' . bin2hex(random_bytes(8));
    }
    try {
        ob_start();
        $matched = $router->dispatch($method, $path);
        ob_end_clean();

        return ['status' => $matched ? 0 : 404, 'body' => []];
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
}

function purge(): void
{
    Db::run("SET messaging.audit_purge = 'on'");
    foreach ([CMP, OTHER_CMP] as $cmp) {
        foreach (['messaging_audit_events', 'messaging_permission_assignments', 'messaging_permission_profiles',
            'messaging_suppressions', 'messaging_consent_events', 'messaging_consent_records',
            'messaging_channel_capabilities', 'messaging_channel_connections', 'messaging_settings'] as $table) {
            Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => $cmp]);
        }
    }
    Db::run("SET messaging.audit_purge = 'off'");
}

/** @return list<array<string, mixed>> */
function rows(string $action, int $cmp = CMP): array
{
    return Db::all('SELECT * FROM messaging_audit_events WHERE cmp_id = :c AND action = :a ORDER BY audit_id', ['c' => $cmp, 'a' => $action]);
}

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the test database: {$e->getMessage()}\n");
    exit(1);
}

$owner = Auth::forTesting('user-audit-owner', 'user', 'messaging', ['name' => 'Olive Owner']);
Auth::adopt($owner);
$ctx = Context::forCompany(CMP);
Context::trustForTesting(CMP, $owner, true);
Context::trustForTesting(OTHER_CMP, $owner, true);
Clock::freeze('2026-06-01T04:00:00Z');
purge();
Domain\Settings::forget();
Permissions::forget();
ChannelRegistry::overrideForTesting('test_provider', new RecordingAdapter());

$router = new Router();
Routes::register($router);

echo "audit trail\n";

// ---------------------------------------------------------------------------
// Writer
// ---------------------------------------------------------------------------

check('a row carries actor, request context, severity and a summary', static function () use ($ctx, $owner): void {
    $_SERVER['HTTP_USER_AGENT'] = 'AuditTest/1.0';
    $_SERVER['HTTP_X_REQUEST_ID'] = 'req-audit-test-0001';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    Audit::resetRequestForTesting();

    Audit::record($ctx, $owner, 'template.saved', 'template', 'tpl-1', null, ['version' => 2, 'name' => 'Rent reminder']);

    $row = rows('template.saved')[0];
    assertSame('user-audit-owner', $row['actor_uuid'], 'actor');
    assertSame('Olive Owner', $row['actor_label'], 'cached actor label');
    assertSame('Rent reminder', $row['entity_label'], 'the entity label is the template name');
    assertSame('req-audit-test-0001', $row['request_id'], 'request id');
    assertSame('AuditTest/1.0', $row['user_agent'], 'user agent');
    assertSame('203.0.113.9', $row['ip_address'], 'ip');
    assertSame('info', $row['severity'], 'severity from the action');
    assertTrue(str_contains((string) $row['summary'], 'Rent reminder'), 'a readable summary');
    unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['REMOTE_ADDR']);
    Audit::resetRequestForTesting();
});

check('diff skips unchanged and secret fields, hides content, masks contact details', static function (): void {
    $changes = Audit::diff(
        ['tone' => 'formal', 'same' => 1, 'api_key' => 'k1', 'webhook_secret_ref' => 'a', 'body' => 'Hello Asha', 'to' => '+919812345678'],
        ['tone' => 'friendly', 'same' => 1, 'api_key' => 'k2', 'webhook_secret_ref' => 'b', 'body' => 'Hi Asha', 'to' => '+919800000000'],
        ['tone' => 'Reply tone'],
    );
    $byField = array_column($changes, null, 'field');
    assertSame(['tone', 'body', 'to'], array_keys($byField), 'only changed, non-secret fields');
    assertSame('Reply tone', $byField['tone']['label'], 'labels');
    assertSame(['formal', 'friendly'], [$byField['tone']['old'], $byField['tone']['new']], 'old and new');
    assertSame('[content not logged]', $byField['body']['new'], 'message text is never stored');
    assertSame('***5678', $byField['to']['old'], 'a recipient is masked');
});

check('a stored state keeps no message text, full address or credential', static function () use ($ctx, $owner): void {
    Audit::record($ctx, $owner, 'message.sent', 'message', 'm-9', null, [
        'body' => 'Dear Asha, your balance is 4,200', 'address' => 'asha@example.com', 'token' => 'sk-live-zzz', 'channel' => 'whatsapp',
    ]);
    $row = Db::first("SELECT after_state::text AS s FROM messaging_audit_events WHERE entity_id = 'm-9'");
    $stored = (string) $row['s'];
    foreach (['Dear Asha', 'asha@example.com', 'sk-live-zzz'] as $leak) {
        assertTrue(!str_contains($stored, $leak), "{$leak} must not reach the trail");
    }
    assertTrue(str_contains($stored, 'whatsapp') && str_contains($stored, 'a***@example.com'), 'labels and a masked address remain');
});

check('material() writes in the caller\'s transaction and fails closed', static function () use ($ctx, $owner): void {
    // A rolled-back transaction takes its audit row with it.
    try {
        Db::transaction(static function () use ($ctx, $owner): void {
            Audit::material($ctx, $owner, 'settings.updated', 'settings', 'rollback-me');
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException) {
    }
    assertSame(0, (int) Db::scalar("SELECT COUNT(*) FROM messaging_audit_events WHERE entity_id = 'rollback-me'"), 'rolled back with the change');

    // A row the table refuses makes material() throw; record() swallows it.
    $bad = Context::forCompany(CMP);
    $threw = false;
    try {
        Audit::material($bad, $owner, str_repeat('x', 80), 'settings', 'too-long');
    } catch (\Throwable) {
        $threw = true;
    }
    assertTrue($threw, 'material() throws when the row cannot be written');
    Audit::record($bad, $owner, str_repeat('x', 80), 'settings', 'too-long-best-effort');
});

check('a best-effort failure inside a transaction does not poison it', static function () use ($ctx, $owner): void {
    Db::transaction(static function () use ($ctx, $owner): void {
        Audit::record($ctx, $owner, str_repeat('y', 80), 'settings', 'bad');
        Audit::record($ctx, $owner, 'conversation.updated', 'conversation', 'still-fine');
    });
    assertSame(1, (int) Db::scalar("SELECT COUNT(*) FROM messaging_audit_events WHERE entity_id = 'still-fine'"), 'later work in the same transaction survives');
});

// ---------------------------------------------------------------------------
// Immutability
// ---------------------------------------------------------------------------

check('UPDATE and DELETE on the trail are refused', static function (): void {
    Db::run("SET messaging.audit_purge = 'off'");
    foreach (['UPDATE messaging_audit_events SET summary = \'edited\' WHERE cmp_id = ' . CMP,
        'DELETE FROM messaging_audit_events WHERE cmp_id = ' . CMP] as $sql) {
        $refused = false;
        try {
            Db::run($sql);
        } catch (\PDOException $e) {
            $refused = str_contains($e->getMessage(), 'append-only');
        }
        assertTrue($refused, 'refused: ' . $sql);
    }
    assertTrue((int) Db::scalar('SELECT COUNT(*) FROM messaging_audit_events WHERE cmp_id = ' . CMP) > 0, 'rows are still there');
});

// ---------------------------------------------------------------------------
// Coverage
// ---------------------------------------------------------------------------

check('a settings change leaves a material row with old -> new field changes', static function () use ($router): void {
    $first = call($router, 'PUT', 'v1/settings', [], ['timezone' => 'UTC']);
    $second = call($router, 'PUT', 'v1/settings', [], ['timezone' => 'Asia/Dubai']);
    assertTrue($first['status'] < 300 && $second['status'] < 300, 'settings saved: ' . json_encode($second['body']));
    $row = array_values(array_filter(rows('settings.updated'), static fn (array $r): bool => str_contains((string) $r['meta_json'], 'timezone')));
    assertTrue($row !== [], 'a row naming the changed setting');
    $meta = Db::jsonColumn(end($row)['meta_json']);
    assertSame('material', end($row)['severity'], 'severity');
    $fields = array_column($meta['changes'], null, 'field');
    assertSame(['UTC', 'Asia/Dubai'], [$fields['timezone']['old'] ?? null, $fields['timezone']['new'] ?? null], 'old -> new is recorded');
    assertTrue(!isset($fields['updated_at']), 'bookkeeping columns are not changes');
});

check('access changes write security rows', static function () use ($router): void {
    $saved = call($router, 'POST', 'v1/access/profiles', [], ['name' => 'Front desk', 'permissions' => ['messaging.conversations.view']]);
    assertSame(201, $saved['status'], 'profile created: ' . json_encode($saved['body']));
    $profileId = (int) $saved['body']['data']['profile_id'];
    assertSame(201, call($router, 'POST', 'v1/access/assignments', [], ['user_uuid' => 'user-audit-agent', 'profile_id' => $profileId])['status'], 'assigned');

    $created = rows('access.profile_created')[0];
    assertSame('security', $created['severity'], 'severity');
    assertSame('Front desk', $created['entity_label'], 'label is the profile name');
    $assigned = rows('access.profile_assigned')[0];
    assertSame('user-audit-agent', $assigned['entity_id'], 'who received it');
    assertTrue(str_contains((string) $assigned['summary'], 'Front desk'), 'summary names the profile');

    call($router, 'POST', 'v1/access/profiles', [], ['profile_id' => $profileId, 'name' => 'Front desk', 'permissions' => ['messaging.conversations.view', 'messaging.conversations.note']]);
    $updated = rows('access.profile_updated')[0];
    $change = Db::jsonColumn($updated['meta_json'])['changes'][0] ?? [];
    assertSame('permissions', $change['field'] ?? null, 'permission change is itemised');

    call($router, 'DELETE', 'v1/access/assignments', ['user_uuid' => 'user-audit-agent', 'profile_id' => $profileId]);
    assertSame(1, count(rows('access.profile_revoked')), 'revocation is logged');
});

check('a suppression and a consent withdrawal leave masked, material rows', static function () use ($router): void {
    $suppressed = call($router, 'POST', 'v1/suppressions', [], ['channel' => 'sms', 'address' => '+919812345678', 'reason' => 'manual']);
    assertSame(201, $suppressed['status'], 'suppressed: ' . json_encode($suppressed['body']));
    $row = rows('address.suppressed')[0];
    assertSame('material', $row['severity'], 'severity');
    assertTrue(!str_contains(json_encode($row), '919812345678'), 'the full number is nowhere in the row');
    assertTrue(str_contains((string) $row['entity_label'], '5678'), 'a masked tail identifies it');

    $uuid = (string) $suppressed['body']['data']['suppression_uuid'];
    $released = call($router, 'POST', 'v1/suppressions/' . $uuid . '/release', [], ['reason' => 'Customer asked by phone']);
    assertSame(200, $released['status'], 'released: ' . json_encode($released['body']));
    $rel = rows('suppression.released')[0];
    assertSame('security', $rel['severity'], 'overriding a suppression is a security event');
    assertSame('Customer asked by phone', $rel['reason'], 'with the stated reason');
});

check('a channel connection is created with a fail-closed row and no credential', static function () use ($router): void {
    putenv('MESSAGING_AUDIT_TEST_TOKEN=tok-should-never-appear');
    $created = call($router, 'POST', 'v1/channels', [], [
        'channel' => 'whatsapp', 'provider' => 'test_provider', 'display_name' => 'Audit line',
        'sender_address' => '+14155550101', 'credential_ref' => 'MESSAGING_AUDIT_TEST_TOKEN',
    ]);
    assertTrue($created['status'] === 201, 'created: ' . json_encode($created['body']));
    $row = rows('channel.created')[0];
    assertSame('security', $row['severity'], 'severity');
    assertSame('Audit line', $row['entity_label'], 'label');
    assertTrue(!str_contains(json_encode($row), 'tok-should-never-appear'), 'no credential value');
});

// ---------------------------------------------------------------------------
// Read API
// ---------------------------------------------------------------------------

check('GET v1/audit filters by action, entity, actor, severity, q and date, with a total', static function () use ($router): void {
    $all = call($router, 'GET', 'v1/audit');
    assertSame(200, $all['status'], 'list');
    $total = $all['body']['meta']['total'];
    assertTrue($total >= 6, 'rows exist');

    $access = call($router, 'GET', 'v1/audit', ['action' => 'access.*']);
    assertTrue($access['body']['meta']['total'] >= 3, 'prefix filter');
    foreach ($access['body']['data'] as $row) {
        assertTrue(str_starts_with($row['action'], 'access.'), 'only access rows');
    }
    assertSame(1, call($router, 'GET', 'v1/audit', ['entity_type' => 'channel_connection'])['body']['meta']['total'], 'entity_type');
    assertSame(1, call($router, 'GET', 'v1/audit', ['entity_label' => 'audit line'])['body']['meta']['total'], 'entity_label search');
    assertSame($total, call($router, 'GET', 'v1/audit', ['actor_uuid' => 'user-audit-owner'])['body']['meta']['total'] + (int) Db::scalar("SELECT COUNT(*) FROM messaging_audit_events WHERE cmp_id = " . CMP . " AND actor_uuid <> 'user-audit-owner'"), 'actor filter partitions the trail');
    assertTrue(call($router, 'GET', 'v1/audit', ['severity' => 'security'])['body']['meta']['total'] >= 4, 'severity');
    assertTrue(call($router, 'GET', 'v1/audit', ['q' => 'Front desk'])['body']['meta']['total'] >= 2, 'q matches summary and label');
    assertSame($total, call($router, 'GET', 'v1/audit', ['from' => '2026-06-01', 'to' => '2026-06-01'])['body']['meta']['total'], 'a bare date range is the whole day');
    assertSame(0, call($router, 'GET', 'v1/audit', ['to' => '2026-05-31'])['body']['meta']['total'], 'before the first row');
    assertSame(2, count(call($router, 'GET', 'v1/audit', ['limit' => 2])['body']['data']), 'paging');
});

check('GET v1/audit/entity/{type}/{id} returns the history of one record', static function () use ($router): void {
    $profile = rows('access.profile_created')[0];
    $history = call($router, 'GET', 'v1/audit/entity/permission_profile/' . $profile['entity_id']);
    assertSame(200, $history['status'], 'history');
    assertSame(2, $history['body']['meta']['total'], 'created and updated');
    assertTrue(is_array($history['body']['data'][0]['meta_json']), 'the field changes come decoded');
});

check('the trail is scoped to the caller\'s company', static function () use ($router): void {
    Audit::record(Context::forCompany(OTHER_CMP), Auth::forTesting('someone-else'), 'settings.updated', 'settings', 'other-company');
    $seen = array_column(call($router, 'GET', 'v1/audit', ['limit' => 200])['body']['data'], 'entity_id');
    assertTrue(!in_array('other-company', $seen, true), 'another company\'s row is not served');
    assertSame(0, call($router, 'GET', 'v1/audit/entity/settings/other-company')['body']['meta']['total'], 'nor through the entity history');
});

check('CSV export: formula-safe cells, cap flag, audited', static function () use ($router, $ctx, $owner): void {
    Audit::record($ctx, $owner, 'journey.saved', 'journey', 'j-1', null, ['name' => '=HYPERLINK("http://x","y")']);
    $export = call($router, 'GET', 'v1/audit/export', ['entity_type' => 'journey']);
    assertSame(200, $export['status'], 'export');
    $csv = (string) $export['body']['csv'];
    assertTrue(str_starts_with($csv, '"audit_id","when"'), 'a header row');
    assertTrue(str_contains($csv, "\"'=HYPERLINK"), 'a leading = is neutralised');
    assertTrue(!preg_match('/(^|,)"=/m', $csv), 'no cell starts with =');
    assertSame(false, $export['body']['truncated'], 'not truncated');
    assertSame(1, count(rows('audit.exported')), 'the export is itself audited');

    // Over the cap: the file says so.
    Db::run("SET messaging.audit_purge = 'on'");
    Db::run("INSERT INTO messaging_audit_events (cmp_id, actor_uuid, actor_kind, action, entity_type, entity_id, summary)
             SELECT " . OTHER_CMP . ", 'bulk', 'user', 'bulk.row', 'bulk', g::text, 'bulk ' || g FROM generate_series(1, " . (Audit::EXPORT_CAP + 5) . ") g");
    Db::run("SET messaging.audit_purge = 'off'");
    Context::trustForTesting(OTHER_CMP, $owner, true);
    $big = call($router, 'GET', 'v1/audit/export', [], null, OTHER_CMP);
    assertSame(true, $big['body']['truncated'], 'truncated');
    assertSame(Audit::EXPORT_CAP, $big['body']['rows'], 'at the cap');
    assertTrue(str_contains((string) $big['body']['csv'], 'PARTIAL:'), 'with a PARTIAL line');
});

check('reading needs messaging.audit.view and exporting also messaging.export', static function () use ($router): void {
    $agent = Auth::forTesting('user-audit-nobody');
    Auth::adopt($agent);
    Context::trustForTesting(CMP, $agent);
    Permissions::forget();
    try {
        assertSame(403, call($router, 'GET', 'v1/audit')['status'], 'no grant, no list');
        assertSame(403, call($router, 'GET', 'v1/audit/entity/settings/x')['status'], 'no grant, no history');
        assertSame(403, call($router, 'GET', 'v1/audit/export')['status'], 'no grant, no export');

        $profile = (int) Db::insert('messaging_permission_profiles', [
            'cmp_id' => CMP, 'name' => 'Auditor', 'permissions' => ['messaging.audit.view'],
            'created_at' => Clock::nowSql(), 'created_by' => 'x', 'updated_at' => Clock::nowSql(), 'updated_by' => 'x',
        ], 'profile_id');
        Db::run('INSERT INTO messaging_permission_assignments (cmp_id, user_uuid, profile_id, created_at, created_by) VALUES (:c, :u, :p, NOW(), :a)',
            ['c' => CMP, 'u' => 'user-audit-nobody', 'p' => $profile, 'a' => 'x']);
        Permissions::forget();
        assertSame(200, call($router, 'GET', 'v1/audit')['status'], 'view alone reads');
        assertSame(403, call($router, 'GET', 'v1/audit/export')['status'], 'but cannot export');
    } finally {
        Auth::adopt(Auth::forTesting('user-audit-owner', 'user', 'messaging', ['name' => 'Olive Owner']));
        Permissions::forget();
    }
});

Clock::freeze(null);
purge();
Db::run("SET messaging.audit_purge = 'on'");
Db::run('DELETE FROM messaging_audit_events WHERE cmp_id IN (' . CMP . ', ' . OTHER_CMP . ')');

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
