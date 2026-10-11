<?php

declare(strict_types=1);

/**
 * E8-ID / MY-AW-04: my.aicountly being down is not "sign in again".
 *
 * Over REAL HTTP through this product's own front controller (index.php under `php -S`):
 * PORTAL_AUTH_BASE points at a fault listener standing in for a broken portal or the proxy in
 * front of it. Only refusal shapes and failures are produced — the portal's own 401
 * {status:0} (my-aicountly-com AuthController::unauthenticated) and outages; no positive answer
 * is invented.
 *
 *   portal 401 {status:0}                       -> 401
 *   portal 502 / 503 / HTML 200 / empty 200 /
 *   timeout                                     -> 503 + Retry-After, within the 6 s bound
 *
 *   php server-php/tests/e8id_portal_outage.php
 */

$root = dirname(__DIR__);
$apiPort = (int) (getenv('E8ID_API_PORT') ?: 33981);
$faultPort = (int) (getenv('E8ID_PORTAL_FAULT_PORT') ?: 33982);
$route = getenv('E8ID_SESSION_ROUTE') ?: 'session';
$modeFile = sys_get_temp_dir() . '/e8id_portal_mode_' . getmypid();
file_put_contents($modeFile, 'http502');
$router = sys_get_temp_dir() . '/e8id_portal_router_' . getmypid() . '.php';
file_put_contents($router, '<?php
$mode = trim((string) @file_get_contents(' . var_export($modeFile, true) . '));
switch ($mode) {
    case "refused401": http_response_code(401); header("Content-Type: application/json"); echo "{\"status\":0,\"error\":\"unauthenticated\",\"code\":\"session_invalid\",\"retryable\":false,\"message\":\"Invalid or expired session\"}"; break;
    case "http503": http_response_code(503); header("Content-Type: application/json"); header("Retry-After: 7"); echo "{\"status\":0,\"error\":\"auth_unavailable\",\"retryable\":true}"; break;
    case "html200": http_response_code(200); header("Content-Type: text/html"); echo "<html>maintenance</html>"; break;
    case "empty200": http_response_code(200); break;
    case "timeout": sleep(30); http_response_code(504); break;
    default: http_response_code(502); header("Content-Type: text/html"); echo "<html>Bad gateway</html>";
}
');

$env = getenv() + ['PORTAL_AUTH_BASE' => 'http://127.0.0.1:' . $faultPort, 'PHP_CLI_SERVER_WORKERS' => '2'];
$env['PORTAL_AUTH_BASE'] = 'http://127.0.0.1:' . $faultPort;
$procs = [];
$procs[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $faultPort, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p1, null, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
$procs[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $apiPort, '-t', $root, $root . '/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2, $root, $env);
foreach ([$faultPort, $apiPort] as $port) {
    for ($i = 0; $i < 60; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
        if ($fp) {
            fclose($fp);
            break;
        }
        usleep(100000);
    }
}

$passed = 0;
$failed = 0;
$probe = static function () use ($apiPort, $route): array {
    $retry = null;
    $ch = curl_init('http://127.0.0.1:' . $apiPort . '/' . $route);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer e8id-probe-session', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$retry): int {
            if (stripos($line, 'retry-after:') === 0) {
                $retry = trim(substr($line, 12));
            }

            return strlen($line);
        },
    ]);
    $t = microtime(true);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'retry' => $retry, 'ms' => (int) ((microtime(true) - $t) * 1000), 'raw' => substr((string) $raw, 0, 200)];
};
$check = static function (string $name, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : "\n        " . $detail) . "\n";
    $ok ? $passed++ : $failed++;
};

try {
    file_put_contents($modeFile, 'refused401');
    $r = $probe();
    $check('the portal\'s own 401 refusal -> 401, sign in again', $r['status'] === 401, json_encode($r));

    foreach (['http502', 'http503', 'html200', 'empty200', 'timeout'] as $mode) {
        file_put_contents($modeFile, $mode);
        $r = $probe();
        $check("the portal cannot answer ({$mode}) -> 503 + Retry-After, never 401", $r['status'] === 503 && $r['retry'] !== null && (int) $r['retry'] >= 1, json_encode($r));
        if ($mode === 'timeout') {
            $check('a hanging portal holds the request at most ~6 s (was 15 s)', $r['ms'] < 9000, $r['ms'] . ' ms');
        }
    }
} finally {
    foreach ($procs as $p) {
        proc_terminate($p);
        proc_close($p);
    }
    @unlink($modeFile);
    @unlink($router);
}

echo "\n" . str_repeat('-', 60) . "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
