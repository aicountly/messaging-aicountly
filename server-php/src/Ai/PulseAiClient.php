<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Env;

/**
 * Messaging's client for the AI Pulse gateway.
 *
 * Adapted from Pulse's reference client (pulse-aicountly:
 * docs/ai-gateway/PulseAiClient.php; the contract is docs/AI_GATEWAY.md there).
 * Only the calls Messaging makes are kept: generate (text and JSON) and status.
 * Messaging sends no files, images, voice or embeddings to a model.
 *
 * Pulse picks the model (the one Console binds to Pulse), enforces budgets,
 * validates JSON and reports usage per product and feature. Messaging holds no
 * model key and calls no model provider. The product's own rules — grounding,
 * untrusted-data labelling, the closed vocabularies, the checks on what comes
 * back, the run log — live in AiClient, which is the only caller of this class.
 *
 * Who is calling:
 *   - a signed-in user started the action: their ses_key (the Bearer this API
 *     received) goes to Pulse as Bearer, and Pulse checks the person and the
 *     company itself;
 *   - nobody (a sibling product calling with its service key): the estate
 *     service key goes as X-Pulse-Service-Key — PULSE_SERVICE_KEY, falling back
 *     to CONSOLE_SERVICE_KEY.
 *
 * Configuration (server-php/.env):
 *   PULSE_API_ORIGIN   https://pulse.aicountly.com (sandbox https://pulse.gh.aicountly.com).
 *                      Unset: production Pulse for AIC_ENVIRONMENT (else APP_ENV)
 *                      production, the sandbox for sandbox/local — from configuration
 *                      only, never the request's Host and never a guess for a CLI
 *                      with no Host (X-09). Unconfigured: no call. A trailing /api
 *                      is ignored.
 *   PULSE_SERVICE_KEY  only for calls with no signed-in user.
 *
 * Never throws, never logs content. Every method returns
 *   ['ok' => bool, 'status' => int, 'code' => ?string, 'message' => ?string, 'retryable' => bool, 'data' => ?array]
 * where data is the gateway's `data` (id, text, json, tool_calls, stop_reason, model,
 * provider, tier, usage, …; for status(): enabled, available, reason, tiers, limits).
 */
final class PulseAiClient
{
    /** X-Pulse-Product. */
    public const PRODUCT = 'messaging';

    public const PRODUCTION = 'https://pulse.aicountly.com';
    public const SANDBOX    = 'https://pulse.gh.aicountly.com';

    /** @var \Closure(string, string, list<string>, ?string, float, float): array{status: int, body: ?string, error: ?string} */
    private \Closure $transport;

    /**
     * @param callable|null $transport how a request goes out; curl unless a test passes a fake:
     *     fn (string $method, string $url, list<string> $headers, ?string $body, float $timeoutSeconds, float $connectTimeoutSeconds)
     *         => ['status' => int, 'body' => ?string, 'error' => null | 'timeout' | 'unreachable']
     */
    public function __construct(
        private string $product = self::PRODUCT,
        private ?string $origin = null,
        // Messaging's AI is interactive — somebody clicked "Draft a reply", or
        // the Command Centre is rendering — so it is bounded the way the
        // product always bounded its model calls: a panel that says the
        // assistant is not responding, never a request left hanging.
        private float $timeoutSeconds = 20.0,
        private float $connectTimeoutSeconds = 4.0,
        ?callable $transport = null,
    ) {
        $this->transport = $transport !== null ? \Closure::fromCallable($transport) : self::curl(...);
    }

    /**
     * One call. Pass the user's ses_key whenever a user is behind the request;
     * leave it null only when nobody is (then the service key is used).
     *
     * @param array<string,mixed> $request gateway body: feature, system, input|messages,
     *                                     response_format, tier, max_output_tokens, cmp_id,
     *                                     bo_id, cache_ttl_seconds, actor_uuid
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    public function generate(array $request, ?string $userSesKey = null, ?string $idempotencyKey = null): array
    {
        $res = $this->post('/api/ai/v1/generate', $request, $userSesKey, $idempotencyKey);
        if ($res['ok'] && ($res['data']['stop_reason'] ?? '') === 'refused') {
            return self::result(false, $res['status'], 'refused', 'The model declined this request.', false, $res['data']);
        }

        return $res;
    }

    /**
     * JSON against a schema — Messaging's closed-vocabulary classification.
     *
     * @param string|array<string,mixed> $input
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $options any other gateway fields (cmp_id, tier, …)
     */
    public function json(string $feature, string $system, $input, array $schema, array $options = [], ?string $userSesKey = null, ?string $idempotencyKey = null): array
    {
        return $this->generate(['feature' => $feature, 'system' => $system, 'input' => $input, 'response_format' => ['type' => 'json', 'schema' => $schema]] + $options, $userSesKey, $idempotencyKey);
    }

    /** @param array<string,mixed> $options any other gateway fields (cmp_id, tier, max_output_tokens, …) */
    public function text(string $feature, string $system, string $input, array $options = [], ?string $userSesKey = null): array
    {
        return $this->generate(['feature' => $feature, 'system' => $system, 'input' => $input] + $options, $userSesKey);
    }

