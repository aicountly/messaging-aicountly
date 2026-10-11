<?php

declare(strict_types=1);

/**
 * Messaging's Console channel secrets against the REAL Console API (E8-PUL F104, F087).
 *
 * Dev-only cross-repo check (not in run.sh: it needs a Console checkout and a PostgreSQL database
 * migrated with Console's own runner, scripts/migrate.php):
 *
 *   CONSOLE_E2E_ROOT=/path/to/console-react-app/server-php \
 *   CONSOLE_E2E_DB_HOST=127.0.0.1 CONSOLE_E2E_DB_PORT=5432 CONSOLE_E2E_DB_NAME=…test \
 *   CONSOLE_E2E_DB_USER=… CONSOLE_E2E_DB_PASS=… CONSOLE_E2E_PORT=34075 \
 *   php -d variables_order=EGPCS tests/console_real.php
 *
 * It seeds, in Console's database and through Console's own libraries, a channel secret on
 * messaging.gh.aicountly.com (module channel:zz_wa, keeps_own_credential) and mints a key for the
 * messaging-sandbox identity that Console migration 061 creates; starts Console's real
 * public/index.php; then asks through Messaging's ConsoleSecrets exactly as a send would.
 * Everything it seeds is removed at the end.
 */

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

use Aicountly\Api\Channels\ConsoleSecrets;

