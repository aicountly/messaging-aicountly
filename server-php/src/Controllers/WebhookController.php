<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\WebhookService;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Uuid;

/**
 * Provider webhooks.
 *
 * ## No Auth, by design — and no tenant from the payload, by design
 *
 * A delivery receipt from Meta is not a user and never will be. So these routes
 * resolve no session. What they do instead:
 *
 *  1. Take the connection uuid from the PATH.
 *  2. Look that connection up server-side.
 *  3. Verify the provider's signature against THAT connection's secret.
 *  4. Take the company from the row.
 *
 * A `cmp_id` in the body is ignored entirely. If the tenant came from the
 * payload, anybody who could reach this URL could write delivery events,
 * inbound messages and opt-outs into any company in the deployment — which is
 * the single worst thing that could go wrong on this endpoint.
 *
 * ## Answer fast, then think
 *
 * The receipt row is committed and the provider gets its 200. Providers retry
 * aggressively without one, and a provider retrying while we are still thinking
 * sends the same event five times.
 */
final class WebhookController
{
    /**
     * The subscription handshake some providers require.
     *
     * Verified against the connection's configured secret, in constant time,
     * before anything is echoed back.
     */
    public static function verify(string $provider, string $connectionUuid): void
    {
        if (!Uuid::isValid($connectionUuid)) {
            self::plain(404, 'Not found.');
        }

        $adapter = ChannelRegistry::adapter($provider);
        if ($adapter === null) {
            self::plain(404, 'Not found.');
        }

        $row = Db::first(
            'SELECT * FROM messaging_channel_connections WHERE connection_uuid = :uuid',
            ['uuid' => $connectionUuid],
        );
        if ($row === null) {
            self::plain(404, 'Not found.');
        }

        $connection = ChannelConnection::fromRow($row);
        $challenge = $adapter->webhookChallenge($connection, self::query());

        if ($challenge === null) {
            // Deliberately terse. Explaining why would help somebody get the
            // verify token right on their next attempt.
            self::plain(403, 'Forbidden.');
        }

        Db::run(
            'UPDATE messaging_channel_connections SET webhook_verified_at = NOW() WHERE connection_uuid = :uuid',
            ['uuid' => $connectionUuid],
        );

        self::plain(200, $challenge);
    }

    public static function receive(string $provider, string $connectionUuid): void
    {
        if (!Uuid::isValid($connectionUuid)) {
            self::plain(404, 'Not found.');
        }

        $rawBody = Http::rawBody();

        // A bound on what a stranger can post. Beyond this it is not a
        // delivery receipt.
        if (strlen($rawBody) > 1048576) {
            self::plain(413, 'Payload too large.');
        }

        $result = WebhookService::receive($provider, $connectionUuid, $rawBody, self::headers());

        // 200 on anything we accepted, including events we chose to ignore —
        // a provider that gets anything else retries, and retrying an event we
        // deliberately ignored is pure noise.
        if ($result['accepted']) {
            self::json(200, ['received' => true]);
        }

        self::json($result['status'], ['received' => false]);
    }

    // -----------------------------------------------------------------------

    /** @return array<string, string> */
    private static function headers(): array
    {
        $headers = [];

        if (function_exists('apache_request_headers')) {
            foreach ((array) apache_request_headers() as $name => $value) {
                $headers[(string) $name] = (string) $value;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_scalar($value)) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /** @return array<string, string> */
    private static function query(): array
    {
        $out = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                // Providers use dotted names (hub.mode) which PHP rewrites to
                // underscores; both spellings are offered to the adapter.
                $out[$key] = (string) $value;
                $out[str_replace('_', '.', $key)] = (string) $value;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    private static function json(int $status, array $payload): never
    {
        if (PHP_SAPI === 'cli') {
            throw new \Aicountly\Api\ResponseSent($status, $payload);
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload);
        exit;
    }

    private static function plain(int $status, string $body): never
    {
        if (PHP_SAPI === 'cli') {
            throw new \Aicountly\Api\ResponseSent($status, ['body' => $body]);
        }

        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }
}
