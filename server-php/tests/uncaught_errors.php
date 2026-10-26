<?php

declare(strict_types=1);

/**
 * No blank errors, and the atomic-deploy entry guard, over REAL HTTP through index.php (`php -S`).
 *
 *   - an uncaught Throwable on any path answers JSON 500: a human message, code server_error and a
 *     reference — the X-Request-Id the caller sent when it is a plain token, else a generated
 *     32-hex id — in the body and the X-Request-Id header; the exception text is not in the body;
 *     the server log line carries the same reference, the exception class, file:line, method, path
 *   - a fatal error (not a Throwable) answers the same way instead of an empty 500
 *   - every JSON answer carries X-Request-Id
 *   - the guard (bin/ops/atomic-entry.php.txt) hands a request that opened the PREVIOUS release's
 *     index.php to the current release's copy, and that answers 200: index.php declares no
 *     functions of its own, so nothing is declared twice ("Cannot redeclare" = an empty 500)
 *
 *   php server-php/tests/uncaught_errors.php
 */

$root = dirname(__DIR__);
$product = 'messaging';
// The code index.php's global catch answers an uncaught error with.
$internalCode = 'server_error';
// A function src/front.php declares: the "fatal" fault declares it first, so loading front.php dies.
preg_match('/^function\s+(\w+)/m', (string) file_get_contents($root . '/src/front.php'), $fn);
$frontFunction = $fn[1] ?? 'send_json';
$port = (int) (getenv('UNCAUGHT_API_PORT') ?: 33991);
$work = sys_get_temp_dir() . '/' . $product . '_uncaught_' . getmypid();
$doc = $work . '/public_html/' . $product . '.example';
$releases = $work . '/aicountly-releases/' . $product . '.example-api/releases';

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  ok    {$name}\n";

        return;
    }
    $failed++;
    echo "  FAIL  {$name}" . ($detail !== '' ? "\n        {$detail}" : '') . "\n";
};

// Two releases of this very API (tests excluded, as the deploy ships it), each with its own .env,
// and api/ a symlink to release B — the layout atomic-switch.sh leaves.
foreach (['a', 'b'] as $name) {
    $dest = $releases . '/' . $name;
    @mkdir($dest, 0777, true);
    exec('rsync -a --exclude=/tests --exclude=/.env --exclude=/error_log ' . escapeshellarg($root . '/') . ' ' . escapeshellarg($dest . '/'), $o, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "rsync failed\n");
        exit(1);
    }
    file_put_contents($dest . '/.env', "APP_ENV=sandbox\n");
    // Test copies only: which release's code ran (src/front.php is loaded by index.php right after
    // the guard), and a fault the router can switch on that throws INSIDE index.php's global try
    // (src/Router.php is autoloaded there, for any path the router serves).
    file_put_contents($dest . '/src/front.php', "\nif (PHP_SAPI !== 'cli' && !headers_sent()) { header('X-Test-Release: {$name}'); }\n", FILE_APPEND);
    $routerFile = (string) file_get_contents($dest . '/src/Router.php');
    $routerFile = preg_replace('/^namespace Aicountly\\\\Api;$/m', "namespace Aicountly\\Api;\n\nif ((\$_SERVER['HTTP_X_TEST_FAULT'] ?? '') === 'throw') {\n    throw new \\RuntimeException('injected test fault in Router.php');\n}", $routerFile, 1, $replaced);
    if ($replaced !== 1) {
        fwrite(STDERR, "could not inject the fault into src/Router.php\n");
        exit(1);
    }
    file_put_contents($dest . '/src/Router.php', $routerFile);
}
@mkdir($doc, 0777, true);
symlink($releases . '/b', $doc . '/api');

// As Apache/LiteSpeed run it: SCRIPT_FILENAME is the path through the symlink. X-Test-Open says
// which file PHP actually opened — "previous" is a worker whose realpath cache still holds release
// A; X-Test-Fault makes index.php fail in a way the request itself causes.
$router = $work . '/router.php';
file_put_contents($router, '<?php
$path = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (strpos($path, "/api/") !== 0) { http_response_code(404); return true; }
$_SERVER["SCRIPT_FILENAME"] = $_SERVER["DOCUMENT_ROOT"] . "/api/index.php";
$_SERVER["SCRIPT_NAME"] = "/api/index.php";
$open = ($_SERVER["HTTP_X_TEST_OPEN"] ?? "") === "previous" ? ' . var_export($releases . '/a/index.php', true) . ' : $_SERVER["SCRIPT_FILENAME"];
$fault = $_SERVER["HTTP_X_TEST_FAULT"] ?? "";
if ($fault === "fatal") { eval("namespace Aicountly\\\\Api; function ' . $frontFunction . '() {}"); }
chdir(dirname($open));
require $open;
');

$log = $work . '/server.log';
// Each release's .env names it (APP_ENV=release-a|b); a real APP_ENV in the environment would win over both.
$serverEnv = getenv();
unset($serverEnv['APP_ENV']);
$proc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', '127.0.0.1:' . $port, '-t', $doc, $router],
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    $doc,
    ['PHP_CLI_SERVER_WORKERS' => '2'] + $serverEnv,
);
for ($i = 0; $i < 60; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if ($fp) {
        fclose($fp);
        break;
    }
    usleep(100000);
}

$request = static function (string $method, string $path, array $headers = []) use ($port): array {
    $responseHeaders = [];
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => $responseHeaders, 'raw' => $raw, 'json' => json_decode($raw, true)];
};

