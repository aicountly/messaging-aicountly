<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Server-to-server calls to the AICOUNTLY auth portal.
 *
 * The portal owns every token. This API never mints, signs or stores one — it
 * relays the browser's session-bootstrap calls and asks the portal whether a
 * ses_key is still good.
 */
final class Portal
{
    /**
     * Always my.aicountly.com, in sandbox as well as production.
     *
     * sandbox.aicountly.com serves the *login redirect* only; seskey, refresh
     * and validatesession live on my.aicountly.com for every environment.
     * See docs/auth/AICOUNTLY_AUTH_WORKFLOW.md.
     */
    private const DEFAULT_AUTH_BASE = 'https://my.aicountly.com';

    private const CONNECT_TIMEOUT_SECONDS = 8;
    private const REQUEST_TIMEOUT_SECONDS = 15;

    /**
     * validatesession runs in front of EVERY authenticated request: a portal that is up but not
     * answering must not hold each of them for 15 s (E8-ID). 2 s to connect, 6 s in all; past that
     * the answer is "unavailable" (503 + Retry-After), never a sign-out.
     */
    private const VALIDATE_CONNECT_TIMEOUT_SECONDS = 2;
    private const VALIDATE_TIMEOUT_SECONDS = 6;

    public static function base(): string
    {
        return rtrim(Env::get('PORTAL_AUTH_BASE', self::DEFAULT_AUTH_BASE), '/');
    }

    /**
     * Forward one request to the portal and return its raw answer.
     *
     * @param array<int, string> $headers
     * @return array{status: int, body: string, contentType: string}
     */
    public static function forward(string $method, string $path, array $headers, string $body, int $connectTimeout = self::CONNECT_TIMEOUT_SECONDS, int $timeout = self::REQUEST_TIMEOUT_SECONDS): array
    {
        $url = self::base() . '/api/' . ltrim($path, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 504, 'body' => '', 'contentType' => 'application/json'];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADER => false,
        ];

        // Set the body even when it is empty: a bodiless CURLOPT_CUSTOMREQUEST
        // POST goes out with no Content-Length, and the seskey call has no body.
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $failed = $response === false;
        curl_close($ch);

        if ($failed || $status === 0) {
            return ['status' => 504, 'body' => '', 'contentType' => 'application/json'];
        }

        return [
            'status' => $status,
            'body' => (string) $response,
            'contentType' => $contentType !== '' ? $contentType : 'application/json',
        ];
    }

    /**
     * Ask my.aicountly whether a Bearer ses_key is live — and say WHICH kind of "no" it is
     * (E8-ID, the fleet's tri-state as Purchases, Contacts and Appointments classify it).
     *
     *   valid        200 {status: 1, uuid_aictly, ...}: the session.
     *   invalid      the portal's own 401 (or 403), or a 200 that says status != 1: this token is
     *                not a session. Sign in again.
     *   unavailable  anything else — a 503, a bare 500, a gateway timeout, no answer, an answer
     *                that is not JSON: the portal could not say. That is not a statement about the
     *                token; answering 401 would have the SPA discard a good one. The caller answers
     *                503 + Retry-After instead. Nothing is granted either way, and nothing is cached.
     *
     * @return array{state: 'valid'|'invalid'|'unavailable', session: ?array<string, mixed>, retry_after: int}
     */
    public static function checkSesKey(string $sesKey): array
    {
        if (trim($sesKey) === '') {
            return ['state' => 'invalid', 'session' => null, 'retry_after' => 5];
        }

        $result = self::forward('POST', 'validatesession', [
            'Authorization: Bearer ' . $sesKey,
            'Content-Type: application/json',
        ], '', self::VALIDATE_CONNECT_TIMEOUT_SECONDS, self::VALIDATE_TIMEOUT_SECONDS);

        $retry = max(1, min(60, (int) ($result['retryAfter'] ?? 0) ?: 5));
        $status = (int) $result['status'];

        if (in_array($status, [401, 403], true)) {
            return ['state' => 'invalid', 'session' => null, 'retry_after' => $retry];
        }
        if ($status !== 200 || (string) $result['body'] === '') {
            return ['state' => 'unavailable', 'session' => null, 'retry_after' => $retry];
        }

        $data = json_decode((string) $result['body'], true);
        if (!is_array($data) || !array_key_exists('status', $data)) {
            return ['state' => 'unavailable', 'session' => null, 'retry_after' => $retry];
        }
        if ((int) $data['status'] !== 1) {
            return ['state' => 'invalid', 'session' => null, 'retry_after' => $retry];
        }

        return ['state' => 'valid', 'session' => $data, 'retry_after' => $retry];
    }

    /**
     * The session, or null when there is none — for a caller that does not need to tell a refusal
     * from an outage (a command-line script). Request authentication uses checkSesKey().
     *
     * @return array<string, mixed>|null
     */
    public static function validateSesKey(string $sesKey): ?array
    {
        return self::checkSesKey($sesKey)['session'];
    }
}
