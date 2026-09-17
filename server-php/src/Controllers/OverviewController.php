<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Ai\NextBestActions;
use Aicountly\Api\Channels\Capability;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\DispatchService;
use Aicountly\Api\Domain\MetricsService;
use Aicountly\Api\Domain\Period;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * The Command Centre.
 *
 * One endpoint for the whole screen, because it is one screen and six requests
 * for it would be six round trips on a page somebody opens all day. Each
 * SECTION carries its own state, so a panel whose source is unavailable says so
 * without taking the others down.
 */
final class OverviewController extends Controller
{
    public static function show(): void
    {
        [$auth, $ctx] = self::enter('messaging.command_centre.view');

        $period = Period::fromRequest($ctx, 'today');
        $previous = $period->previous();

        $current = MetricsService::overview($ctx, $period);
        $prior = MetricsService::overview($ctx, $previous);

        $suggestions = NextBestActions::for($ctx, $auth);

        Http::data([
            'period'          => $period->describe(),
            'comparison'      => [
                'period' => $previous->describe(),
                'note'   => 'The immediately preceding period of the same length, in the company timezone.',
            ],
            'metrics'         => self::metrics($current, $prior, $ctx, $period),
            'response_times'  => MetricsService::responseTimes($ctx, $period),
            'awaiting'        => MetricsService::awaitingReply($ctx),
            'ai_assisted'     => MetricsService::aiAssisted($ctx, $period),
            'delivery_trend'  => MetricsService::deliveryTrend($ctx, $period),
            'channel_health'  => self::channelHealth($ctx),
            'agent_workload'  => self::agentWorkload($ctx, $auth, $period),
            'attention_queue' => self::attentionQueue($ctx, $auth),
            'suggestions'     => $suggestions,
            'suggestions_narrative' => NextBestActions::narrate($ctx, $auth, $suggestions),
            'ai'              => [
                'available' => AiClient::isAvailable(),
                'status'    => \Aicountly\Api\Ai\ConsoleCredentials::status(),
            ],
            'queue'           => Permissions::allows($ctx, $auth, 'messaging.dispatch.manage')
                ? DispatchService::queueHealth($ctx)
                : null,
        ]);
    }

    /**
     * Dismiss a suggestion for this user.
     *
     * Per user, not per company: one manager clearing their own list should not
     * hide a delivery problem from everybody else.
     */
    public static function dismissSuggestion(string $key): void
    {
        [$auth, $ctx] = self::enter('messaging.command_centre.view');

        $key = trim($key);
        if ($key === '' || preg_match('/^[a-z0-9_:-]{1,120}$/', $key) !== 1) {
            Http::validationFailed('That is not a suggestion key.');
        }

        NextBestActions::dismiss($ctx, $auth, $key, Http::intParam('days', 7) ?? 7);

        Http::data([
            'dismissed' => true,
            'detail'    => 'Hidden for you. It will come back if it is still true in a week, because a delivery '
                . 'problem that has not been fixed is still a problem.',
        ]);
    }

    /**
     * The headline metric tiles.
     *
     * Each tile carries `definition` and, where relevant, `basis`. A number on
     * a dashboard whose definition is not written down is a number two people
     * will read differently.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $prior
     * @return list<array<string, mixed>>
     */
    private static function metrics(array $current, array $prior, \Aicountly\Api\Context $ctx, Period $period): array
    {
        return [
            [
                'key'        => 'messages_delivered',
                'label'      => 'Messages delivered',
                'value'      => $current['messages_delivered'],
                'previous'   => $prior['messages_delivered'],
                'unit'       => 'count',
                'definition' => 'Outbound messages a provider confirmed as delivered or read. Provider acceptance '
                    . 'alone does not count.',
                'basis'      => $current['delivery_rate_basis'],
            ],
            [
                'key'        => 'messages_accepted',
                'label'      => 'Accepted by provider',
                'value'      => $current['messages_accepted'],
                'previous'   => $prior['messages_accepted'],
                'unit'       => 'count',
                'definition' => 'Messages a provider took responsibility for. Not the same as delivered — '
                    . $current['messages_unconfirmed'] . ' of these have no delivery confirmation yet.',
            ],
            [
                'key'        => 'awaiting_reply',
                'label'      => 'Awaiting reply',
                'value'      => MetricsService::awaitingReply($ctx)['awaiting_reply'],
                'previous'   => null,
                'unit'       => 'count',
                'definition' => 'Open conversations where the customer spoke last. A live count, not a figure for '
                    . 'the selected period.',
            ],
            [
                'key'        => 'median_first_human_reply',
                'label'      => 'Median first human reply',
                'value'      => MetricsService::responseTimes($ctx, $period)['median_first_human_reply_seconds'],
                'previous'   => MetricsService::responseTimes($ctx, $period->previous())['median_first_human_reply_seconds'],
                'unit'       => 'seconds',
                // Lower is better here, which a UI cannot infer from the number.
                'lower_is_better' => true,
                'definition' => 'Median time from the customer\'s first message to the first reply written or '
                    . 'approved by a person. Automated replies are measured separately and are not included.',
            ],
            [
                'key'        => 'messages_failed',
                'label'      => 'Failed',
                'value'      => $current['messages_failed'],
                'previous'   => $prior['messages_failed'],
                'unit'       => 'count',
                'lower_is_better' => true,
                'definition' => 'Messages a provider reported as failed.',
            ],
            [
                'key'        => 'submission_unknown',
                'label'      => 'Submission unknown',
                'value'      => $current['submission_unknown'],
                'previous'   => $prior['submission_unknown'],
                'unit'       => 'count',
                'lower_is_better' => true,
                'definition' => 'Sends that timed out after the provider may already have accepted them. Neither '
                    . 'delivered nor failed, and deliberately not retried.',
            ],
            [
                'key'        => 'spend',
                'label'      => 'Messaging spend',
                'value'      => null,
                'unit'       => 'money',
                'spend'      => $current['spend'],
                'definition' => 'Provider-reported cost where the provider has priced the message, kept separate '
                    . 'from estimates. Currencies are never added together.',
            ],
        ];
    }

