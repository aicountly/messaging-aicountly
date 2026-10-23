<?php

declare(strict_types=1);

/**
 * The same-origin relays to Manage's company registry (/api/manage/v1/*) and to the portal's
 * logout (/api/global/logout).
 *
 * The browser used to call manage.aicountly.com and my.aicountly.com itself. The hosting's bot
 * shield answers distrusted IPs (common Indian CGNAT broadband) with a challenge page that carries
 * no CORS headers, so those cross-site calls failed with no response. Now the browser calls only
 * this product's /api and the server relays. This pins:
 *
 *   - every call the shared client (web/src/company-access/companyRegistryClient.ts) makes is
 *     relayed — a drift check that reads the client;
 *   - nothing else is: wrong method, an unlisted path, `..` or an encoded slash are 404;
 *   - over real HTTP through index.php: only the allowed headers reach Manage (no cookie, no
 *     service key, no actor header), X-Saas-Origin names this product, the query, body, method,
 *     status, content type and X-Correlation-Id pass through, an encoded grant reference stays one
 *     path segment, and a Manage that does not answer is a 504 manage_unavailable;
 *   - POST /api/global/logout reaches the portal's /api/logout.
 *
 *   php server-php/tests/manage_relay.php
 */

$root = dirname(__DIR__);
require $root . '/src/Env.php';
require $root . '/src/Autoload.php';

use Aicountly\Api\ManageRelay;

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : "\n        " . $detail) . "\n";
    $ok ? $passed++ : $failed++;
};

// ---------------------------------------------------------------------------
// The allowlist, and its drift against the shared client
// ---------------------------------------------------------------------------

$client = (string) file_get_contents(dirname($root) . '/web/src/company-access/companyRegistryClient.ts');
// One level of nested generics too (`call<Array<{ key: string }>>('GET', '/v1/applications')`).
preg_match_all("/call<(?:[^<>]|<[^<>]*>)*>\\('(GET|POST|PUT|PATCH|DELETE)', [`']([^`']+)[`']/", $client, $calls, PREG_SET_ORDER);
$check('the shared client was read (all 29 calls of company-access 1.2.0)', count($calls) >= 29, (string) count($calls));
$version = trim((string) file_get_contents(dirname($root) . '/web/src/company-access/VERSION'));
$check('the vendored company-access is 1.2.0', $version === '1.2.0', $version);
foreach ($calls as [, $method, $path]) {
    $concrete = preg_replace('/\$\{query\([^}]*\}\)?\}?/', '', $path);
    $concrete = str_replace('${action}', 'accept', (string) $concrete);
    $concrete = preg_replace('/\$\{enc\([^)]*\)\}/', rawurlencode('1f2e-uuid:messaging'), (string) $concrete);
    $concrete = preg_replace('/\$\{[^}]*\}/', '74', (string) $concrete);
    $concrete = ltrim(explode('?', (string) $concrete)[0], '/');
    $url = ManageRelay::upstreamUrl($method, 'manage/' . $concrete, '', 'https://manage.example');
    $check("client call {$method} {$path} is relayed", $url !== null, $concrete);
}
foreach (['accept', 'decline', 'exit'] as $action) {
    $check("POST v1/workspaces/3/{$action} is relayed", ManageRelay::isRelayed('POST', 'v1/workspaces/3/' . $action));
}

// The 1.1/1.2 calls and the plan reads (CONTRACTS §1, §2, §3.5), pinned by name as well as by the drift check.
foreach ([
    ['GET', 'v1/app-roles'],
    ['GET', 'v1/company-creation-targets'],
    ['PATCH', 'v1/workspaces/3'],
    ['PATCH', 'v1/workspaces/3/members/1f2e-uuid'],
    ['POST', 'v1/companies/74/creator-access/retry'],
    ['GET', 'v1/companies/74/plan'],
    ['GET', 'v1/me/plan'],
    ['GET', 'v1/me/pending'],
] as [$method, $path]) {
    $check("{$method} {$path} is relayed", ManageRelay::isRelayed($method, $path));
}

