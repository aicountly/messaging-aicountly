<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * The one outbound HTTP call shape the kit uses (acks, Manage's company-status). Tests and products
 * may pass their own callable with the same signature:
 *
 *   fn (string $method, string $url, list<string> $headerLines, ?string $body, int $timeout): array{status:int, body:string, error:?string}
 *
 * status 0 = no answer (error says why). Never throws.
 */
final class Http
{
    /**
     * @param list<string> $headers "Name: value" lines
     * @return array{status:int, body:string, error:?string}
     */
    public static function request(string $method, string $url, array $headers, ?string $body, int $timeout = 15): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return ['status' => 0, 'body' => '', 'error' => 'not an http(s) URL'];
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $text = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $text === false ? curl_error($ch) : null;
            curl_close($ch);

            return ['status' => $text === false ? 0 : $status, 'body' => is_string($text) ? $text : '', 'error' => $error];
        }

        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body ?? '',
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $text = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => is_string($text) ? $text : '', 'error' => $text === false ? 'request failed' : null];
    }
}
