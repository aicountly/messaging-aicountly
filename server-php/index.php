<?php

declare(strict_types=1);

/**
 * Messaging API — front controller.
 *
 * Deployed to <document root>/api, so it is same-origin with the React app on
 * both messaging.aicountly.com and messaging.gh.aicountly.com.
 *
 * Routes:
 *   GET  /api/health              liveness, database readiness, channel and integration state
 *   POST /api/global/{path}       allow-listed relay to the portal auth API
 *   *    /api/manage/v1/{path}    allow-listed relay to Manage's company registry (src/ManageRelay.php)
 *   GET  /api/session             who the caller is, per the portal
 *   *    /api/webhooks/...        provider callbacks — NO session, signature verified
 *   *    /api/v1/...              the Messaging API proper — see src/Routes.php
 */

namespace Aicountly\Api;

// Atomic deploys (bin/ops/atomic-switch.sh): api/ is a symlink to the current release. This guard,
// pasted from bin/ops/atomic-entry.php.txt, makes every request run the current release's copy of
// this file. It runs before anything is declared, which is why this file declares no functions or
// classes of its own (src/front.php says why): a second copy would redeclare them and die.
// >>> aicountly atomic entry (bin/ops/atomic-entry.php.txt) >>>
if (PHP_SAPI !== 'cli' && !defined('AICOUNTLY_ATOMIC_ENTRY') && isset($_SERVER['SCRIPT_FILENAME'])) {
    define('AICOUNTLY_ATOMIC_ENTRY', true);
    clearstatcache(true);
    $aicountlyEntry = realpath((string) $_SERVER['SCRIPT_FILENAME']);
    if ($aicountlyEntry !== false && $aicountlyEntry !== __FILE__ && is_file($aicountlyEntry)) {
        return require $aicountlyEntry;
    }
    unset($aicountlyEntry);
}
// <<< aicountly atomic entry <<<

require __DIR__ . '/src/Env.php';
require __DIR__ . '/src/Autoload.php';
require __DIR__ . '/src/Portal.php';

// A fatal error (not a Throwable: a failed require, memory exhausted) answers JSON with a reference
// instead of an empty 500, and the log line carries the same reference (Http::answerFatal).
register_shutdown_function([Http::class, 'answerFatal']);

require __DIR__ . '/src/front.php';

Env::load(__DIR__ . '/.env');

/**
 * Portal paths this API relays for the browser.
 *
 * The relay exists so the SPA never makes a cross-origin call to the portal:
 * a new product domain is not in the portal's CORS allowlist on day one.
 *
 * It is an allowlist and must stay one. Forwarding arbitrary paths would turn
 * this host into an open proxy for the portal's whole auth surface — login,
 * signup, OTP, user lookups — with the portal seeing this server's IP instead
 * of the caller's, so anything it rate-limits per IP could be driven through
 * here instead.
 *
 * Every relayed call also names the visitor in X-Aicountly-Client-Ip (this connection's
 * REMOTE_ADDR, never a browser-supplied header), because all of them now reach the portal from
 * this server and its per-IP sign-in throttles would otherwise be shared by every user.
 */
const RELAYED_PATHS = [
    'seskey',
    'seskey/refresh',
    'refresh_authtoken',
    // Sign-out. The browser used to POST my.aicountly.com/api/logout itself; from networks the
    // hosting's bot shield distrusts that cross-site call got a challenge page with no CORS
    // headers and failed, leaving the portal token alive. Same-origin through here, it passes.
    'logout',
];

// ---------------------------------------------------------------------------
// Routing
//
// One try around ALL of it, the relays included: whatever throws, on any path, is answered as JSON
// with a reference the log line carries too (Http::uncaught), never as PHP's empty 500. (The body
// is not indented, so the diff stays readable.)
// ---------------------------------------------------------------------------

