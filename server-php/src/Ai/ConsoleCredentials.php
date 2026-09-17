<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Env;

/**
 * This product's LLM credentials, from Console.
 *
 * ## Why Console and not a key in this product's .env
 *
 * console.aicountly.org is the fleet's system of record for AI provider keys.
 * Before it, every product carried its own key in its own server .env on its
 * own cPanel host — so rotating one key meant editing a dozen boxes by hand,
 * and nothing could report where a key was in use.
 *
 * Console holds it encrypted and hands it over on
 * `GET /ai/credentials/resolve`, authenticated with the shared service key.
 * NOTHING IS WRITTEN TO DISK HERE: the response is held in process memory and,
 * where APCu exists, in shared memory. A provider key never comes to rest in a
 * file next to this code, which is the point — a compromised product host no
 * longer yields a long-lived provider key.
 *
 * ## What this class is NOT
 *
 * It is not a second AI credentials system, and Messaging does not create a
 * central AI service. It does not store a key, mint one, cache one to disk or
 * accept one from a request. It asks Console and holds the answer for a few
 * minutes. Messaging owns its PROMPTS and its AI LOGIC — see DraftAssistant and
 * NextBestActions — and Console owns the keys and the provider governance.
 * Those are different things and both statements are true at once.
 *
 * Adapted from the same class in appointments-aicountly and calendar-react-app,
 * deliberately, so the fleet has one way of doing this.
 *
 * `providerSecret()` reuses the same path for CHANNEL credentials that a
 * deployment chooses to keep in Console rather than in its server environment —
 * see Channels/ChannelConnection::credential(). Same rule: nothing is written
 * to disk, and nothing is returned to a browser.
 */
final class ConsoleCredentials
{
    /** The module name this product resolves under, in Console. */
    public const MODULE = 'messaging';

    private const DEFAULT_TTL = 300;
    private const TIMEOUT = 4;

    /** Per-process memo. PHP-FPM reuses a worker for many requests. */
    private static array $memo = [];