    /**
     * Is AI available to Messaging right now (for a status line)?
     *
     * Short timeouts: this is asked while a screen is rendering.
     */
    public function status(?string $userSesKey = null): array
    {
        $caller = $this->callerHeader($userSesKey);
        if ($caller === null) {
            return self::result(false, 0, 'not_configured', 'No user session and no PULSE_SERVICE_KEY to ask AI Pulse with.', false);
        }
        $headers = ['Accept: application/json', 'X-Pulse-Product: ' . $this->product, $caller];
        if ($this->origin() === '') {
            return self::result(false, 0, 'not_configured', \Aicountly\Api\Environment::explainUnconfigured(), false);
        }
        $res = ($this->transport)('GET', $this->origin() . '/api/ai/v1/status', $headers, null, 4.0, 2.0);
        if ($res['error'] !== null) {
            return self::result(false, 0, $res['error'] === 'timeout' ? 'timeout' : 'pulse_unreachable', 'AI Pulse did not answer.', true);
        }
        $json = json_decode((string) $res['body'], true);

        return is_array($json) && ($json['status'] ?? 0) === 1
            ? self::result(true, $res['status'], null, null, false, is_array($json['data'] ?? null) ? $json['data'] : null)
            : self::result(false, $res['status'], (string) ($json['code'] ?? 'bad_response'), (string) ($json['message'] ?? "AI Pulse answered HTTP {$res['status']}."), (bool) ($json['retryable'] ?? $res['status'] >= 500));
    }

    /** Pulse's origin for the host this API is served from. */
    public function origin(): string
    {
        $origin = $this->origin !== null && trim($this->origin) !== '' ? trim($this->origin) : self::env('PULSE_API_ORIGIN');
        if ($origin === '') {
            $origin = self::defaultOrigin();
        }
        if ($origin === '') {
            return '';
        }
        $origin = rtrim($origin, '/');

        return preg_replace('#/api$#i', '', $origin) ?? $origin;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    private function post(string $path, array $body, ?string $userSesKey, ?string $idempotencyKey): array
    {
        $caller = $this->callerHeader($userSesKey);
        if ($caller === null) {
            return self::result(false, 0, 'not_configured', 'No user session and no PULSE_SERVICE_KEY for an AI call with no user.', false);
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Pulse-Product: ' . $this->product, $caller];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return self::result(false, 0, 'invalid_request', 'The request could not be encoded as JSON.', false);
        }

        if ($this->origin() === '') {
            return self::result(false, 0, 'not_configured', \Aicountly\Api\Environment::explainUnconfigured(), false);
        }
        $res = ($this->transport)('POST', $this->origin() . $path, $headers, $encoded, $this->timeoutSeconds, $this->connectTimeoutSeconds);
        if ($res['error'] !== null) {
            // The outcome is unknown: retry only with the same Idempotency-Key.
            return self::result(false, 0, $res['error'] === 'timeout' ? 'timeout' : 'pulse_unreachable', 'AI Pulse did not answer.', true);
        }
        $status = $res['status'];
        $json = json_decode((string) $res['body'], true);
        if (!is_array($json)) {
            return self::result(false, $status, 'bad_response', "AI Pulse answered HTTP {$status} without JSON.", $status >= 500);
        }
        if ($status >= 200 && $status < 300 && ($json['status'] ?? 0) === 1) {
            return self::result(true, $status, null, null, false, is_array($json['data'] ?? null) ? $json['data'] : null);
        }

        return self::result(false, $status, (string) ($json['code'] ?? 'error'), (string) ($json['message'] ?? "AI Pulse answered HTTP {$status}."), (bool) ($json['retryable'] ?? $status >= 500));
    }

    /** The user's session when a user is behind the call, else the estate service key; null when neither. */
    private function callerHeader(?string $userSesKey): ?string
    {
        if ($userSesKey !== null && trim($userSesKey) !== '') {
            return 'Authorization: Bearer ' . trim($userSesKey);
        }
        $key = self::env('PULSE_SERVICE_KEY') ?: self::env('CONSOLE_SERVICE_KEY');

        return $key !== '' ? 'X-Pulse-Service-Key: ' . $key : null;
    }

    private static function defaultOrigin(): string
    {
        // From the configured environment only (Environment) — never the
        // request's Host, and never a guess for a CLI with no Host (X-09).
        return match (\Aicountly\Api\Environment::siblingTier()) {
            'production' => self::PRODUCTION,
            'sandbox'    => self::SANDBOX,
            default      => '',
        };
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: ?string, error: ?string}
     */
    private static function curl(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds, float $connectTimeoutSeconds): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => null, 'error' => 'unreachable'];
        }
        $options = [
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($connectTimeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS        => (int) round($timeoutSeconds * 1000),
            CURLOPT_NOSIGNAL          => true,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) $body;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return ['status' => 0, 'body' => null, 'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable'];
        }

        return ['status' => $status, 'body' => (string) $raw, 'error' => null];
    }

    private static function env(string $name): string
    {
        return trim(Env::get($name));
    }

    /** @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array} */
    private static function result(bool $ok, int $status, ?string $code, ?string $message, bool $retryable, ?array $data = null): array
    {
        return ['ok' => $ok, 'status' => $status, 'code' => $code, 'message' => $message, 'retryable' => $retryable, 'data' => $data];
    }
}