    /**
     * Channel health.
     *
     * Configuration and the last health check. NOT a live provider call on
     * every page load — that would turn a dashboard into a rate-limit problem.
     *
     * @return array<string, mixed>
     */
    private static function channelHealth(\Aicountly\Api\Context $ctx): array
    {
        $rows = Db::all(
            'SELECT * FROM messaging_channel_connections WHERE cmp_id = :cmp ORDER BY channel',
            ['cmp' => $ctx->cmpId],
        );

        $channels = [];
        foreach ($rows as $row) {
            $connection = ChannelConnection::fromRow($row);
            $adapter = ChannelRegistry::adapterFor($connection);
            $capabilities = ChannelRegistry::capabilities($connection);

            // Delivery rate per channel over the last 24 hours, with its
            // denominator, from our own records rather than from the provider.
            $stats = Db::first(
                "SELECT COUNT(*) FILTER (WHERE status IN ('provider_accepted','delivered','read')) AS accepted,
                        COUNT(*) FILTER (WHERE status IN ('delivered','read')) AS delivered,
                        COUNT(*) FILTER (WHERE status = 'provider_accepted') AS unconfirmed,
                        COUNT(*) FILTER (WHERE status = 'failed') AS failed
                 FROM messaging_messages
                 WHERE cmp_id = :cmp AND connection_uuid = :conn AND direction = 'outbound'
                   AND created_at >= NOW() - INTERVAL '24 hours'",
                ['cmp' => $ctx->cmpId, 'conn' => $connection->connectionUuid],
            ) ?? [];

            $accepted = (int) ($stats['accepted'] ?? 0);
            $delivered = (int) ($stats['delivered'] ?? 0);

            $channels[] = [
                'connection_uuid' => $connection->connectionUuid,
                'channel'         => $connection->channel,
                'display_name'    => $connection->displayName !== ''
                    ? $connection->displayName
                    : ($adapter?->displayName() ?? ucfirst($connection->channel)),
                'provider'        => $connection->provider,
                'status'          => $connection->status,
                'status_label'    => self::describeStatus($connection->status),
                'configuration_gap' => $adapter?->configurationGap($connection),
                'last_health_check_at' => $row['last_health_check_at'] ?? null,
                'last_webhook_at' => $row['last_webhook_at'] ?? null,
                'can_receive'     => (bool) ($capabilities[Capability::INBOUND] ?? false),
                'delivery_24h'    => [
                    'accepted'    => $accepted,
                    'delivered'   => $delivered,
                    'unconfirmed' => (int) ($stats['unconfirmed'] ?? 0),
                    'failed'      => (int) ($stats['failed'] ?? 0),
                    'rate'        => $accepted > 0 ? round($delivered / $accepted, 4) : null,
                    'basis'       => 'Confirmed deliveries divided by provider-accepted messages, last 24 hours.',
                ],
            ];
        }

        // Planned channels, so the screen can show them as planned rather than
        // as broken or as available.
        $planned = [];
        foreach (ChannelRegistry::PLANNED as $key => $label) {
            $planned[] = ['channel' => $key, 'label' => $label, 'status' => 'planned'];
        }

        return [
            'configured' => $channels,
            'planned'    => $planned,
            'available_providers' => array_merge(
                ChannelRegistry::providersForChannel('whatsapp'),
                ChannelRegistry::providersForChannel('rcs'),
                ChannelRegistry::providersForChannel('sms'),
            ),
            'note' => $channels === []
                ? 'No channel is connected yet, so nothing can be sent. Connect one in Channels & Trust.'
                : null,
        ];
    }

