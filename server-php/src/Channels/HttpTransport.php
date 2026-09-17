<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

/**
 * The HTTP call an adapter makes to its provider.
 *
 * In one place because the timeout policy is the same for all of them and
 * because of the one case that matters: a request that times out AFTER the
 * bytes went out. cURL cannot tell us whether the provider processed it, so
 * this returns a distinct `timeout` outcome and the adapter turns that into
 * SendResult::unknown() rather than a failure. Adapters that used curl
 * directly would each have to remember that, and one of them would not.
 *
 * SSRF: the URL is built by the adapter from its own constant base plus
 * identifiers. No adapter ever fetches a URL that came from a request, a
 * database row or a webhook payload — see src/Channels/README notes in
 * docs/INTEGRATIONS.md.
 */
final class HttpTransport
{
    private const CONNECT_TIMEOUT = 4;
    private const TOTAL_TIMEOUT = 20;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     * @return array{outcome:string, status:int, body:?array, raw:string, error:string}
     *         outcome: ok | http_error | timeout | transport_error
     */
    public static function send(string $method, string $url, array $headers = [], array|string|null $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['outcome' => 'transport_error', 'status' => 0, 'body' => null, 'raw' => '', 'error' => 'curl_init_failed'];
        }

        $wire = ['Accept: application/json', 'Expect:'];
        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $wire[] = $name . ': ' . $value;
            }
        }

        $payload = null;
        if ($body !== null) {
            if (is_array($body)) {
                $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $wire[] = 'Content-Type: application/json';
            } else {
                $payload = $body;
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $wire,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            // Providers do not redirect their API endpoints, and following one
            // would replay a credential to wherever the redirect pointed.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ] + ($payload !== null ? [CURLOPT_POSTFIELDS => $payload] : []));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // THE CASE THIS CLASS EXISTS FOR. The request went out and we never
        // heard back — the provider may well have accepted it.
        if (in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_RECV_ERROR, CURLE_PARTIAL_FILE], true)) {
            return ['outcome' => 'timeout', 'status' => 0, 'body' => null, 'raw' => '', 'error' => $error];
        }

        if ($raw === false || $status === 0) {
            return ['outcome' => 'transport_error', 'status' => $status, 'body' => null, 'raw' => '', 'error' => $error];
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'outcome' => $status >= 200 && $status < 300 ? 'ok' : 'http_error',
            'status'  => $status,
            'body'    => is_array($decoded) ? $decoded : null,
            'raw'     => (string) $raw,
            'error'   => '',
        ];
    }
}