    /**
     * @return array{
     *     api_key: string, model: string, provider: string, source: string,
     *     base_url: ?string, auth_header: ?string
     * }|null
     */
    public static function resolve(string $module = self::MODULE): ?array
    {
        $cacheKey = 'messaging|' . $module;

        if (isset(self::$memo[$cacheKey]) && self::$memo[$cacheKey]['expires'] > time()) {
            return self::$memo[$cacheKey]['value'];
        }

        $shared = self::apcuGet($cacheKey);
        if ($shared !== null) {
            self::$memo[$cacheKey] = ['value' => $shared, 'expires' => time() + 30];

            return $shared;
        }

        $fromConsole = self::fetch($module);
        if ($fromConsole !== null) {
            $shaped = self::shape($fromConsole);
            if ($shaped !== null) {
                $ttl = max(30, (int) ($fromConsole['ttl_seconds'] ?? self::DEFAULT_TTL));
                self::$memo[$cacheKey] = ['value' => $shaped, 'expires' => time() + $ttl];
                self::apcuSet($cacheKey, $shaped, $ttl);

                return $shaped;
            }
        }

        return null;
    }

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && Env::get('CONSOLE_SERVICE_KEY') !== '';
    }

    /**
     * What a screen may say about AI, with no secret in it.
     *
     * The variable NAME goes in `admin_hint`, never the value, and the caller
     * shows that hint only to somebody who could act on it.
     *
     * @return array{available: bool, model: ?string, provider: ?string, reason: ?string, admin_hint: ?string}
     */
    public static function status(): array
    {
        if (!self::isConfigured()) {
            return [
                'available'  => false,
                'model'      => null,
                'provider'   => null,
                'reason'     => 'AI insights are unavailable. No AI provider is configured for this deployment.',
                'admin_hint' => 'Set CONSOLE_API_URL and CONSOLE_SERVICE_KEY in the server environment. '
                    . 'The provider key itself lives in Console, not here.',
            ];
        }

        $resolved = self::resolve();
        if ($resolved === null) {
            return [
                'available'  => false,
                'model'      => null,
                'provider'   => null,
                'reason'     => 'AI insights are unavailable. Console did not return credentials for Messaging.',
                'admin_hint' => 'Add an AI connected account for the "messaging" module in Console.',
            ];
        }

        return [
            'available'  => true,
            'model'      => $resolved['model'] !== '' ? $resolved['model'] : null,
            'provider'   => $resolved['provider'],
            'reason'     => null,
            'admin_hint' => null,
        ];
    }

    /**
     * Report one AI call back to Console.
     *
     * Fire-and-forget: usage telemetry must never delay or fail a user-facing
     * response, so failures are swallowed and the timeout is one second.
     *
     * @param array<string, mixed> $event
     */
    public static function reportUsage(array $event): void
    {
        $base = self::baseUrl();
        $key = Env::get('CONSOLE_SERVICE_KEY');

        if ($base === '' || $key === '') {
            return;
        }

        try {
            $ch = curl_init($base . '/ai/usage');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS     => json_encode(['events' => [$event]]),
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Telemetry is never worth an exception on the caller's path.
        }
    }

    /** @return array<string, mixed>|null the decoded `data` envelope */
    private static function fetch(string $module): ?array
    {
        $base = self::baseUrl();
        $key = Env::get('CONSOLE_SERVICE_KEY');

        if ($base === '' || $key === '') {
            return null;
        }

        $url = $base . '/ai/credentials/resolve?domain=' . rawurlencode('messaging')
            . '&module=' . rawurlencode($module);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $status !== 200) {
            // The message is deliberately generic: a curl error can echo the
            // URL, and the URL is next door to the key.
            error_log(sprintf(
                '[messaging-ai] Console resolve for %s failed: %s',
                $module,
                $error !== '' ? 'transport error' : 'HTTP ' . $status,
            ));

            return null;
        }

        $json = json_decode($raw, true);

        return is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function shape(array $data): ?array
    {
        $list = $data['credentials'] ?? [];
        if (!is_array($list) || $list === []) {
            return null;
        }

        $primary = $list[0];
        if (trim((string) ($primary['api_key'] ?? '')) === '') {
            return null;
        }

        return [
            'api_key'     => (string) $primary['api_key'],
            'model'       => (string) ($primary['model'] ?? ''),
            'provider'    => (string) ($primary['provider'] ?? 'google'),
            'source'      => 'console',
            'base_url'    => isset($primary['base_url']) ? (string) $primary['base_url'] : null,
            'auth_header' => isset($primary['auth_header']) ? (string) $primary['auth_header'] : null,
        ];
    }

    private static function baseUrl(): string
    {
        return rtrim(Env::get('CONSOLE_API_URL'), '/');
    }

    /** @return array<string, mixed>|null */
    private static function apcuGet(string $key): ?array
    {
        if (!function_exists('apcu_fetch') || !ini_get('apc.enabled')) {
            return null;
        }

        $hit = false;
        $value = apcu_fetch('msg_ai_cred:' . $key, $hit);

        return ($hit && is_array($value)) ? $value : null;
    }

    /** @param array<string, mixed> $value */
    private static function apcuSet(string $key, array $value, int $ttl): void
    {
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            // Shared memory only — deliberately never a file, so a provider key
            // does not come to rest on the product host's disk.
            apcu_store('msg_ai_cred:' . $key, $value, $ttl);
        }
    }

    /**
     * A channel provider credential Console holds, by reference.
     *
     * Used when a connection's `credential_ref` is `console:<name>` rather than
     * an environment variable name. The indirection buys the same thing it buys
     * for model keys: rotation without touching a dozen cPanel hosts, and a
     * compromised product host that yields the NAMES of secrets rather than the
     * secrets.
     *
     * Returns '' when Console has nothing for it, which every adapter treats as
     * "not configured" and reports honestly.
     */
    public static function providerSecret(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !self::isConfigured()) {
            return '';
        }

        $resolved = self::resolve('channel:' . $name);

        return $resolved === null ? '' : (string) $resolved['api_key'];
    }

    /** CLI only. Lets the test suite exercise the disabled path without a Console. */
    public static function overrideForTesting(?array $credentials): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        if ($credentials === null) {
            self::$memo = [];

            return;
        }
        self::$memo['messaging|' . self::MODULE] = [
            'value'   => $credentials,
            'expires' => time() + 300,
        ];
    }
}
