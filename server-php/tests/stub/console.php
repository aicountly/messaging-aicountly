<?php

declare(strict_types=1);

/**
 * Stands in for Console's database-details endpoint during tests/console_database.php.
 *
 * WRITTEN FROM THE ENDPOINT'S CONTRACT, NOT FROM MESSAGING'S CLIENT: a stub that
 * agrees with the client it tests proves nothing. This one answers the way
 * Console's SaaS Database Details does:
 *
 *   GET /api/database-details/resolve
 *       Authorization: Bearer <key of ONE product + environment row>
 *     200  {"success": true, "data": {"database_name", "database_username", "environment"}}
 *     401  any other key (revoked, rotated or wrong)
 *
 * It is a real HTTP server on purpose: the suite's other cases script the
 * transport, which is exactly the part this one exists to test — the curl
 * request that really goes out (no redirect followed, a capped body, a
 * timeout).
 *
 * Behaviour is the JSON file CONSOLE_STUB_STATE, read on every request:
 *   {"key": "...", "row": {"database_name": "...", ...}, "mode": "ok|redirect|huge|status", "status": 503}
 * Every request is appended to CONSOLE_STUB_LOG as one JSON line, so the suite
 * can assert what was asked, with which credential, and what was NOT asked.
 */

$state = json_decode((string) @file_get_contents((string) getenv('CONSOLE_STUB_STATE')), true);
$state = is_array($state) ? $state : [];

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

file_put_contents(
    (string) getenv('CONSOLE_STUB_LOG'),
    json_encode(['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'path' => $path, 'authorization' => $authorization]) . "\n",
    FILE_APPEND | LOCK_EX,
);

function reply(int $status, string $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo $body;
    exit;
}

if ($path === '/elsewhere') {
    // Where a redirect would send the key. Reaching it at all is the failure.
    reply(200, json_encode(['success' => true, 'data' => ['database_name' => 'redirected_db', 'database_username' => 'redirected_user']]));
}

if ($path !== '/api/database-details/resolve') {
    reply(404, '{"success":false,"message":"Not found."}');
}

$mode = (string) ($state['mode'] ?? 'ok');

if ($mode === 'status') {
    reply((int) ($state['status'] ?? 500), '{"success":false}');
}
if ($mode === 'redirect') {
    header('Location: http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/elsewhere');
    reply(302, '');
}
if ($authorization !== 'Bearer ' . (string) ($state['key'] ?? '')) {
    reply(401, '{"success":false,"message":"Invalid key."}');
}
if ($mode === 'huge') {
    reply(200, json_encode(['success' => true, 'data' => $state['row'] ?? [], 'pad' => str_repeat('x', 200000)]));
}

reply(200, json_encode(['success' => true, 'data' => $state['row'] ?? []]));