    /**
     * Agent workload, with names resolved LIVE from Manage.
     *
     * @return array<string, mixed>
     */
    private static function agentWorkload(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, Period $period): array
    {
        $rows = MetricsService::agentWorkload($ctx, $period);

        // Names from Manage, on this request. There is no agent table here and
        // a copied display name is a name that stays wrong.
        $names = [];
        $nameState = 'ready';
        $nameMessage = '';

        if ($rows !== [] && $auth->sesKey() !== '') {
            $result = (new ManageClient())->withSession($auth->sesKey())->members($ctx->cmpId);
            if ($result['ok']) {
                $members = (array) ($result['body']['data'] ?? $result['body'] ?? []);
                foreach ($members as $member) {
                    if (!is_array($member)) {
                        continue;
                    }
                    $uuid = (string) ($member['uuid_aictly'] ?? $member['uuid'] ?? '');
                    if ($uuid !== '') {
                        $names[$uuid] = (string) ($member['name'] ?? $member['email'] ?? '');
                    }
                }
            } else {
                $nameState = (string) $result['state'];
                $nameMessage = (string) $result['message'];
            }
        }

        $agents = [];
        foreach ($rows as $row) {
            $uuid = (string) $row['user_uuid'];
            $agents[] = [
                'user_uuid'          => $uuid,
                // Falls back to the uuid rather than to "Unknown", so an
                // administrator can still tell the rows apart.
                'name'               => $names[$uuid] ?? $uuid,
                'name_resolved'      => isset($names[$uuid]),
                'open_conversations' => (int) $row['open_conversations'],
                'awaiting_reply'     => (int) $row['awaiting_reply'],
                'resolved_in_period' => (int) $row['resolved_in_period'],
                'median_reply_seconds' => $row['median_reply_seconds'] !== null
                    ? (int) round((float) $row['median_reply_seconds']) : null,
            ];
        }

        return [
            'agents'     => $agents,
            'name_state' => $nameState,
            'name_note'  => $nameState !== 'ready'
                ? 'Names could not be read from Aicountly Manage, so user ids are shown instead. ' . $nameMessage
                : 'Names read live from Aicountly Manage.',
        ];
    }

    /**
     * The needs-attention queue.
     *
     * @return array<string, mixed>
     */
    private static function attentionQueue(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): array
    {
        [$scope, $params] = $ctx->scopeClause('c');

        if (!Permissions::allows($ctx, $auth, 'messaging.conversations.view_all')) {
            $scope .= ' AND (c.assigned_to_uuid = :self OR c.assigned_to_uuid IS NULL)';
            $params['self'] = $auth->uuid;
        }

        $rows = Db::all(
            'SELECT c.conversation_uuid, c.customer_address, c.contact_uuid, c.provider_profile_name,
                    c.channel, c.intent, c.intent_source, c.priority, c.assigned_to_uuid,
                    c.last_inbound_at, c.row_version,
                    EXTRACT(EPOCH FROM (NOW() - c.last_inbound_at))::bigint AS waiting_seconds,
                    (SELECT m.body FROM messaging_messages m
                      WHERE m.conversation_uuid = c.conversation_uuid AND m.direction = \'inbound\'
                      ORDER BY m.created_at DESC LIMIT 1) AS last_message
             FROM messaging_conversations c
             WHERE ' . $scope . '
               AND c.status <> \'resolved\'
               AND c.last_inbound_at IS NOT NULL
               AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)
             ORDER BY c.last_inbound_at ASC
             LIMIT 20',
            $params,
        );

        $target = Settings::for($ctx)['first_response_target_minutes'] ?? null;

        $queue = [];
        foreach ($rows as $row) {
            $waiting = (int) ($row['waiting_seconds'] ?? 0);
            $queue[] = [
                'conversation_uuid' => (string) $row['conversation_uuid'],
                'customer_address'  => (string) $row['customer_address'],
                'contact_uuid'      => $row['contact_uuid'] !== null ? (string) $row['contact_uuid'] : null,
                'provider_profile_name' => (string) ($row['provider_profile_name'] ?? ''),
                'channel'           => (string) $row['channel'],
                'intent'            => (string) ($row['intent'] ?? ''),
                'intent_source'     => (string) ($row['intent_source'] ?? 'none'),
                'priority'          => (string) $row['priority'],
                'assigned_to_uuid'  => $row['assigned_to_uuid'] !== null ? (string) $row['assigned_to_uuid'] : null,
                'last_message'      => $row['last_message'] !== null ? (string) $row['last_message'] : null,
                'waiting_seconds'   => $waiting,
                'row_version'       => (int) $row['row_version'],
                // Only when a target exists. No target, no breach flag.
                'past_target'       => $target !== null ? $waiting > ((int) $target * 60) : null,
            ];
        }

        return [
            'rows'   => $queue,
            'target_minutes' => $target !== null ? (int) $target : null,
            'note'   => $target === null
                ? 'No first-response target is configured, so nothing here is marked as late.'
                : null,
        ];
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