$refused = [
    ['DELETE', 'manage/v1/companies/74/permissions', 'the right path with the wrong method'],
    ['POST', 'manage/v1/workspaces', 'creating a workspace is not a call the screens make'],
    ['PATCH', 'manage/v1/workspaces', 'a PATCH without a workspace id'],
    ['DELETE', 'manage/v1/workspaces/3', 'deleting a workspace is not a call the screens make'],
    ['GET', 'manage/v1/me/profile', 'another v1/me path'],
    ['POST', 'manage/v1/companies/74/plan', 'the plan is read-only here'],
    ['OPTIONS', 'manage/v1/workspaces', 'an unlisted method'],
    ['GET', 'manage/v1/audit-log', 'an unlisted Manage path'],
    ['GET', 'manage/internal/company-status', 'Manage internal routes'],
    ['GET', 'manage/companies', 'the non-registry Manage API'],
    ['GET', 'manage/v1/companies/74/permissions/extra/level', 'an extra path level'],
    ['DELETE', 'manage/v1/companies/74/permissions/..', 'a `..` reference'],
    ['DELETE', 'manage/v1/companies/74/permissions/%2e%2e', 'an encoded `..` reference'],
    ['DELETE', 'manage/v1/workspaces/3/members/..%2F..%2Fbilling', 'an encoded slash'],
    ['DELETE', 'manage/v1/workspaces/3/members/../../billing', 'a traversal'],
    ['GET', 'manage/v1/companies/74/summary%3F', 'an encoded question mark'],
    ['GET', 'manage/v1//applications', 'an empty segment'],
    ['GET', 'manage', 'the bare prefix'],
    ['GET', 'global/v1/applications', 'another prefix'],
];
foreach ($refused as [$method, $path, $why]) {
    $check("refused: {$method} {$path} ({$why})", ManageRelay::upstreamUrl($method, $path, '', 'https://manage.example') === null);
}

$url = ManageRelay::upstreamUrl('patch', 'manage/v1/companies/74/permissions/1f2e%3Amessaging', 'app=messaging', 'https://manage.example/api/');
$check('a grant reference is decoded, checked and sent encoded again, with the query', $url === 'https://manage.example/api/v1/companies/74/permissions/1f2e%3Amessaging?app=messaging', (string) $url);

// ---------------------------------------------------------------------------
// Over real HTTP through index.php
// ---------------------------------------------------------------------------

$apiPort = (int) (getenv('MANAGE_RELAY_API_PORT') ?: 33991);
$deadApiPort = $apiPort + 1;
$fakePort = $apiPort + 2;
$closedPort = $apiPort + 3;

$fake = sys_get_temp_dir() . '/manage_relay_fake_' . getmypid() . '.php';
file_put_contents($fake, '<?php
$headers = function_exists("getallheaders") ? getallheaders() : [];
http_response_code(str_contains($_SERVER["REQUEST_URI"], "/permissions/") ? 409 : 200);
header("Content-Type: application/vnd.test+json");
header("X-Correlation-Id: from-manage-123");
echo json_encode(["method" => $_SERVER["REQUEST_METHOD"], "uri" => $_SERVER["REQUEST_URI"], "headers" => $headers, "body" => file_get_contents("php://input")]);
');

$base = getenv();
$apiEnv = ['APP_ENV' => 'local', 'APP_PRODUCT_KEY' => 'messaging', 'PORTAL_AUTH_BASE' => 'http://127.0.0.1:' . $fakePort] + $base;
$procs = [];
$quiet = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
$procs[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $fakePort, $fake], $quiet, $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '2'] + $base);
$procs[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $apiPort, '-t', $root, $root . '/index.php'], $quiet, $pipes, $root, ['MANAGE_API_BASE' => 'http://127.0.0.1:' . $fakePort] + $apiEnv);
$procs[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $deadApiPort, '-t', $root, $root . '/index.php'], $quiet, $pipes, $root, ['MANAGE_API_BASE' => 'http://127.0.0.1:' . $closedPort] + $apiEnv);
foreach ([$fakePort, $apiPort, $deadApiPort] as $port) {
    for ($i = 0; $i < 60; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
        if ($fp) {
            fclose($fp);
            break;
        }
        usleep(100000);
    }
}

$http = static function (int $port, string $method, string $path, array $headers = [], ?string $body = null): array {
    $responseHeaders = [];
    $ch = curl_init('http://127.0.0.1:' . $port . '/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_NOPROXY => '*',
        CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }

            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => $responseHeaders, 'raw' => $raw, 'json' => json_decode($raw, true)];
};
$seen = static function (array $r): array {
    $out = [];
    foreach ((array) ($r['json']['headers'] ?? []) as $k => $v) {
        $out[strtolower((string) $k)] = (string) $v;
    }

    return $out;
};

