<?php

declare(strict_types=1);

/**
 * AICOUNTLY company lifecycle in Messaging (lib/company-lifecycle; src/Lifecycle/).
 *
 *   server-php/tests/run.sh
 *
 * Against the migrated test database. Manage is never reached: events are signed here with a test
 * secret, acks go to a recording fake, Drive deletions to a recording deleter.
 *
 * What these prove:
 *   - the reviewed manifest has no problems for the company and the user scope on the migrated
 *     schema (strict mode: a new table with cmp_id / user_uuid fails this until it is listed);
 *   - every allow-list entry matches a real route;
 *   - the receiver route needs no session, takes a signed event (202) and refuses a bad signature;
 *   - a restricted company: writes answer 423 COMPANY_RESTRICTED (after the company check), reads and
 *     the allow-list pass, background sends wait; companyinfo's lifecycle is written through;
 *   - a purge: rows archived and deleted, the audit trail retained (CERT-In), attachment files and
 *     Drive documents removed after the commit, a store failure retried and recorded, the signed ack;
 *   - an account purge removes only the person's own UI state;
 *   - kit 1.1: the manifest has no warnings (every retained table has a period), schema-1.1 is
 *     migrated, a product's service-key write into a read-only company is 423 (checkMachine) while
 *     provider webhooks (MACHINE_ALLOW) pass, every MACHINE_ALLOW entry matches a real route, and
 *     --expire-retained deletes a purged company's audit rows once 180 days old (and only those);
 *   - the append-only audit trail (migration 012): UPDATE / DELETE refused without
 *     aicountly.lifecycle_purge; with it (SET LOCAL) a DELETE and the scrub's UPDATE pass, nothing else.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/../lib/company-lifecycle/autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Lifecycle\CompanyLifecycle;
use Aicountly\Api\Lifecycle\ObjectSweep;
use Aicountly\Lifecycle\Kit;
use Aicountly\Lifecycle\PurgeEngine;
use Aicountly\Lifecycle\PurgeManifest;
use Aicountly\Lifecycle\ReadOnlyGuard;
use Aicountly\Lifecycle\Signature;

const CMP = 9301;
const OTHER = 9302;
const PERSON = '7300001';
const SECRET = 'test-lifecycle-secret-0123456789abcdef0123456789';

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
        echo "  FAIL  {$name}\n        {$e->getMessage()} @ " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        $failed++;
        $failures[] = $name;
    }
}

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException($what . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

function truthy(bool $cond, string $what): void
{
    if (!$cond) {
        throw new \RuntimeException($what);
    }
}

/** @return array{status:int, body:array<string,mixed>} */
function call(Router $router, string $method, string $path, array $query = [], ?array $body = null, array $server = []): array
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/api/' . $path;
    foreach ($server as $k => $v) {
        $_SERVER[$k] = $v;
    }
    $_GET = $query + ['cmp_id' => CMP, 'bo_id' => 0];
    Http::setBodyForTesting($body);
    if ($method !== 'GET') {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'lc-' . bin2hex(random_bytes(6));
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

        return ['status' => 500, 'body' => ['error' => ['message' => $e->getMessage()]]];
    } finally {
        Http::setBodyForTesting(null);
        foreach (array_keys($server) as $k) {
            unset($_SERVER[$k]);
        }
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    }
}