$root = rtrim((string) getenv('CONSOLE_E2E_ROOT'), '/');
if ($root === '' || !is_file($root . '/public/index.php')) {
    fwrite(STDERR, "Set CONSOLE_E2E_ROOT to console-react-app/server-php.\n");
    exit(2);
}
$db = [
    'DB_HOST' => getenv('CONSOLE_E2E_DB_HOST') ?: '127.0.0.1', 'DB_PORT' => getenv('CONSOLE_E2E_DB_PORT') ?: '5432',
    'DB_NAME' => (string) getenv('CONSOLE_E2E_DB_NAME'), 'DB_USER' => (string) getenv('CONSOLE_E2E_DB_USER'), 'DB_PASS' => (string) getenv('CONSOLE_E2E_DB_PASS'),
];
if (!str_contains($db['DB_NAME'], 'test')) {
    fwrite(STDERR, "CONSOLE_E2E_DB_NAME must be a test database (its name must contain 'test').\n");
    exit(2);
}
$encKey = base64_encode(random_bytes(32));
$sharedKey = 'zz-test-estate-key-' . bin2hex(random_bytes(8));
foreach (['CONSOLE_AI_KEY_ENCRYPTION_KEY' => $encKey] as $k => $v) {
    $_ENV[$k] = $v;
    putenv("{$k}={$v}");
}
require_once $root . '/app/Libraries/AiKeyCipher.php';
require_once $root . '/app/Libraries/AiCredentialService.php';
require_once $root . '/app/Libraries/AiServiceIdentity.php';
$pdo = new PDO("pgsql:host={$db['DB_HOST']};port={$db['DB_PORT']};dbname={$db['DB_NAME']}", $db['DB_USER'], $db['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$passed = 0;
$failed = 0;
$check = static function (string $what, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    fwrite(STDOUT, ($ok ? '  PASS  ' : '  FAIL  ') . $what . ($ok || $detail === '' ? '' : " — {$detail}") . "\n");
};

// ---- seed Console ------------------------------------------------------------------------
$one = static function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($p);

    return $st->fetchColumn();
};
$domainId = (int) $one("SELECT id FROM ai_domains WHERE domain = 'messaging.gh.aicountly.com'");
$identityId = (int) $one("SELECT id FROM ai_service_identities WHERE slug = 'messaging-sandbox'");
if ($domainId === 0 || $identityId === 0) {
    fwrite(STDERR, "Console migration 061 is not applied to {$db['DB_NAME']}.\n");
    exit(2);
}
$providerId = (int) $one("INSERT INTO ai_providers (slug, name, base_url, auth_method, auth_header, notes, kind)
                          VALUES ('zz-test-wa', 'ZZ WhatsApp (test)', 'https://zz-test.invalid', 'bearer', NULL, 'zz-test', 'non_model')
                          ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name RETURNING id");
$modelId = (int) $one("INSERT INTO ai_models (provider_id, model_key, display_name) VALUES (:p, 'zz-test-wa-sender', 'ZZ sender') RETURNING id", ['p' => $providerId]);
$moduleId = (int) $one("INSERT INTO ai_modules (domain_id, module_key, name, keeps_own_credential) VALUES (:d, 'channel:zz_wa', 'ZZ channel', TRUE)
                        ON CONFLICT (domain_id, module_key) DO UPDATE SET keeps_own_credential = TRUE RETURNING id", ['d' => $domainId]);
$secret = 'zz-test-wa-secret-' . bin2hex(random_bytes(6));
$credId = AiCredentialService::store($pdo, $domainId, $providerId, 'zz-test-wa', $secret, null)['id'];
AiCredentialService::bindModule($pdo, $moduleId, $modelId, (int) $credId, 'primary', 1, null);
$minted = AiServiceIdentity::mintKey($pdo, $identityId);
$savedUse = $pdo->query("SELECT first_used_at, last_used_at FROM ai_service_identities WHERE id = {$identityId}")->fetch(PDO::FETCH_ASSOC);

$cleanup = static function () use ($pdo, $moduleId, $credId, $modelId, $providerId, $minted, $identityId, $savedUse): void {
    $pdo->exec("DELETE FROM ai_module_models WHERE module_id = {$moduleId}");
    $pdo->exec("DELETE FROM ai_modules WHERE id = {$moduleId}");
    $pdo->exec("DELETE FROM ai_credentials WHERE id = {$credId}");
    $pdo->exec("DELETE FROM ai_models WHERE id = {$modelId}");
    $pdo->exec("DELETE FROM ai_providers WHERE id = {$providerId}");
    $pdo->exec('DELETE FROM ai_service_identity_keys WHERE id = ' . (int) $minted['id']);
    $pdo->prepare('UPDATE ai_service_identities SET first_used_at = :f, last_used_at = :l WHERE id = :i')
        ->execute(['f' => $savedUse['first_used_at'], 'l' => $savedUse['last_used_at'], 'i' => $identityId]);
};

// ---- start the real Console ------------------------------------------------------------
$port = (int) (getenv('CONSOLE_E2E_PORT') ?: 34075);
$log = sys_get_temp_dir() . "/e8pl_messaging_console_{$port}.log";
$env = array_merge(array_filter(getenv(), 'is_string'), $db, [
    'APP_ENV' => 'testing', 'CONSOLE_AI_KEY_ENCRYPTION_KEY' => $encKey, 'CONSOLE_SERVICE_KEY' => $sharedKey,
    // The transition window is OPEN: the estate key must still be refused for a SaaS domain (F087).
    'CONSOLE_AI_LEGACY_SHARED_KEY_UNTIL' => gmdate('Y-m-d', time() + 3 * 86400),
]);
$proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', "127.0.0.1:{$port}", $root . '/public/index.php'],
    [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
$base = "http://127.0.0.1:{$port}/api";
for ($i = 0; $i < 50 && @file_get_contents($base . '/health') === false && !isset($http_response_header); $i++) {
    usleep(100000);
}

try {
    fwrite(STDOUT, "Messaging -> real Console\n");
    putenv('APP_ENV=local');
    putenv("CONSOLE_API_URL={$base}");
    putenv('CONSOLE_AI_SERVICE_KEY=' . $minted['key']);
    putenv('MESSAGING_CONSOLE_DOMAIN');
    ConsoleSecrets::forgetForTesting();
    $check('Messaging asks for its real sandbox hostname', ConsoleSecrets::domain() === 'messaging.gh.aicountly.com', ConsoleSecrets::domain());
    $check('a console:zz_wa channel secret resolves with Messaging\'s own identity key', ConsoleSecrets::get('zz_wa') === $secret);

    $ctx = stream_context_create(['http' => ['header' => "Authorization: Bearer {$sharedKey}", 'ignore_errors' => true]]);
    $raw = (string) @file_get_contents($base . '/ai/credentials/resolve?domain=messaging.gh.aicountly.com&module=channel%3Azz_wa', false, $ctx);
    $status = (int) (preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    $check('the estate key is refused for Messaging\'s domain even inside the window (403 shared_key_saas_domain)',
        $status === 403 && str_contains($raw, 'shared_key_saas_domain'), "{$status} {$raw}");
    $check('and its answer carries no secret', !str_contains($raw, $secret));

    putenv('CONSOLE_AI_SERVICE_KEY');
    putenv("CONSOLE_SERVICE_KEY={$sharedKey}");
    ConsoleSecrets::forgetForTesting();
    $check('Messaging configured with the estate key only resolves nothing', ConsoleSecrets::get('zz_wa') === '');
    putenv('CONSOLE_SERVICE_KEY');

    AiServiceIdentity::revokeKey($pdo, $identityId, (int) $minted['id']);
    putenv('CONSOLE_AI_SERVICE_KEY=' . $minted['key']);
    ConsoleSecrets::forgetForTesting();
    $check('a revoked identity key resolves nothing (fails closed as "not configured")', ConsoleSecrets::get('zz_wa') === '');
} finally {
    proc_terminate($proc);
    proc_close($proc);
    $cleanup();
}

fwrite(STDOUT, "\n{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