echo "No blank errors and the atomic entry guard (real HTTP through index.php)\n";

try {
    // ---- the guard
    $direct = $request('GET', '/api/health');
    $check('the current release answers /api/health', in_array($direct['status'], [200, 503], true) && is_array($direct['json']) && ($direct['headers']['x-test-release'] ?? '') === 'b', $direct['status'] . ' ' . substr($direct['raw'], 0, 300));

    $r = $request('GET', '/api/health', ['X-Test-Open: previous']);
    $check('a request that opened the PREVIOUS release\'s index.php is answered by the current one, as a direct request is', $r['status'] === $direct['status'] && is_array($r['json']) && ($r['headers']['x-test-release'] ?? '') === 'b', $r['status'] . ' ' . substr($r['raw'], 0, 300));

    $r = $request('POST', '/api/v1/no-such-route', ['X-Test-Open: previous', 'Content-Type: application/json']);
    $check('...and so is any other path (a JSON 4xx from the current release, never a 500)', $r['status'] >= 400 && $r['status'] < 500 && is_array($r['json']) && ($r['headers']['x-test-release'] ?? '') === 'b', $r['status'] . ' ' . substr($r['raw'], 0, 300));

    $source = (string) file_get_contents($root . '/index.php');
    $declares = preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:function|class|interface|trait|enum)\s+[A-Za-z_]/mi', $source) === 1;
    $check('index.php declares no top-level function, class, interface, trait or enum', !$declares);

    // ---- request ids
    $r = $request('GET', '/api/v1/no-such-route', ['X-Request-Id: spa-ref-123456']);
    $check('a JSON answer carries the caller\'s X-Request-Id back', ($r['headers']['x-request-id'] ?? '') === 'spa-ref-123456', json_encode($r['headers']));

    $r = $request('GET', '/api/health', ['X-Request-Id: bad id <script>']);
    $check('an id that is not a plain token is replaced by a generated 32-hex id', preg_match('/^[0-9a-f]{32}$/', $r['headers']['x-request-id'] ?? '') === 1, json_encode($r['headers']));

    // ---- an uncaught Throwable
    $r = $request('GET', '/api/v1/no-such-route', ['X-Test-Fault: throw', 'X-Request-Id: spa-ref-throw01']);
    $body = $r['json'];
    $check('an uncaught error answers JSON 500', $r['status'] === 500 && is_array($body), $r['status'] . ' ' . substr($r['raw'], 0, 300));
    $check('...with code server_error and a human message that quotes the reference', ($body['error']['code'] ?? '') === $internalCode && str_contains((string) ($body['message'] ?? ''), 'spa-ref-throw01') && ($body['error']['message'] ?? '') === ($body['message'] ?? null), $r['raw']);
    $check('...and the reference in the body and the X-Request-Id header', ($body['request_id'] ?? '') === 'spa-ref-throw01' && ($body['error']['request_id'] ?? '') === 'spa-ref-throw01' && ($r['headers']['x-request-id'] ?? '') === 'spa-ref-throw01', $r['raw']);
    $check('...never the exception text', !str_contains($r['raw'], 'injected') && !str_contains($r['raw'], 'Router.php') && !str_contains($r['raw'], 'RuntimeException'), $r['raw']);
    usleep(200000);
    $logText = (string) file_get_contents($log);
    $line = '';
    foreach (explode("\n", $logText) as $l) {
        if (str_contains($l, 'spa-ref-throw01')) {
            $line = $l;
        }
    }
    $check('the log line carries the reference, class, file:line, method and path', $line !== ''
        && str_contains($line, '[' . $product . '] uncaught RuntimeException')
        && str_contains($line, 'injected test fault')
        && preg_match('#/src/Router\.php:\d+#', $line) === 1
        && str_contains($line, 'GET /api/v1/no-such-route'), $line !== '' ? $line : substr($logText, -600));

    $r = $request('POST', '/api/v1/no-such-route', ['X-Test-Fault: throw', 'X-Request-Id: x', 'Content-Type: application/json']);
    $rid = (string) ($r['json']['request_id'] ?? '');
    $check('an uncaught error with no usable id gets a generated 32-hex reference', $r['status'] === 500 && preg_match('/^[0-9a-f]{32}$/', $rid) === 1 && ($r['headers']['x-request-id'] ?? '') === $rid, $r['raw']);

    // ---- a fatal error
    $r = $request('GET', '/api/health', ['X-Test-Fault: fatal', 'X-Request-Id: spa-ref-fatal01']);
    $check('a fatal error answers JSON 500 with the reference, not an empty body', $r['status'] === 500 && ($r['json']['request_id'] ?? '') === 'spa-ref-fatal01' && ($r['json']['error']['code'] ?? '') === 'server_error' && !str_contains($r['raw'], 'redeclare'), $r['status'] . ' ' . substr($r['raw'], 0, 300));
    usleep(200000);
    $check('...and its log line carries the reference', preg_match('/\[' . $product . '\] fatal \[spa-ref-fatal01\] GET \/api\/health: .*redeclare/i', (string) file_get_contents($log)) === 1, substr((string) file_get_contents($log), -600));
} finally {
    proc_terminate($proc);
    proc_close($proc);
    exec('rm -rf ' . escapeshellarg($work));
}

echo "\n" . str_repeat('-', 60) . "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
