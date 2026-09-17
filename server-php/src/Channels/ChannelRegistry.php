<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

use Aicountly\Api\Db;

/**
 * Which adapters this build has, and which connection uses which.
 *
 * ## Adding a provider
 *
 * Write an adapter, add it to ADAPTERS, and set `provider` on a channel
 * connection. Nothing else in the product needs to know it exists — the
 * composer reads capabilities, the dispatcher reads the adapter, the webhook
 * route resolves it by name. That is the whole extension point, and it is what
 * "future OTT messaging channels through extensible adapters" means in
 * practice.
 *
 * ## The planned channels
 *
 * Messenger, LINE, Telegram and the rest are NOT in this list. They are
 * declared in PLANNED, which is what the Channels & Trust screen draws its
 * "Planned" tile from. A planned channel has no adapter, cannot be connected,
 * and cannot be selected on a journey. Listing it as available with no adapter
 * behind it is the placeholder-that-looks-connected problem this codebase
 * works hard to make impossible.
 */
final class ChannelRegistry
{
    /** @var array<string, class-string<ChannelAdapter>> */
    private const ADAPTERS = [
        'whatsapp_cloud' => WhatsAppCloudAdapter::class,
        'twilio_sms'     => TwilioSmsAdapter::class,
        'rcs_generic'    => RcsAdapter::class,
    ];

    /**
     * Channels on the roadmap, with no adapter and therefore no way to connect.
     *
     * @var array<string, string>
     */
    public const PLANNED = [
        'messenger' => 'Facebook Messenger',
        'line'      => 'LINE',
        'telegram'  => 'Telegram',
        'instagram' => 'Instagram Direct',
    ];

    /** @var array<string, ChannelAdapter> */
    private static array $instances = [];

    public static function adapter(string $provider): ?ChannelAdapter
    {
        $provider = strtolower(trim($provider));

        // An already-built instance first. Under the web SAPI this is only ever
        // a memoised adapter from ADAPTERS; under CLI it may also be one
        // overrideForTesting() installed, which is what lets the dispatch
        // pipeline be exercised end to end without calling a real provider.
        if (isset(self::$instances[$provider])) {
            return self::$instances[$provider];
        }

        if (!isset(self::ADAPTERS[$provider])) {
            return null;
        }

        $class = self::ADAPTERS[$provider];

        return self::$instances[$provider] = new $class();
    }

    public static function adapterFor(ChannelConnection $connection): ?ChannelAdapter
    {
        return self::adapter($connection->provider);
    }

    /** @return list<string> */
    public static function providers(): array
    {
        return array_keys(self::ADAPTERS);
    }

    /**
     * Providers available for a channel, for the onboarding picker.
     *
     * @return list<array{provider:string, display_name:string, channel:string}>
     */
    public static function providersForChannel(string $channel): array
    {
        $out = [];
        foreach (self::providers() as $provider) {
            $adapter = self::adapter($provider);
            if ($adapter !== null && $adapter->channel() === strtolower($channel)) {
                $out[] = [
                    'provider'     => $provider,
                    'display_name' => $adapter->displayName(),
                    'channel'      => $adapter->channel(),
                ];
            }
        }

        return $out;
    }

    /**
     * The capabilities of one connection.
     *
     * Adapter declaration first, then any per-connection refinement the adapter
     * makes (an alphanumeric SMS sender losing INBOUND), then anything the
     * provider itself has told us, which is recorded in
     * messaging_channel_capabilities and wins because it is an observation
     * rather than a promise.
     *
     * @return array<string, bool>
     */
    public static function capabilities(ChannelConnection $connection): array
    {
        $adapter = self::adapterFor($connection);
        if ($adapter === null) {
            // No adapter means no capability. An unknown provider can do
            // nothing, which is the safe answer and disables every action.
            return array_fill_keys(Capability::all(), false);
        }

        $capabilities = method_exists($adapter, 'capabilitiesFor')
            ? $adapter->capabilitiesFor($connection)
            : $adapter->declaredCapabilities();

        try {
            $rows = Db::all(
                'SELECT capability, supported FROM messaging_channel_capabilities
                 WHERE connection_uuid = :uuid AND source = :source',
                ['uuid' => $connection->connectionUuid, 'source' => 'provider'],
            );
            foreach ($rows as $row) {
                $capabilities[(string) $row['capability']] = (bool) $row['supported'];
            }
        } catch (\Throwable $e) {
            // A capability lookup that fails must not silently widen what the
            // product believes it can do.
            error_log('[channels] capability lookup failed: ' . $e->getMessage());
        }

        return $capabilities;
    }

    public static function supports(ChannelConnection $connection, string $capability): bool
    {
        return self::capabilities($connection)[$capability] ?? false;
    }

    /**
     * Configuration state per provider, for /api/health.
     *
     * Booleans and counts only. No sender addresses — those identify the tenant
     * — and certainly no credentials, on an endpoint a stranger can read.
     *
     * @return array<string, mixed>
     */
    public static function healthSummary(): array
    {
        $out = [];
        foreach (self::providers() as $provider) {
            $adapter = self::adapter($provider);
            if ($adapter === null) {
                continue;
            }
            $out[$provider] = [
                'channel'   => $adapter->channel(),
                'available' => true,
            ];
        }

        try {
            $rows = Db::all(
                'SELECT channel, status, COUNT(*) AS n
                 FROM messaging_channel_connections
                 WHERE is_active = TRUE
                 GROUP BY channel, status',
            );
            $connections = [];
            foreach ($rows as $row) {
                $connections[(string) $row['channel']][(string) $row['status']] = (int) $row['n'];
            }
            $out['connections'] = $connections;
        } catch (\Throwable) {
            // No schema yet is a normal state on a fresh host.
            $out['connections'] = null;
        }

        $out['planned'] = array_keys(self::PLANNED);

        return $out;
    }

    /** CLI only. Lets a test substitute an adapter that does not call a provider. */
    public static function overrideForTesting(string $provider, ?ChannelAdapter $adapter): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        if ($adapter === null) {
            unset(self::$instances[$provider]);

            return;
        }
        self::$instances[$provider] = $adapter;
    }
}
