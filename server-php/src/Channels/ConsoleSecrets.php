<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

use Aicountly\Api\Env;

/**
 * Channel provider secrets a deployment keeps in Console rather than in its
 * server environment: a connection whose `credential_ref` (or
 * `webhook_secret_ref`) is `console:<name>`. See ChannelConnection::credential().
 *
 * ## This is not AI
 *
 * Messaging's AI runs through AI Pulse and resolves no model key — see
 * Ai/AiClient. What is resolved here is a WhatsApp, Twilio or RCS secret. It
 * goes through Console's credential endpoint (`GET /ai/credentials/resolve`,
 * domain `messaging`, module `channel:<name>`) because that is where Console
 * keeps a product's named secrets.
 *
 * Once Messaging's domain is switched to "Using AI Pulse" in Console, Console
 * answers that endpoint with 409 for every module not marked
 * `keeps_own_credential`. A deployment with `console:` channel refs marks those
 * `channel:<name>` modules first. Until it does, the secret resolves to '' here,
 * which every adapter reports honestly as "not configured" rather than sending
 * with a stale key.
 *
 * ## Nothing comes to rest on disk
 *
 * The answer is held in process memory and, where APCu exists, in shared
 * memory, for the TTL Console gives. A compromised product host yields the
 * NAMES of secrets rather than the secrets, and rotation needs no edit on a
 * dozen cPanel hosts. Nothing here is returned to a browser.
 */
final class ConsoleSecrets
{
    private const DEFAULT_TTL = 300;
    private const TIMEOUT = 4;

    /** Per-process memo. PHP-FPM reuses a worker for many requests. @var array<string, array{value: string, expires: int}> */
    private static array $memo = [];

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && Env::get('CONSOLE_SERVICE_KEY') !== '';
    }

    /** The secret Console holds under `channel:<name>`, or '' when it has none (or will not say). */
    public static function get(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !self::isConfigured()) {
            return '';
        }

        $module = 'channel:' . $name;

        if (isset(self::$memo[$module]) && self::$memo[$module]['expires'] > time()) {
            return self::$memo[$module]['value'];
        }

        $shared = self::apcuGet($module);
        if ($shared !== null) {
            self::$memo[$module] = ['value' => $shared, 'expires' => time() + 30];

            return $shared;
        }

        $data = self::fetch($module);
        $list = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];
        $secret = trim((string) ($list[0]['api_key'] ?? ''));
        if ($secret === '') {
            return '';
        }

        $ttl = max(30, (int) ($data['ttl_seconds'] ?? self::DEFAULT_TTL));
        self::$memo[$module] = ['value' => $secret, 'expires' => time() + $ttl];
        self::apcuSet($module, $secret, $ttl);

        return $secret;
    }

    /** CLI only: forget what was resolved, so a test starts clean. */
    public static function forgetForTesting(): void
    {
        if (PHP_SAPI === 'cli') {
            self::$memo = [];
        }
    }

    /** @return array<string, mixed>|null the decoded `data` envelope */
    private static function fetch(string $module): ?array
    {
        $url = self::baseUrl() . '/ai/credentials/resolve?domain=' . rawurlencode('messaging')
            . '&module=' . rawurlencode($module);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . Env::get('CONSOLE_SERVICE_KEY')],
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
                '[messaging-channels] Console resolve for %s failed: %s',
                $module,
                $error !== '' ? 'transport error' : 'HTTP ' . $status,
            ));

            return null;
        }

        $json = json_decode($raw, true);

        return is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    private static function baseUrl(): string
    {
        return rtrim(Env::get('CONSOLE_API_URL'), '/');
    }

    private static function apcuGet(string $module): ?string
    {
        if (!function_exists('apcu_fetch') || !ini_get('apc.enabled')) {
            return null;
        }

        $hit = false;
        $value = apcu_fetch('msg_console_secret:' . $module, $hit);

        return ($hit && is_string($value) && $value !== '') ? $value : null;
    }

    private static function apcuSet(string $module, string $value, int $ttl): void
    {
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            // Shared memory only — deliberately never a file, so a provider
            // secret does not come to rest on the product host's disk.
            apcu_store('msg_console_secret:' . $module, $value, $ttl);
        }
    }
}