try {
apply_cors();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// The environment is configuration (Environment); the Host header only gets
// to make us REFUSE, never to choose. A sandbox name on a production server is
// a probe or a mis-copied .env, and either way nothing here should answer it.
if (Environment::hostContradicts((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
    send_json(503, ['error' => ['code' => 'environment_mismatch', 'message' => 'This server is not configured for the host it was asked for.'],
        'message' => 'This server is not configured for the host it was asked for.']);
}

$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// Strip the directory this front controller is mounted under, so the same file
// works at <docroot>/api and at the root of a dedicated API vhost.
$mountPoint = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
if ($mountPoint !== '' && $mountPoint !== '/' && strpos($uri, $mountPoint) === 0) {
    $uri = substr($uri, strlen($mountPoint));
}

$path = normalise_path($uri);

if ($path === '' || $path === 'health') {
    // Liveness AND readiness. 'status' stays ok whenever PHP is serving, so an
    // uptime monitor pointed here keeps behaving as it always has; the database
    // and integration blocks are what tell you whether the app can actually be
    // used. Reporting only the former is how a deploy goes green on an app
    // whose every real endpoint answers 503.
    $database = Health::database();

    send_json(200, [
        'status'   => 'ok',
        'app'      => 'Messaging',
        // From configuration (Environment), never this request's Host.
        'env'      => Environment::current() ?? 'not_configured',
        'time'     => gmdate('c'),
        'database' => $database,
        // Booleans and counts only. This endpoint is public: no sender
        // addresses (they identify the tenant) and certainly no credentials.
        'integrations' => Health::integrations(),
        'channels'     => Health::channels(),
        // One field to read when something is wrong. A channel is NOT in it:
        // Messaging with no channel connected is up, usable, and honest about
        // not being able to send — which is a configuration state, not an
        // outage.
        'usable'   => $database['reachable'] && ($database['schema']['ready'] ?? false),
    ]);
}

if (strpos($path, 'global/') === 0) {
    $portalPath = substr($path, strlen('global/'));

    if (!in_array($portalPath, RELAYED_PATHS, true)) {
        send_json(404, ['message' => 'This path is not relayed. Call the portal API directly.']);
    }

    $headers = [];
    $authorization = authorization_header();
    if ($authorization !== '') {
        $headers[] = 'Authorization: ' . $authorization;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (is_string($contentType) && $contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    // Only the headers above are forwarded, so a browser-sent X-Aicountly-Client-Ip, X-Forwarded-For,
    // X-Real-IP, Forwarded or Client-IP never reaches the portal; the visitor is the address this
    // server's own connection sees (see RELAYED_PATHS).
    $visitor = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($visitor, FILTER_VALIDATE_IP) !== false) {
        $headers[] = 'X-Aicountly-Client-Ip: ' . $visitor;
    }

    $body = (string) file_get_contents('php://input');
    $result = Portal::forward($method, $portalPath, $headers, $body);

    if ($result['status'] === 504) {
        send_json(504, ['message' => 'Auth service unavailable — please retry.']);
    }

    http_response_code($result['status']);
    header('Content-Type: ' . $result['contentType']);
    header('Cache-Control: no-store');
    echo $result['body'];
    exit;
}

// Manage's company registry (Users & Access, My workspaces, the company picker), same-origin for
// the same reason as the portal relay above: a cross-site call to manage.aicountly.com can be
// answered by the hosting's bot shield with a challenge page that has no CORS headers, and the
// browser then sees no response at all. ManageRelay forwards only the calls the shared client
// makes; the raw (still encoded) path is passed so each segment is decoded and checked once.
if ($path === 'manage' || strpos($path, 'manage/') === 0) {
    ManageRelay::serve($method, $uri, (string) ($_SERVER['QUERY_STRING'] ?? ''), [
        'Authorization'    => authorization_header(),
        'Content-Type'     => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        'Accept'           => (string) ($_SERVER['HTTP_ACCEPT'] ?? ''),
        'Idempotency-Key'  => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''),
        'X-Correlation-Id' => (string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''),
    ], (string) file_get_contents('php://input'));
}

if ($path === 'session') {
    $sesKey = bearer_token();
    if ($sesKey === '') {
        send_json(401, ['message' => 'Missing bearer session key.']);
    }

    $checked = Portal::checkSesKey($sesKey);
    if ($checked['state'] === 'unavailable') {
        // The portal could not say. Not a sign-out: 503 + Retry-After (E8-ID / MY-AW-04).
        header('Retry-After: ' . max(1, (int) $checked['retry_after']));
        send_json(503, [
            'error'   => ['code' => 'auth_unavailable', 'message' => 'Sign-in could not be checked right now. Please retry in a moment.'],
            'message' => 'Sign-in could not be checked right now. Please retry in a moment.',
        ]);
    }
    $session = $checked['session'];
    if ($session === null) {
        send_json(401, ['message' => 'Invalid or expired session.']);
    }

    send_json(200, [
        'authenticated' => true,
        'uuid' => $session['uuid_aictly'] ?? ($session['uuid'] ?? ''),
    ]);
}

// ---------------------------------------------------------------------------
// The Messaging API
//
// Everything above this line is the auth bootstrap and predates the product.
// Everything below is the product, and it all goes through one router so that
// authentication, company scope and the tenant check happen in one place rather
// than being remembered per endpoint.
// ---------------------------------------------------------------------------

$router = new Router();
Routes::register($router);

if ($router->dispatch($method, $path)) {
    exit;
}

send_json(404, ['message' => 'Not found.']);
} catch (\PDOException $e) {
    // A database problem is ours, not the caller's. The detail goes to the log, with the
    // reference; the caller gets something they can act on.
    Http::uncaught($e, 503, 'database_unavailable', 'The Messaging database is not reachable right now. Please retry.', [
        'retryable' => true,
    ]);
} catch (ResponseSent $e) {
    // Only reachable under CLI, where Http throws instead of exiting.
    send_json($e->status, $e->payload);
} catch (\Throwable $e) {
    Http::uncaught($e);
}
