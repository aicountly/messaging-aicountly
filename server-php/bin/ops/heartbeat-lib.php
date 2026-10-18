<?php

declare(strict_types=1);

/**
 * AICOUNTLY ops kit — report a scheduled run to Console's Cron Job Monitor (E8-OPS: F160, F162, F262).
 *
 * CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/heartbeat-lib.php (identical in every product
 * at server-php/bin/ops/heartbeat-lib.php). Same protocol as Inventory's CronHeartbeat:
 *   POST {CONSOLE_API_BASE}/portal/cron-heartbeat   X-Console-Cron-Key: <key>
 *   {monitor, run_id, outcome: started|ok|error, started_at, finished_at, message, counts}
 * The monitor code must be registered in Console (console-react-app migration 059_e8op_product_cron_monitors).
 *
 * The key is CONSOLE_CRON_MONITOR_KEY, else CONSOLE_SERVICE_KEY (both accepted by Console's CronMonitorKey).
 * UNCONFIGURED MEANS INERT: no key, no HTTP — a laptop must never hold production's monitor green.
 * NEVER BREAKS THE JOB: every failure is swallowed (logged once), 5 s budget per POST.
 *
 * SENT WITH cURL. This used PHP's URL stream wrapper (file_get_contents), which needs
 * allow_url_fopen; production's PHP has it off, so every heartbeat failed with "HTTP 0" and the
 * monitors sat OVERDUE while the jobs ran. The stream wrapper is only the fallback for a PHP
 * built without cURL.
 */

/**
 * @param callable(string):string $env reads one setting from the product's .env
 * @param array<string, scalar> $counts counts only — never a company id, name or payload
 */
function aic_ops_heartbeat(callable $env, string $monitor, string $runId, string $outcome, ?string $startedAt, ?string $finishedAt, string $message = '', array $counts = []): bool
{
    try {
        $key = trim($env('CONSOLE_CRON_MONITOR_KEY'));
        if ($key === '') {
            $key = trim($env('CONSOLE_SERVICE_KEY'));
        }
        if ($key === '') {
            return false;
        }
        $base = rtrim(trim($env('CONSOLE_API_BASE')) ?: 'https://console.aicountly.org/api', '/');
        $body = array_filter([
            'monitor'     => $monitor,
            'run_id'      => substr($runId, 0, 120),
            'outcome'     => $outcome,
            'started_at'  => $startedAt,
            'finished_at' => $finishedAt,
            'message'     => $message === '' ? null : substr($message, 0, 2000),
            'counts'      => $counts === [] ? null : array_slice($counts, 0, 50, true),
        ], static fn ($v) => $v !== null);
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $url = $base . '/portal/cron-heartbeat';
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Console-Cron-Key: ' . $key];
        $resp = false;
        $status = 0;
        $why = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                $options = [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $json,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_NOSIGNAL       => true,
                    CURLOPT_FOLLOWLOCATION => false,
                ];
                if (defined('CURLOPT_PROTOCOLS_STR')) {
                    $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
                } else {
                    $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                }
                curl_setopt_array($ch, $options);
                $resp = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($status === 0) {
                    $why = ', curl error ' . curl_errno($ch);
                }
                curl_close($ch);
            }
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers) . "\r\n",
                'content'       => $json,
                'timeout'       => 5,
                'ignore_errors' => true,
            ]]);
            $resp = @file_get_contents($url, false, $ctx);
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                    $status = (int) $m[1];
                }
            }
            if ($status === 0) {
                $why = ini_get('allow_url_fopen') ? ', no answer' : ', no cURL and allow_url_fopen is off';
            }
        }
        if ($status < 200 || $status >= 300) {
            error_log('[heartbeat] Console did not accept ' . $monitor . ' ' . $outcome . ' (HTTP ' . $status . $why . ')');

            return false;
        }

        return $resp !== false;
    } catch (\Throwable $e) {
        error_log('[heartbeat] ' . $monitor . ': ' . get_class($e));

        return false;
    }
}

function aic_ops_run_id(string $monitor): string
{
    return $monitor . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
}