/** A signed event delivered to the receiver through the router, as Manage sends it. */
function deliver(Router $router, array $event): array
{
    $raw = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
    $ts = time();
    CompanyLifecycle::setRawBodyForTesting($raw);

    return call($router, 'POST', 'internal/manage/company-lifecycle', [], null, [
        'HTTP_X_MANAGE_TIMESTAMP' => (string) $ts,
        'HTTP_X_MANAGE_SIGNATURE' => Signature::sign(SECRET, $ts, $raw),
        'HTTP_X_MANAGE_EVENT'     => (string) $event['event'],
    ]);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function event(string $name, array $extra = []): array
{
    return $extra + ['event_id' => uuid4(), 'event' => $name, 'app_key' => 'messaging', 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z')];
}

try {
    $pdo = Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the test database: {$e->getMessage()}\n");
    exit(1);
}

// The kit, as the API builds it, with the test secret, a fake Manage and recording deleters.
$acks = [];
$driveDeleted = [];
$driveFailures = 0;
putenv('LIFECYCLE_WEBHOOK_SECRET=' . SECRET);
putenv('MANAGE_API_URL=https://manage.example.test');
$fakeManage = static function (string $method, string $url, array $headers, ?string $body) use (&$acks): array {
    $acks[] = ['url' => $url, 'body' => json_decode((string) $body, true), 'headers' => $headers];

    return ['status' => 200, 'body' => '{"ok":true}', 'error' => null];
};
$kit = Kit::fromEnv('messaging', $pdo, CompanyLifecycle::manifestPath(), CompanyLifecycle::env(), [], [
    'http'  => $fakeManage,
    'hooks' => CompanyLifecycle::hooks($pdo, [
        'drive' => static function (string $key) use (&$driveDeleted, &$driveFailures): void {
            if ($driveFailures > 0) {
                $driveFailures--;
                throw new \RuntimeException('Drive could not be reached (test)');
            }
            $driveDeleted[] = $key;
        },
    ], static function (int $s): void {
    }),
]);
CompanyLifecycle::overrideForTesting($kit);

/**
 * A test fixture change to the append-only audit trail: the test-suite reset flag of migration 009
 * (messaging.audit_purge), SET LOCAL in its own transaction — never the lifecycle's own setting, which
 * migration 012 keeps to DELETE and the scrub.
 *
 * @param list<mixed> $params
 */
function auditFixture(\PDO $pdo, string $sql, array $params = []): void
{
    $pdo->beginTransaction();
    try {
        $pdo->exec("SELECT set_config('messaging.audit_purge', 'on', true)");
        $pdo->prepare($sql)->execute($params);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$clean = static function () use ($pdo): void {
    foreach ([CMP, OTHER] as $cmp) {
        foreach (['messaging_message_attachments', 'messaging_messages', 'messaging_conversations', 'messaging_channel_connections', 'messaging_dismissed_suggestions', 'messaging_settings', 'messaging_dispatch_jobs'] as $t) {
            $pdo->prepare("DELETE FROM {$t} WHERE cmp_id = ?")->execute([$cmp]);
        }
        auditFixture($pdo, 'DELETE FROM messaging_audit_events WHERE cmp_id = ?', [$cmp]);
        $pdo->prepare('DELETE FROM aic_lifecycle_state WHERE target_cmp_id = ?')->execute([$cmp]);
    }
    $pdo->exec("DELETE FROM aic_lifecycle_acks WHERE job_id IN (SELECT id FROM aic_lifecycle_jobs WHERE target_cmp_id IN (" . CMP . ',' . OTHER . ") OR target_uuid = '" . PERSON . "')");
    $pdo->exec("DELETE FROM aic_lifecycle_jobs WHERE target_cmp_id IN (" . CMP . ',' . OTHER . ") OR target_uuid = '" . PERSON . "'");
    $pdo->exec("DELETE FROM aic_lifecycle_events WHERE target_cmp_id IN (" . CMP . ',' . OTHER . ") OR target_uuid = '" . PERSON . "'");
    foreach ($pdo->query("SELECT nspname FROM pg_namespace WHERE nspname ~ '^aic_lifecycle_archive_(c" . CMP . "|c" . OTHER . "|u" . PERSON . ")_'")->fetchAll(\PDO::FETCH_COLUMN) as $schema) {
        $pdo->exec('DROP SCHEMA "' . $schema . '" CASCADE');
    }
    $pdo->exec("DELETE FROM lifecycle_object_deletions WHERE target_ref IN ('" . CMP . "', '" . OTHER . "', '" . PERSON . "')");
    $pdo->exec('DELETE FROM aic_lifecycle_expiries WHERE target_cmp_id IN (' . CMP . ',' . OTHER . ") OR target_uuid = '" . PERSON . "'");
};
$clean();

$router = new Router();
Routes::register($router);
$auth = Auth::forTesting('user-lifecycle', 'user', 'messaging');
Auth::adopt($auth);
Context::trustForTesting(CMP, $auth, true);
Context::trustForTesting(OTHER, $auth, true);

echo "Company lifecycle\n";

check('the reviewed manifest has no problems for the company and the user scope (strict)', static function () use ($pdo): void {
    $engine = new PurgeEngine($pdo, PurgeManifest::load(CompanyLifecycle::manifestPath()));
    same([], $engine->problems('company'), 'company problems');
    same([], $engine->problems('user'), 'user problems');
    same('messaging', $engine->manifest()->appKey(), 'app key');
});

check('kit 1.1: schema-1.1 is migrated and the manifest has no warnings (every retained table has a period)', static function () use ($pdo): void {
    same(true, (bool) $pdo->query("SELECT to_regclass('public.aic_lifecycle_expiries') IS NOT NULL")->fetchColumn(), 'aic_lifecycle_expiries');
    same([], PurgeManifest::load(CompanyLifecycle::manifestPath())->warnings(), 'manifest warnings');
    $audit = array_values(array_filter(PurgeManifest::load(CompanyLifecycle::manifestPath())->retained('company'), static fn (array $e): bool => $e['table'] === 'messaging_audit_events'));
    same(180, $audit[0]['retain_days'] ?? null, 'CERT-In 180 days');
    $m = PurgeManifest::load(CompanyLifecycle::manifestPath());
    $rules = array_keys($m->scrub('public.messaging_audit_events'));
    $hook = CompanyLifecycle::AUDIT_SCRUBBED;
    sort($rules);
    sort($hook);
    same($rules, $hook, 'CompanyLifecycle::auditScrubHook redacts exactly the manifest\'s scrub columns');
    same(['aicountly.lifecycle_purge' => 'on'], $m->expirySessionSettings(), 'expiry_session_settings (migration 012)');
});

check('migration 012: the audit trail stays append-only; only aicountly.lifecycle_purge (SET LOCAL) lets a DELETE and a scrub UPDATE through', static function () use ($pdo): void {
    $refused = static function (string $sql, bool $purge) use ($pdo): ?string {
        $pdo->beginTransaction();
        try {
            if ($purge) {
                $pdo->exec("SELECT set_config('aicountly.lifecycle_purge', 'on', true)");
            }
            $pdo->exec($sql);

            return null;
        } catch (\PDOException $e) {
            return (string) $e->getMessage();
        } finally {
            $pdo->rollBack();
        }
    };
    $pdo->prepare("INSERT INTO messaging_audit_events (cmp_id, actor_uuid, actor_kind, action, entity_type, before_state, after_state) VALUES (?, 'actor-1', 'user', 'immutability.probe', 'x', '{\"a\": 1}', '{\"a\": 2}')")->execute([OTHER]);
    $row = 'cmp_id = ' . OTHER . " AND action = 'immutability.probe'";
    foreach (["DELETE FROM messaging_audit_events WHERE {$row}", "UPDATE messaging_audit_events SET action = 'x' WHERE {$row}", "UPDATE messaging_audit_events SET before_state = NULL WHERE {$row}"] as $sql) {
        truthy(str_contains((string) $refused($sql, false), 'append-only'), 'refused without the setting: ' . $sql);
    }
    same(null, $refused("DELETE FROM messaging_audit_events WHERE {$row}", true), 'DELETE under the setting');
    same(null, $refused("UPDATE messaging_audit_events SET before_state = '\"[redacted]\"', after_state = '\"[redacted]\"' WHERE {$row}", true), 'the scrub UPDATE under the setting');
    // what a --restore does with rows a hook archived: an upsert of every column (unchanged but the scrubbed ones)
    same(null, $refused("INSERT INTO messaging_audit_events SELECT * FROM messaging_audit_events WHERE {$row}
        ON CONFLICT (audit_id) DO UPDATE SET before_state = '\"[redacted]\"', after_state = EXCLUDED.after_state, actor_uuid = EXCLUDED.actor_uuid, action = EXCLUDED.action", true), 'a restore\'s upsert under the setting');
    foreach (["UPDATE messaging_audit_events SET action = 'x' WHERE {$row}", "UPDATE messaging_audit_events SET actor_uuid = 'someone-else' WHERE {$row}", "UPDATE messaging_audit_events SET meta_json = '{}', before_state = NULL WHERE {$row}", "UPDATE messaging_audit_events SET created_at = now() - INTERVAL '400 days' WHERE {$row}"] as $sql) {
        truthy(str_contains((string) $refused($sql, true), 'append-only'), 'any other UPDATE is refused even under the setting: ' . $sql);
    }
    truthy(str_contains((string) $refused("DELETE FROM messaging_audit_events WHERE {$row}", false), 'append-only'), 'SET LOCAL ended with its transaction');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM messaging_audit_events WHERE {$row}")->fetchColumn(), 'the probe row is untouched');
    auditFixture($pdo, "DELETE FROM messaging_audit_events WHERE {$row}");
});

check('every allow-list and machine allow-list entry matches a real route', static function () use ($router): void {
    foreach (array_merge(CompanyLifecycle::ALLOW, CompanyLifecycle::MACHINE_ALLOW) as $entry) {
        [$method] = explode(' ', $entry, 2);
        $hit = false;
        foreach ($router->routes() as $route) {
            $path = '/api/' . preg_replace('/\{[^}]+\}/', 'x1', $route['path']);
            if (($method === '*' || $method === $route['method']) && !in_array($route['method'], ['GET'], true)
                && ReadOnlyGuard::decide('restricted', null, $route['method'], $path, [$entry]) === null) {
                $hit = true;
            }
        }
        truthy($hit, "{$entry} matches no write route");
    }
});

check('the receiver needs no session and refuses a bad signature (401)', static function () use ($router): void {
    Auth::adopt(null);
    try {
        $raw = (string) json_encode(event('company_restricted', ['cmp_id' => CMP, 'reason' => 'subscription_lapsed', 'restricted_until' => '2026-12-01T00:00:00Z']));
        CompanyLifecycle::setRawBodyForTesting($raw);
        $r = call($router, 'POST', 'internal/manage/company-lifecycle', [], null, ['HTTP_X_MANAGE_TIMESTAMP' => (string) time(), 'HTTP_X_MANAGE_SIGNATURE' => 'sha256=' . str_repeat('0', 64)]);
        same(401, $r['status'], 'bad signature');
    } finally {
        Auth::adopt(Auth::forTesting('user-lifecycle', 'user', 'messaging'));
    }
});

check('company_restricted: writes answer 423 COMPANY_RESTRICTED, reads and the allow-list pass', static function () use ($router): void {
    Auth::adopt(null);
    $r = deliver($router, event('company_restricted', ['cmp_id' => CMP, 'reason' => 'subscription_lapsed', 'restricted_until' => '2026-12-01T00:00:00Z']));
    Auth::adopt(Auth::forTesting('user-lifecycle', 'user', 'messaging'));
    same(202, $r['status'], 'receiver status ' . json_encode($r['body']));
    same('applied', $r['body']['outcome'] ?? null, 'outcome');

    $write = call($router, 'PUT', 'v1/settings', [], ['quiet_hours' => []]);
    same(423, $write['status'], 'settings write');
    same('COMPANY_RESTRICTED', $write['body']['code'] ?? null, 'code');
    same('COMPANY_RESTRICTED', $write['body']['error']['code'] ?? null, 'error.code');
    truthy(str_contains((string) ($write['body']['message'] ?? ''), '1 Dec 2026'), 'message names the date');

    truthy(call($router, 'GET', 'v1/settings')['status'] !== 423, 'a read passes');
    truthy(call($router, 'POST', 'v1/journeys/validate', [], ['definition' => []])['status'] !== 423, 'journey validation (allow-list) passes');
    truthy(call($router, 'DELETE', 'v1/suggestions/delivery_failures')['status'] !== 423, 'dismissing a suggestion (own UI state) passes');
    truthy(call($router, 'PUT', 'v1/settings', ['cmp_id' => OTHER], ['quiet_hours' => []])['status'] !== 423, 'another company is not restricted');
});

check('background sends wait while the company is read-only', static function () use ($pdo): void {
    $sql = 'SELECT COUNT(*) FROM (SELECT ' . CMP . ' AS cmp_id UNION ALL SELECT ' . OTHER . ') c WHERE ' . CompanyLifecycle::NOT_RESTRICTED_SQL;
    same(1, (int) $pdo->query($sql)->fetchColumn(), 'only the unrestricted company is eligible');
});

check('companyinfo lifecycle "active" lifts it (Manage is the source of truth)', static function () use ($router): void {
    CompanyLifecycle::syncFromCompanyInfo(CMP, ['data' => ['lifecycle' => 'active', 'restricted_until' => null]]);
    truthy(call($router, 'PUT', 'v1/settings', [], ['quiet_hours' => []])['status'] !== 423, 'write passes again');
    CompanyLifecycle::syncFromCompanyInfo(CMP, ['data' => ['lifecycle' => 'restricted', 'restricted_until' => '2026-12-01T00:00:00Z']]);
    same(423, call($router, 'PUT', 'v1/settings', [], ['quiet_hours' => []])['status'], 'restricted again from companyinfo');
});

check('kit 1.1: a product\'s service-key write into a read-only company is 423; provider webhooks pass', static function () use ($router, $pdo): void {
    // CMP is restricted here (previous check). Appointments, bound to CMP, sends a notice with its key.
    putenv('SERVICE_KEY_COMPANIES=appointments:' . CMP . '|' . OTHER);
    Auth::adopt(Auth::forTesting('appointments', 'service', 'appointments'));
    try {
        $r = call($router, 'POST', 'v1/messages', [], ['cmp_id' => CMP, 'kind' => 'reminder', 'to' => '+919800000001']);
        same(423, $r['status'], 'service send into a read-only company ' . json_encode($r['body']));
        same('COMPANY_RESTRICTED', $r['body']['code'] ?? null, 'code');
        same('restricted', $r['body']['lifecycle'] ?? null, 'lifecycle (DeferredSend reads it)');
        $other = call($router, 'POST', 'v1/messages', ['cmp_id' => OTHER], ['cmp_id' => OTHER, 'kind' => 'reminder', 'to' => '+919800000001']);
        truthy($other['status'] !== 423, 'another company is not refused (' . $other['status'] . ')');
    } finally {
        putenv('SERVICE_KEY_COMPANIES');
        Auth::adopt(Auth::forTesting('user-lifecycle', 'user', 'messaging'));
    }
    // A provider webhook is a machine write the allow-list keeps open; a service write is not.
    same(null, CompanyLifecycle::machineRefusal(CMP, 'POST', '/api/webhooks/whatsapp/' . uuid4()), 'provider webhook');
    same(423, CompanyLifecycle::machineRefusal(CMP, 'POST', '/api/v1/messages')[0] ?? null, 'service send');
    same(423, CompanyLifecycle::machineRefusal(CMP, 'POST', '/api/v1/messages/' . uuid4() . '/cancel')[0] ?? null, 'service cancel');
    same(null, CompanyLifecycle::machineRefusal(CMP, 'POST', '/api/internal/manage/company-lifecycle'), 'Manage\'s own event');
    same(null, CompanyLifecycle::machineRefusal(OTHER, 'POST', '/api/v1/messages'), 'an active company');

    // Through the real webhook path: a connection of the read-only company still receives.
    $conn = uuid4();
    $pdo->prepare("INSERT INTO messaging_channel_connections (connection_uuid, cmp_id, channel, provider, credential_ref) VALUES (?, ?, 'whatsapp', 'test_provider', 'MESSAGING_LC_TEST_TOKEN')")->execute([$conn, CMP]);
    try {
        $r = \Aicountly\Api\Domain\WebhookService::receive('no_such_provider', $conn, '{}', []);
        same(404, $r['status'], 'unknown provider still 404 (the gate is after tenant resolution)');
    } finally {
        $pdo->prepare('DELETE FROM messaging_channel_connections WHERE connection_uuid = ?')->execute([$conn]);
    }
});

check('company_purge_requested: rows purged, audit retained, files and Drive documents removed, retried, acked', static function () use ($router, $pdo, $kit, &$acks, &$driveDeleted, &$driveFailures): void {
    $dir = Env::get('MESSAGING_ATTACHMENT_DIR');
    truthy($dir !== '' && is_dir($dir), 'MESSAGING_ATTACHMENT_DIR is set by run.sh');
    $localKey = 'ab/' . str_repeat('c', 62) . '.pdf';
    @mkdir($dir . '/ab', 0700, true);
    file_put_contents($dir . '/' . $localKey, '%PDF-test');

    $conn = uuid4();
    $conv = uuid4();
    $msg = uuid4();
    $pdo->prepare("INSERT INTO messaging_channel_connections (connection_uuid, cmp_id, channel, provider, credential_ref) VALUES (?, ?, 'whatsapp', 'test_provider', 'MESSAGING_LC_TEST_TOKEN')")->execute([$conn, CMP]);
    $pdo->prepare("INSERT INTO messaging_conversations (conversation_uuid, cmp_id, connection_uuid, channel, customer_address) VALUES (?, ?, ?, 'whatsapp', '+919800000009')")->execute([$conv, CMP, $conn]);
    $pdo->prepare("INSERT INTO messaging_messages (message_uuid, cmp_id, conversation_uuid, connection_uuid, channel, direction) VALUES (?, ?, ?, ?, 'whatsapp', 'inbound')")->execute([$msg, CMP, $conv, $conn]);
    $ins = $pdo->prepare('INSERT INTO messaging_message_attachments (attachment_uuid, cmp_id, message_uuid, storage, storage_ref) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([uuid4(), CMP, $msg, 'local', $localKey]);
    $ins->execute([uuid4(), CMP, $msg, 'drive', 'drive:' . CMP . ':1:0:555']);
    $ins->execute([uuid4(), CMP, $msg, 'provider', 'meta-media-1']);
    $pdo->prepare("INSERT INTO messaging_audit_events (cmp_id, actor_uuid, actor_kind, action, entity_type, before_state, after_state) VALUES (?, 'user-lifecycle', 'user', 'settings.updated', 'settings', '{\"address\": \"+919800000009\"}', '{\"address\": \"+919800000010\"}')")->execute([CMP]);
    // another company's rows are untouched
    $pdo->prepare("INSERT INTO messaging_dismissed_suggestions (cmp_id, user_uuid, suggestion_key) VALUES (?, 'someone', 'k1')")->execute([OTHER]);

    Auth::adopt(null);
    $event = event('company_purge_requested', ['cmp_id' => CMP, 'reason' => 'subscription_lapsed']);
    $r = deliver($router, $event);
    Auth::adopt(Auth::forTesting('user-lifecycle', 'user', 'messaging'));
    same(202, $r['status'], 'receiver');
    same('queued', $r['body']['outcome'] ?? null, 'queued');

    // First run: rows purged, Drive unreachable → the job is retried, the object stays pending.
    $driveFailures = 3;
    $run = $kit->worker()->run();
    same(1, $run['retrying'], 'retrying after a store failure ' . json_encode($run));
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM messaging_messages WHERE cmp_id = ' . CMP)->fetchColumn(), 'messages purged');
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM messaging_channel_connections WHERE cmp_id = ' . CMP)->fetchColumn(), 'connections purged');
    truthy(!is_file($dir . '/' . $localKey), 'local attachment file deleted');
    $pending = $pdo->query("SELECT store, attempts, last_error FROM lifecycle_object_deletions WHERE target_ref = '" . CMP . "' AND status = 'pending'")->fetchAll(\PDO::FETCH_ASSOC);
    same(1, count($pending), 'one object pending');
    same('drive', $pending[0]['store'], 'the Drive document is pending');
    same(3, (int) $pending[0]['attempts'], 'tried three times');
    same([], $acks, 'no ack yet');

    // Retry (as the kit would 10 minutes later): Drive answers now.
    $pdo->exec("UPDATE aic_lifecycle_jobs SET next_attempt_at = now() - INTERVAL '1 minute' WHERE target_cmp_id = " . CMP);
    $run = $kit->worker()->run();
    same(1, $run['purged'], 'purged on the retry ' . json_encode($run) . json_encode($pdo->query('SELECT status, attempts, next_attempt_at, now() AS db_now, detail FROM aic_lifecycle_jobs WHERE target_cmp_id = ' . CMP)->fetchAll(\PDO::FETCH_ASSOC)));
    same(['drive:' . CMP . ':1:0:555'], $driveDeleted, 'Drive document deleted');
    same(0, (int) $pdo->query("SELECT COUNT(*) FROM lifecycle_object_deletions WHERE target_ref = '" . CMP . "' AND status <> 'deleted'")->fetchColumn(), 'every object recorded deleted');
    same(2, (int) $pdo->query("SELECT COUNT(*) FROM lifecycle_object_deletions WHERE target_ref = '" . CMP . "'")->fetchColumn(), 'provider media is not ours to delete');
    truthy((int) $pdo->query('SELECT COUNT(*) FROM messaging_audit_events WHERE cmp_id = ' . CMP)->fetchColumn() >= 1, 'audit trail retained');
    $kept = $pdo->query("SELECT action, before_state::text AS b, after_state::text AS a FROM messaging_audit_events WHERE cmp_id = " . CMP . " AND action = 'settings.updated'")->fetch(\PDO::FETCH_ASSOC);
    same(['settings.updated', '"[redacted]"', '"[redacted]"'], [$kept['action'] ?? null, $kept['b'] ?? null, $kept['a'] ?? null], 'kit 1.1 scrub: who / what kept, the row copies redacted');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM messaging_dismissed_suggestions WHERE cmp_id = ' . OTHER)->fetchColumn(), 'other company untouched');

    same(1, count($acks), 'one ack');
    same('purged', $acks[0]['body']['status'] ?? null, 'ack status');
    same(CMP, $acks[0]['body']['cmp_id'] ?? null, 'ack cmp_id');
    same(['public.messaging_audit_events'], $acks[0]['body']['retained'] ?? null, 'retained reported');
    truthy(str_contains((string) $acks[0]['body']['detail'], 'CERT-In'), 'legal reason in the detail');
    same('https://manage.example.test/api/internal/company-lifecycle/ack', $acks[0]['url'], 'ack URL');
    // The scrub ran in the first attempt (before the Drive failure); the archive keeps a scrubbed copy.
    $copies = 0;
    foreach (array_filter($kit->engine()->archives(), static fn (array $a): bool => $a['target'] === (string) CMP) as $a) {
        if ((bool) $pdo->query("SELECT to_regclass('\"" . $a['schema'] . "\"._aic_h_messaging_audit_events') IS NOT NULL")->fetchColumn()) {
            $copies += (int) $pdo->query('SELECT COUNT(*) FROM "' . $a['schema'] . "\"._aic_h_messaging_audit_events WHERE before_state = '\"[redacted]\"'::jsonb")->fetchColumn();
        }
    }
    truthy($copies >= 1, 'the audit rows were archived (scrubbed) before the hook changed them');

    // The archive holds the purged rows until it is dropped (the kit's restore window).
    $archives = array_filter($kit->engine()->archives(), static fn (array $a): bool => $a['target'] === (string) CMP);
    truthy(count($archives) >= 1, 'an archive holds the purged rows');
    foreach ($archives as $a) {
        $kit->engine()->dropArchive($a['schema']);
    }

    $write = call($router, 'PUT', 'v1/settings', [], ['quiet_hours' => []]);
    same(423, $write['status'], 'purged company stays read-only');
});

check('kit 1.1: --expire-retained deletes the purged company\'s audit rows after 180 days, keeps the rest', static function () use ($pdo, $kit): void {
    auditFixture($pdo, "UPDATE messaging_audit_events SET created_at = now() - INTERVAL '181 days' WHERE cmp_id = ?", [CMP]);
    $pdo->prepare("INSERT INTO messaging_audit_events (cmp_id, actor_uuid, actor_kind, action, entity_type, created_at) VALUES (?, 'u', 'user', 'recent', 'settings', now() - INTERVAL '10 days')")->execute([CMP]);
    $pdo->prepare("INSERT INTO messaging_audit_events (cmp_id, actor_uuid, actor_kind, action, entity_type, created_at) VALUES (?, 'u', 'user', 'live-company', 'settings', now() - INTERVAL '400 days')")->execute([OTHER]);
    $old = (int) $pdo->query('SELECT COUNT(*) FROM messaging_audit_events WHERE cmp_id = ' . CMP . " AND action <> 'recent'")->fetchColumn();
    truthy($old >= 1, 'an old retained row exists');

    $dry = $kit->worker()->expireRetained(true);
    same($old, $dry['expired_rows'], 'dry run counts ' . json_encode($dry));
    same($old + 1, (int) $pdo->query('SELECT COUNT(*) FROM messaging_audit_events WHERE cmp_id = ' . CMP)->fetchColumn(), 'dry run changes nothing');

    $r = $kit->worker()->expireRetained();
    same(0, $r['failed'], 'no failure ' . json_encode($r['errors']));
    same($old, $r['expired_rows'], 'expired rows');
    same(['recent'], $pdo->query('SELECT action FROM messaging_audit_events WHERE cmp_id = ' . CMP)->fetchAll(\PDO::FETCH_COLUMN), 'only the recent row is left');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM messaging_audit_events WHERE cmp_id = ' . OTHER . " AND action = 'live-company'")->fetchColumn(), 'a live company is never expired by the kit');
    same($old, (int) $pdo->query("SELECT COALESCE(SUM(rows_deleted), 0) FROM aic_lifecycle_expiries WHERE target_cmp_id = " . CMP . " AND source_table = 'public.messaging_audit_events' AND reason = 'retention_ended'")->fetchColumn(), 'recorded');
    foreach ($kit->engine()->archives() as $a) {
        if ($a['target'] === (string) CMP) {
            $kit->engine()->dropArchive($a['schema']);
        }
    }
    auditFixture($pdo, 'DELETE FROM messaging_audit_events WHERE cmp_id = ?', [OTHER]);
});

check('account_purge_requested: only the person\'s own UI state goes', static function () use ($router, $pdo, $kit, &$acks): void {
    $acks = [];
    $pdo->prepare('INSERT INTO messaging_dismissed_suggestions (cmp_id, user_uuid, suggestion_key) VALUES (?, ?, ?)')->execute([OTHER, PERSON, 'k2']);
    Auth::adopt(null);
    $r = deliver($router, event('account_purge_requested', ['uuid' => PERSON, 'reason' => 'account_closed']));
    Auth::adopt(Auth::forTesting('user-lifecycle', 'user', 'messaging'));
    same(202, $r['status'], 'receiver');
    $run = $kit->worker()->run();
    same(1, $run['purged'], 'purged ' . json_encode($run));
    same(0, (int) $pdo->query("SELECT COUNT(*) FROM messaging_dismissed_suggestions WHERE user_uuid = '" . PERSON . "'")->fetchColumn(), 'personal state gone');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM messaging_dismissed_suggestions WHERE user_uuid = 'someone'")->fetchColumn(), 'others kept');
    same(PERSON, $acks[0]['body']['uuid'] ?? null, 'ack names the person');
    foreach ($kit->engine()->archives() as $a) {
        if ($a['target'] === PERSON) {
            $kit->engine()->dropArchive($a['schema']);
        }
    }
});

check('an object sweep with no deleter for a store keeps it pending and fails the attempt', static function () use ($pdo): void {
    $sweep = new ObjectSweep($pdo, [], [], static function (): void {
    }, static function (): void {
    });
    $pdo->exec("INSERT INTO lifecycle_object_deletions (target_kind, target_ref, source_table, store, object_key) VALUES ('company', '" . OTHER . "', 't', 'nowhere', 'k')");
    try {
        $sweep->run('company', (string) OTHER, []);
        throw new \LogicException('expected a failure');
    } catch (\RuntimeException $e) {
        truthy(str_contains($e->getMessage(), 'could not be removed yet'), 'message');
    }
});

$clean();
CompanyLifecycle::overrideForTesting(null);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo 'Failed: ' . implode('; ', $failures) . "\n";
    exit(1);
}
