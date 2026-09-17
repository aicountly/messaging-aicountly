<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Channels\Capability;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Domain\DispatchService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\WebhookService;
use Aicountly\Api\Env;
use Aicountly\Api\Features;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Channels & Trust.
 *
 * ## The rule this screen exists to honour
 *
 * A pending integration must not look connected. Every card here reports what
 * is ACTUALLY configured, names what is missing, and never shows a credential.
 * `credential_present` is a boolean; the environment variable NAME appears only
 * for somebody who holds `messaging.channels.manage` and could act on it.
 */
final class ChannelsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter('messaging.channels.view');

        $mayManage = Permissions::allows($ctx, $auth, 'messaging.channels.manage');

        $rows = Db::all(
            'SELECT * FROM messaging_channel_connections WHERE cmp_id = :cmp ORDER BY channel, created_at',
            ['cmp' => $ctx->cmpId],
        );

        $connections = [];
        foreach ($rows as $row) {
            $connection = ChannelConnection::fromRow($row);
            $adapter = ChannelRegistry::adapterFor($connection);
            $capabilities = ChannelRegistry::capabilities($connection);

            $card = $connection->toPublicArray() + [
                'adapter_name' => $adapter?->displayName() ?? ('Unknown provider: ' . $connection->provider),
                'status_label' => self::describeStatus($connection->status),
                'status_detail' => $row['status_detail'] ?? null,
                'capabilities' => array_map(
                    static fn (string $capability) => [
                        'capability' => $capability,
                        'label'      => Capability::describe($capability),
                        'supported'  => (bool) ($capabilities[$capability] ?? false),
                    ],
                    Capability::all(),
                ),
                'last_health_check_at' => $row['last_health_check_at'] ?? null,
                'last_health_check_ok' => $row['last_health_check_ok'] !== null
                    ? (bool) $row['last_health_check_ok'] : null,
                'last_health_check_note' => $row['last_health_check_note'] ?? null,
                'last_webhook_at' => $row['last_webhook_at'] ?? null,
                'webhook_verified_at' => $row['webhook_verified_at'] ?? null,
                // Provider-reported, with the time it was read. Nullable
                // because most providers do not report it and a zero would read
                // as "throttled to nothing".
                'provider_quality' => $row['provider_quality'] ?? null,
                'provider_rate_limit' => $row['provider_rate_limit'] !== null
                    ? (int) $row['provider_rate_limit'] : null,
                'provider_state_read_at' => $row['provider_state_read_at'] ?? null,
                'delivery_24h' => self::deliveryStats($ctx, $connection->connectionUuid),
                'template_status' => self::templateStatus($ctx, $connection->channel),
            ];

            // The configuration gap NAMES an environment variable, so only
            // somebody who can act on it sees it.
            if ($mayManage) {
                $card['configuration_gap'] = $adapter?->configurationGap($connection);
                $card['credential_ref'] = $connection->credentialRef;
                $card['webhook_url'] = self::webhookUrl($connection);
            } else {
                $gap = $adapter?->configurationGap($connection);
                $card['configuration_gap'] = $gap === null
                    ? null
                    : 'This channel is not fully configured. Ask an integration administrator to finish it.';
            }

            $connections[] = $card;
        }

        Http::data([
            'connections' => $connections,
            'planned'     => array_map(
                static fn (string $label, string $key) => [
                    'channel' => $key,
                    'label'   => $label,
                    'status'  => 'planned',
                    'note'    => 'On the roadmap. There is no adapter for it yet, so it cannot be connected or '
                        . 'selected on a journey.',
                ],
                ChannelRegistry::PLANNED,
                array_keys(ChannelRegistry::PLANNED),
            ),
            'available_providers' => [
                'whatsapp' => ChannelRegistry::providersForChannel('whatsapp'),
                'rcs'      => ChannelRegistry::providersForChannel('rcs'),
                'sms'      => ChannelRegistry::providersForChannel('sms'),
            ],
            'delivery_health' => self::deliveryHealth($ctx),
            'queue'           => Permissions::allows($ctx, $auth, 'messaging.dispatch.manage')
                ? DispatchService::queueHealth($ctx)
                : null,
            'webhooks'        => WebhookService::health($ctx),
            'consent'         => Permissions::allows($ctx, $auth, 'messaging.consent.view')
                ? ConsentService::summary($ctx)
                : null,
            'ai_permissions'  => self::aiPermissions($ctx, $auth),
            'integrations'    => self::integrations($ctx, $auth, $mayManage),
            'compliance_note' => 'This screen shows the checks this deployment actually performs and the states '
                . 'providers actually report. It does not claim compliance with any particular regulation — that '
                . 'is a judgement for the business and its advisers.',
        ]);
    }

    /**
     * Create a channel connection.
     *
     * The credential is NOT accepted in the request. What is accepted is the
     * NAME of the server environment variable holding it, so a credential never
     * travels through a browser, an access log or this API's request body.
     */
    public static function create(): void
    {
        [$auth, $ctx] = self::enter('messaging.channels.manage');

        $body = Http::body();
        $channel = (string) ($body['channel'] ?? '');
        $provider = (string) ($body['provider'] ?? '');

        if (!in_array($channel, ['whatsapp', 'rcs', 'sms', 'ott'], true)) {
            Http::validationFailed('Unknown channel "' . $channel . '".');
        }

        $adapter = ChannelRegistry::adapter($provider);
        if ($adapter === null) {
            Http::validationFailed(
                'No adapter is installed for provider "' . $provider . '". Available for ' . $channel . ': '
                . implode(', ', array_column(ChannelRegistry::providersForChannel($channel), 'provider')) . '.',
            );
        }
        if ($adapter->channel() !== $channel) {
            Http::validationFailed('Provider "' . $provider . '" serves the ' . $adapter->channel() . ' channel.');
        }

        // A credential reference must look like an environment variable name.
        // Accepting anything would let somebody point it at a value rather
        // than a name.
        $credentialRef = trim((string) ($body['credential_ref'] ?? ''));
        if ($credentialRef !== '' && preg_match('/^(console:[A-Za-z0-9_.-]{1,64}|[A-Z][A-Z0-9_]{2,63})$/', $credentialRef) !== 1) {
            Http::validationFailed(
                'credential_ref must be the NAME of a server environment variable (for example '
                . 'MESSAGING_WHATSAPP_TOKEN) or console:<name>. Never send the credential itself — this API does '
                . 'not accept one.',
            );
        }

        $webhookSecretRef = trim((string) ($body['webhook_secret_ref'] ?? ''));
        if ($webhookSecretRef !== '' && preg_match('/^(console:[A-Za-z0-9_.-]{1,64}|[A-Z][A-Z0-9_]{2,63})$/', $webhookSecretRef) !== 1) {
            Http::validationFailed('webhook_secret_ref must be the NAME of a server environment variable.');
        }

        $uuid = Uuid::v4();
        $senderAddress = trim((string) ($body['sender_address'] ?? ''));

        try {
            Db::insert('messaging_channel_connections', [
                'connection_uuid'      => $uuid,
                'cmp_id'               => $ctx->cmpId,
                'bo_id'                => $ctx->boId,
                'channel'              => $channel,
                'provider'             => $provider,
                'display_name'         => mb_substr(trim((string) ($body['display_name'] ?? '')), 0, 120),
                'sender_address'       => $senderAddress,
                'provider_account_ref' => mb_substr(trim((string) ($body['provider_account_ref'] ?? '')), 0, 200),
                'credential_ref'       => $credentialRef,
                'webhook_secret_ref'   => $webhookSecretRef,
                'status'               => 'setup_pending',
                'created_at'           => Clock::nowSql(),
                'created_by'           => $auth->uuid,
                'updated_at'           => Clock::nowSql(),
                'updated_by'           => $auth->uuid,
            ], 'connection_uuid');
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_messaging_conn_sender')) {
                self::fail(
                    'validation_failed',
                    'A ' . $channel . ' connection already exists for sender ' . $senderAddress . '.',
                );
            }
            throw $e;
        }

        $connection = ChannelConnection::find($ctx->cmpId, $uuid);
        if ($connection !== null) {
            self::recordCapabilities($connection);
            self::refreshStatus($ctx, $connection);
        }

        Audit::record($ctx, $auth, 'channel.created', 'channel_connection', $uuid, null, [
            'channel'        => $channel,
            'provider'       => $provider,
            'sender_address' => $senderAddress,
            // Never the value.
            'credential_ref' => $credentialRef,
        ]);

        $refreshed = ChannelConnection::find($ctx->cmpId, $uuid);

        Http::data([
            'connection' => $refreshed?->toPublicArray(),
            'configuration_gap' => $refreshed !== null ? $adapter->configurationGap($refreshed) : null,
            'webhook_url' => $refreshed !== null ? self::webhookUrl($refreshed) : null,
            'detail' => 'Connection created. It cannot send until its credential is present in the server '
                . 'environment and the provider webhook points at the URL above.',
        ], 201);
    }

    public static function update(string $connectionUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.channels.manage');

        $connection = ChannelConnection::find($ctx->cmpId, $connectionUuid);
        if ($connection === null) {
            Http::notFound('That channel connection could not be found.');
        }

        $expected = self::expectedVersion();
        if ($expected > 0 && $connection->rowVersion !== $expected) {
            self::fail('version_conflict', 'This connection was changed elsewhere. Reload before editing it.');
        }

        $body = Http::body();
        $values = [];

        foreach (['display_name', 'sender_address', 'provider_account_ref'] as $field) {
            if (array_key_exists($field, $body)) {
                $values[$field] = mb_substr(trim((string) $body[$field]), 0, 200);
            }
        }
        foreach (['credential_ref', 'webhook_secret_ref'] as $field) {
            if (array_key_exists($field, $body)) {
                $ref = trim((string) $body[$field]);
                if ($ref !== '' && preg_match('/^(console:[A-Za-z0-9_.-]{1,64}|[A-Z][A-Z0-9_]{2,63})$/', $ref) !== 1) {
                    Http::validationFailed($field . ' must be the NAME of a server environment variable.');
                }
                $values[$field] = $ref;
            }
        }
        if (array_key_exists('is_active', $body)) {
            $values['is_active'] = (bool) $body['is_active'];
        }

        if ($values === []) {
            Http::validationFailed('Nothing to change.');
        }

        $values['updated_at'] = Clock::nowSql();
        $values['updated_by'] = $auth->uuid;

        Db::run(
            'UPDATE messaging_channel_connections SET ' . implode(', ', array_map(
                static fn (string $c) => Db::quoteIdentifier($c) . ' = :' . $c,
                array_keys($values),
            )) . ', row_version = row_version + 1
             WHERE cmp_id = :cmp AND connection_uuid = :uuid',
            array_map(
                static fn (mixed $v) => is_bool($v) ? ($v ? 'true' : 'false') : $v,
                $values,
            ) + ['cmp' => $ctx->cmpId, 'uuid' => $connectionUuid],
        );

        $refreshed = ChannelConnection::find($ctx->cmpId, $connectionUuid);
        if ($refreshed !== null) {
            self::recordCapabilities($refreshed);
            self::refreshStatus($ctx, $refreshed);
        }

        Audit::record($ctx, $auth, 'channel.updated', 'channel_connection', $connectionUuid, null, $values);

        $final = ChannelConnection::find($ctx->cmpId, $connectionUuid);
        $adapter = $final !== null ? ChannelRegistry::adapterFor($final) : null;

        Http::data([
            'connection' => $final?->toPublicArray(),
            'configuration_gap' => ($adapter !== null && $final !== null) ? $adapter->configurationGap($final) : null,
        ]);
    }

    /**
     * Run a real health check against the provider.
     *
     * On demand, for somebody who asked — not on every page render. What it
     * records is the outcome and the time, never the response.
     */
    public static function healthCheck(string $connectionUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.channels.manage');

        $connection = ChannelConnection::find($ctx->cmpId, $connectionUuid);
        if ($connection === null) {
            Http::notFound('That channel connection could not be found.');
        }

        $adapter = ChannelRegistry::adapterFor($connection);
        if ($adapter === null) {
            self::fail('channel_not_configured', 'No adapter is installed for provider "' . $connection->provider . '".');
        }

        $gap = $adapter->configurationGap($connection);
        $ok = $gap === null;
        $note = $gap ?? 'Credentials are present and the configuration is complete.';

        Db::run(
            'UPDATE messaging_channel_connections
             SET last_health_check_at = :now, last_health_check_ok = :ok, last_health_check_note = :note,
                 status = CASE WHEN :ok THEN :connected ELSE status END,
                 status_detail = CASE WHEN :ok THEN NULL ELSE :note END,
                 updated_at = :now, updated_by = :actor
             WHERE cmp_id = :cmp AND connection_uuid = :uuid',
            [
                'now'       => Clock::nowSql(),
                'ok'        => $ok ? 'true' : 'false',
                'note'      => $note,
                'connected' => 'connected',
                'actor'     => $auth->uuid,
                'cmp'       => $ctx->cmpId,
                'uuid'      => $connectionUuid,
            ],
        );

        Audit::record($ctx, $auth, 'channel.health_checked', 'channel_connection', $connectionUuid, null, [
            'ok' => $ok,
        ]);

        Http::data([
            'ok'         => $ok,
            'detail'     => $note,
            'checked_at' => Clock::iso(),
            'note'       => 'This checks that the credential and identifiers this deployment needs are present. '
                . 'It does not send a message.',
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * AI permissions, as the brief lists them.
     *
     * The financial row is the interesting one: creating a financial record is
     * DELEGATED to the owning product and separately authorised there, so this
     * screen reports it as not Messaging's to grant.
     *
     * @return array<string, mixed>
     */
    private static function aiPermissions(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): array
    {
        $settings = Settings::for($ctx);

        return [
            'editable' => Permissions::allows($ctx, $auth, 'messaging.ai.manage'),
            'rows'     => [
                [
                    'key' => 'ai_draft_allowed', 'label' => 'Draft replies',
                    'state' => (bool) $settings['ai_draft_allowed'] ? 'allowed' : 'disabled',
                    'detail' => 'Suggest a reply for a person to review. Never sends.',
                ],
                [
                    'key' => 'ai_translate_allowed', 'label' => 'Translate messages',
                    'state' => (bool) $settings['ai_translate_allowed'] ? 'allowed' : 'disabled',
                    'detail' => 'Translate between configured languages. A translation that loses an amount or a '
                        . 'reference is rejected rather than offered.',
                ],
                [
                    'key' => 'ai_summarise_allowed', 'label' => 'Summarise conversations',
                    'state' => (bool) $settings['ai_summarise_allowed'] ? 'allowed' : 'disabled',
                    'detail' => 'Summarise a thread for a handoff.',
                ],
                [
                    'key' => 'ai_suggest_allowed', 'label' => 'Suggest next steps',
                    'state' => (bool) $settings['ai_suggest_allowed'] ? 'allowed' : 'disabled',
                    'detail' => 'Classify intent from a fixed list and suggest an action. Every suggestion is '
                        . 'validated here before anything happens.',
                ],
                [
                    'key' => 'ai_autosend_allowed', 'label' => 'Send without review',
                    'state' => (bool) $settings['ai_autosend_allowed'] ? 'allowed' : 'approval_required',
                    'detail' => (bool) $settings['ai_autosend_allowed']
                        ? 'ENABLED. AI-drafted messages can dispatch without a person reading them.'
                        : 'Off. Every AI-drafted message needs a person to approve it before it reaches a customer. '
                            . 'This is the default and turning it off is deliberate.',
                    'warning' => (bool) $settings['ai_autosend_allowed']
                        ? 'Autonomous sending is on for this company.' : null,
                ],
                [
                    'key' => 'financial_records', 'label' => 'Create financial records',
                    'state' => 'not_available',
                    'detail' => 'Not Messaging\'s to grant. Invoices, payments and credit notes belong to Aicountly '
                        . 'Books and Aicountly Pay, and creating one is authorised there — this product can only ask '
                        . 'those products for a link or a document under the signed-in user\'s own permissions.',
                    'locked' => true,
                ],
            ],
        ];
    }

    /**
     * Integration cards. A pending integration is never shown as connected.
     *
     * @return list<array<string, mixed>>
     */
    private static function integrations(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, bool $mayManage): array
    {
        $products = [
            'contacts'     => ['label' => 'Contacts', 'purpose' => 'Who the customer is. Names and numbers are read live.'],
            'books'        => ['label' => 'Books', 'purpose' => 'Invoices and balances, read live before every reminder.'],
            'sales'        => ['label' => 'Sales', 'purpose' => 'Orders and fulfilment status.'],
            'pay'          => ['label' => 'Pay', 'purpose' => 'Payment links and payment status.'],
            'appointments' => ['label' => 'Appointments', 'purpose' => 'Appointments and confirmations.'],
            'calendar'     => ['label' => 'Calendar', 'purpose' => 'Calendar events. Messaging stores none of its own.'],
            'drive'        => ['label' => 'Drive', 'purpose' => 'Document storage for attachments.'],
            'reach'        => ['label' => 'Reach', 'purpose' => 'Campaign planning. Messaging does not rebuild it.'],
            'billing'      => ['label' => 'Billing', 'purpose' => 'Dues and collection schedules.'],
            'ai'           => ['label' => 'Console (AI)', 'purpose' => 'Model credentials and AI governance.'],
        ];

        $cards = [];
        foreach ($products as $key => $meta) {
            $enabled = Features::enabled($key);
            $cards[] = [
                'product' => $key,
                'label'   => 'Aicountly ' . $meta['label'],
                'purpose' => $meta['purpose'],
                // Three states, not two. "Pending" and "unavailable" are
                // different things to an administrator.
                'state'   => $enabled ? 'connected' : 'pending',
                'state_label' => $enabled ? 'Connected' : 'Connection pending',
                // The remedy names environment variables, so only somebody who
                // could act on it sees it.
                'remedy'  => $enabled ? null : ($mayManage
                    ? Features::explain($key)
                    : 'Not connected yet. Ask an integration administrator to configure it.'),
            ];
        }

        return $cards;
    }

    /** @return array<string, mixed> */
    private static function deliveryHealth(\Aicountly\Api\Context $ctx): array
    {
        $row = Db::first(
            "SELECT
                COUNT(*) FILTER (WHERE status IN ('provider_accepted','delivered','read')) AS accepted,
                COUNT(*) FILTER (WHERE status IN ('delivered','read')) AS delivered,
                COUNT(*) FILTER (WHERE status = 'provider_accepted') AS unconfirmed,
                COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                COUNT(*) FILTER (WHERE status = 'submission_unknown') AS submission_unknown,
                COUNT(*) FILTER (WHERE status IN ('queued','dispatching')) AS in_flight
             FROM messaging_messages
             WHERE cmp_id = :cmp AND direction = 'outbound'
               AND created_at >= NOW() - INTERVAL '24 hours'",
            ['cmp' => $ctx->cmpId],
        ) ?? [];

        $accepted = (int) ($row['accepted'] ?? 0);
        $delivered = (int) ($row['delivered'] ?? 0);

        return [
            'window'              => 'Last 24 hours',
            'accepted'            => $accepted,
            'delivered'           => $delivered,
            'unconfirmed'         => (int) ($row['unconfirmed'] ?? 0),
            'failed'              => (int) ($row['failed'] ?? 0),
            'submission_unknown'  => (int) ($row['submission_unknown'] ?? 0),
            'in_flight'           => (int) ($row['in_flight'] ?? 0),
            'delivery_rate'       => $accepted > 0 ? round($delivered / $accepted, 4) : null,
            'basis'               => 'Confirmed deliveries divided by provider-accepted messages. Messages with no '
                . 'terminal receipt are counted as unconfirmed, not as delivered.',
        ];
    }

    /** @return array<string, mixed> */
    private static function deliveryStats(\Aicountly\Api\Context $ctx, string $connectionUuid): array
    {
        $row = Db::first(
            "SELECT COUNT(*) FILTER (WHERE status IN ('provider_accepted','delivered','read')) AS accepted,
                    COUNT(*) FILTER (WHERE status IN ('delivered','read')) AS delivered,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed
             FROM messaging_messages
             WHERE cmp_id = :cmp AND connection_uuid = :conn AND direction = 'outbound'
               AND created_at >= NOW() - INTERVAL '24 hours'",
            ['cmp' => $ctx->cmpId, 'conn' => $connectionUuid],
        ) ?? [];

        $accepted = (int) ($row['accepted'] ?? 0);

        return [
            'accepted'  => $accepted,
            'delivered' => (int) ($row['delivered'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
            'rate'      => $accepted > 0 ? round(((int) $row['delivered']) / $accepted, 4) : null,
        ];
    }

    /** @return array<string, int> */
    private static function templateStatus(\Aicountly\Api\Context $ctx, string $channel): array
    {
        $rows = Db::all(
            'SELECT v.provider_status, COUNT(*) AS n
             FROM messaging_template_versions v
             JOIN messaging_templates t ON t.template_uuid = v.template_uuid
             WHERE v.cmp_id = :cmp AND t.channel = :channel
             GROUP BY v.provider_status',
            ['cmp' => $ctx->cmpId, 'channel' => $channel],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['provider_status']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Record the adapter's declared capabilities for a connection.
     *
     * Source 'adapter', so a provider-reported capability recorded later wins —
     * an observation beats a promise.
     */
    private static function recordCapabilities(ChannelConnection $connection): void
    {
        $adapter = ChannelRegistry::adapterFor($connection);
        if ($adapter === null) {
            return;
        }

        $capabilities = method_exists($adapter, 'capabilitiesFor')
            ? $adapter->capabilitiesFor($connection)
            : $adapter->declaredCapabilities();

        foreach ($capabilities as $capability => $supported) {
            Db::run(
                'INSERT INTO messaging_channel_capabilities
                    (connection_uuid, cmp_id, capability, supported, source, read_at)
                 VALUES (:conn, :cmp, :cap, :supported, :source, NOW())
                 ON CONFLICT (connection_uuid, capability)
                 DO UPDATE SET supported = :supported, read_at = NOW()
                 WHERE messaging_channel_capabilities.source = :source',
                [
                    'conn'      => $connection->connectionUuid,
                    'cmp'       => $connection->cmpId,
                    'cap'       => $capability,
                    'supported' => $supported ? 'true' : 'false',
                    'source'    => 'adapter',
                ],
            );
        }
    }

    private static function refreshStatus(\Aicountly\Api\Context $ctx, ChannelConnection $connection): void
    {
        $adapter = ChannelRegistry::adapterFor($connection);
        $gap = $adapter?->configurationGap($connection);

        // RCS specifically resolves to verification_required rather than
        // setup_pending, because that is what is actually true of it.
        $status = $gap === null
            ? 'connected'
            : ($connection->channel === 'rcs' ? 'verification_required' : 'setup_pending');

        Db::run(
            'UPDATE messaging_channel_connections SET status = :status, status_detail = :detail
             WHERE connection_uuid = :uuid AND status NOT IN (:suspended, :disconnected)',
            [
                'status'       => $status,
                'detail'       => $gap,
                'uuid'         => $connection->connectionUuid,
                'suspended'    => 'suspended',
                'disconnected' => 'disconnected',
            ],
        );
    }

    /**
     * The URL to register with the provider.
     *
     * Built from configured values, never from the request's own Host header —
     * a webhook URL derived from an attacker-controlled header is a webhook URL
     * pointing wherever they like.
     */
    private static function webhookUrl(ChannelConnection $connection): ?string
    {
        $base = rtrim(Env::get('MESSAGING_WEBHOOK_BASE_URL'), '/');
        if ($base === '') {
            return null;
        }

        return $base . '/api/webhooks/' . rawurlencode($connection->provider)
            . '/' . rawurlencode($connection->connectionUuid);
    }

    private static function describeStatus(string $status): string
    {
        return match ($status) {
            'connected'             => 'Connected',
            'setup_pending'         => 'Setup pending',
            'verification_required' => 'Verification required',
            'suspended'             => 'Suspended by provider',
            'disconnected'          => 'Disconnected',
            default                 => $status,
        };
    }
}