try {
    $r = $http($apiPort, 'GET', 'manage/v1/company-directory?app=messaging&page=2', [
        'Authorization: Bearer person-ses-key',
        'Accept: application/json',
        'X-Correlation-Id: browser-cid-1',
        'Cookie: aic_session=secret',
        'X-Service-Key: forged-service-key',
        'X-Actor-Uuid: someone-else',
        'X-Saas-Origin: books',
        'X-Forwarded-For: 10.0.0.1',
    ]);
    $h = $seen($r);
    $check('GET is relayed with its query string', $r['status'] === 200 && ($r['json']['method'] ?? '') === 'GET'
        && str_ends_with((string) ($r['json']['uri'] ?? ''), '/v1/company-directory?app=messaging&page=2'), $r['raw']);
    $check('the person\'s Authorization, Accept and X-Correlation-Id reach Manage', ($h['authorization'] ?? '') === 'Bearer person-ses-key'
        && ($h['accept'] ?? '') === 'application/json' && ($h['x-correlation-id'] ?? '') === 'browser-cid-1', json_encode($h));
    $check('cookies, service keys, actor and forwarding headers never reach Manage', !isset($h['cookie']) && !isset($h['x-service-key'])
        && !isset($h['x-actor-uuid']) && !isset($h['x-forwarded-for']), json_encode($h));
    $check('X-Saas-Origin names this product, whatever the browser sent', ($h['x-saas-origin'] ?? '') === 'messaging', json_encode($h));
    $check('Manage\'s content type and X-Correlation-Id come back', ($r['headers']['content-type'] ?? '') === 'application/vnd.test+json'
        && ($r['headers']['x-correlation-id'] ?? '') === 'from-manage-123', json_encode($r['headers']));

    $r = $http($apiPort, 'PATCH', 'manage/v1/companies/74/permissions/1f2e%3Amessaging', [
        'Authorization: Bearer person-ses-key', 'Content-Type: application/json', 'Idempotency-Key: idem-1',
    ], '{"role_key":"agent"}');
    $h = $seen($r);
    $check('PATCH is relayed with its body, the reference still one encoded segment, and Manage\'s status', $r['status'] === 409
        && ($r['json']['method'] ?? '') === 'PATCH' && str_ends_with((string) ($r['json']['uri'] ?? ''), '/v1/companies/74/permissions/1f2e%3Amessaging')
        && ($r['json']['body'] ?? '') === '{"role_key":"agent"}', $r['raw']);
    $check('Content-Type and Idempotency-Key reach Manage on a write', ($h['content-type'] ?? '') === 'application/json'
        && ($h['idempotency-key'] ?? '') === 'idem-1', json_encode($h));

    $r = $http($apiPort, 'DELETE', 'manage/v1/workspaces/3/members/1f2e', ['Content-Type: application/json'], '{"revoke_company_access":true}');
    $check('DELETE is relayed with its body', ($r['json']['method'] ?? '') === 'DELETE' && ($r['json']['body'] ?? '') === '{"revoke_company_access":true}', $r['raw']);

    foreach ([
        ['DELETE', 'manage/v1/companies/74/permissions'],
        ['GET', 'manage/v1/audit-log'],
        ['GET', 'manage/companies'],
        ['DELETE', 'manage/v1/workspaces/3/members/..%2F..%2Fbilling'],
        ['DELETE', 'manage/v1/companies/74/permissions/%2e%2e'],
    ] as [$method, $path]) {
        $r = $http($apiPort, $method, $path, ['Authorization: Bearer person-ses-key']);
        $check("over HTTP, {$method} {$path} is 404 and Manage is not called", $r['status'] === 404 && !isset($r['json']['method']), $r['status'] . ' ' . $r['raw']);
    }

    $r = $http($deadApiPort, 'GET', 'manage/v1/workspaces', ['Authorization: Bearer person-ses-key']);
    $check('a Manage that does not answer is 504 manage_unavailable', $r['status'] === 504 && ($r['json']['error']['code'] ?? '') === 'manage_unavailable', $r['raw']);
    $r = $http($deadApiPort, 'POST', 'manage/v1/access-requests/9/approve', ['Authorization: Bearer person-ses-key', 'Content-Type: application/json'], '{}');
    $check('a write Manage did not answer says it is not known whether it was made', $r['status'] === 504
        && str_contains((string) ($r['json']['error']['message'] ?? ''), 'not known whether the change was made'), $r['raw']);

    $r = $http($apiPort, 'POST', 'global/logout', ['Authorization: Bearer portal-auth-token', 'Content-Type: application/json', 'Cookie: aic_session=secret']);
    $h = $seen($r);
    $check('POST /global/logout reaches the portal\'s /api/logout with the token', ($r['json']['method'] ?? '') === 'POST'
        && ($r['json']['uri'] ?? '') === '/api/logout' && ($h['authorization'] ?? '') === 'Bearer portal-auth-token' && !isset($h['cookie']), $r['raw']);
} finally {
    foreach ($procs as $p) {
        proc_terminate($p);
        proc_close($p);
    }
    @unlink($fake);
}

echo "\n" . str_repeat('-', 60) . "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
