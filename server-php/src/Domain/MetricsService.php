<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * Command Centre metrics, defined precisely.
 *
 * ## The definitions, because vague metrics are worse than none
 *
 *  - ACCEPTED means a provider took the message. It is not delivery.
 *  - DELIVERED means a provider confirmed arrival. Only a receipt counts.
 *  - UNCONFIRMED is accepted with no terminal receipt yet. It is reported on
 *    its own rather than folded into either column, and the delivery rate's
 *    denominator names it — a rate computed as delivered/accepted while a
 *    third of the accepted messages have no receipt is a rate that flatters
 *    itself.
 *  - MEDIAN FIRST HUMAN REPLY uses `first_human_outbound_at`, which an
 *    automated acknowledgement never sets. A product that counted an auto-reply
 *    would report a two-second median and mean nothing by it.
 *  - SPEND separates provider-reported cost from estimate, and never adds two
 *    currencies together. Two currencies are reported as two figures.
 *
 * ## Why counters exist here at all
 *
 * `messaging_daily_metrics` counts OUR OWN events, and every figure in it is
 * derivable from `messaging_messages`. It is a roll-up of this product's data,
 * not a copy of anybody else's — no revenue, no orders, no collections. Those
 * live in Business Outcomes and are fetched live. Deriving a 90-day trend by
 * scanning every message every time a dashboard loads is the only thing this
 * table is avoiding.
 */
final class MetricsService
{
    /** Increment today's counters when a message is accepted. @param array<string, mixed> $message */
    public static function recordSent(Context $ctx, array $message): void
    {
        self::bump($ctx, (string) $message['channel'], ['messages_accepted' => 1, 'messages_unconfirmed' => 1]);
    }

    /** @param array<string, mixed> $message */
    public static function recordFailed(Context $ctx, array $message): void
    {
        self::bump($ctx, (string) $message['channel'], ['messages_failed' => 1]);
    }

    public static function recordInbound(Context $ctx, string $channel): void
    {
        self::bump($ctx, $channel, ['messages_inbound' => 1]);
    }

    /**
     * A provider status change.
     *
     * A delivery moves a message OUT of `unconfirmed` and into `delivered`,
     * which is why the counter is decremented as well as incremented. Without
     * that the unconfirmed figure would only ever grow.
     */
    public static function recordStatusChange(
        Context $ctx,
        string $channel,
        string $newStatus,
        ?int $costMinor = null,
        ?string $currency = null,
    ): void {
        $deltas = match ($newStatus) {
            MessageState::DELIVERED => ['messages_delivered' => 1, 'messages_unconfirmed' => -1],
            MessageState::READ      => ['messages_read' => 1],
            MessageState::FAILED    => ['messages_failed' => 1, 'messages_unconfirmed' => -1],
            default                 => [],
        };

        if ($costMinor !== null) {
            $deltas['provider_cost_minor'] = $costMinor;
        }

        if ($deltas !== []) {
            self::bump($ctx, $channel, $deltas, $currency);
        }
    }

    public static function recordConversationOpened(Context $ctx, string $channel): void
    {
        self::bump($ctx, $channel, ['conversations_opened' => 1]);
    }

    public static function recordConversationResolved(Context $ctx, string $channel, bool $aiAssisted): void
    {
        self::bump($ctx, $channel, [
            'conversations_resolved' => 1,
        ] + ($aiAssisted ? ['conversations_ai_assisted' => 1] : []));
    }

    /**
     * The Command Centre's headline figures.
     *
     * Computed from `messaging_messages` rather than the daily roll-up, because
     * the headline covers an arbitrary period and correctness beats a
     * millisecond here. The roll-up serves the trend chart.
     *
     * @return array<string, mixed>
     */
    public static function overview(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status IN (\'provider_accepted\',\'delivered\',\'read\')) AS accepted,
                COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status IN (\'delivered\',\'read\')) AS delivered,
                COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status = \'read\') AS read_count,
                COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status = \'failed\') AS failed,
                COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status IN (\'queued\',\'dispatching\')) AS pending,
                COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status = \'provider_accepted\') AS unconfirmed,
                COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status = \'submission_unknown\') AS submission_unknown,
                COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status IN (\'draft\',\'awaiting_approval\')) AS awaiting_approval,
                COUNT(*) FILTER (WHERE m.direction = \'inbound\') AS inbound,
                COALESCE(SUM(m.provider_cost_minor), 0) AS provider_cost_minor,
                COUNT(*) FILTER (WHERE m.provider_cost_minor IS NOT NULL) AS priced_messages,
                COALESCE(SUM(m.estimated_cost_minor), 0) AS estimated_cost_minor
             FROM messaging_messages m
             WHERE ' . $scope . ' AND m.created_at >= :from AND m.created_at < :to',
            $params,
        ) ?? [];

        $accepted = (int) ($row['accepted'] ?? 0);
        $delivered = (int) ($row['delivered'] ?? 0);
        $unconfirmed = (int) ($row['unconfirmed'] ?? 0);

        return [
            'messages_accepted'  => $accepted,
            'messages_delivered' => $delivered,
            'messages_read'      => (int) ($row['read_count'] ?? 0),
            'messages_failed'    => (int) ($row['failed'] ?? 0),
            'messages_pending'   => (int) ($row['pending'] ?? 0),
            'messages_unconfirmed' => $unconfirmed,
            'submission_unknown' => (int) ($row['submission_unknown'] ?? 0),
            'awaiting_approval'  => (int) ($row['awaiting_approval'] ?? 0),
            'messages_inbound'   => (int) ($row['inbound'] ?? 0),

            // The rate, WITH its denominator stated. A caller that wants a
            // single number can compute one; a screen that shows one without
            // this is a screen that is overstating delivery.
            'delivery_rate' => $accepted > 0 ? round($delivered / $accepted, 4) : null,
            'delivery_rate_basis' => [
                'numerator'   => 'messages a provider confirmed as delivered or read',
                'denominator' => 'messages a provider accepted',
                'accepted'    => $accepted,
                'delivered'   => $delivered,
                'unconfirmed' => $unconfirmed,
                'note'        => $unconfirmed > 0
                    ? $unconfirmed . ' accepted message(s) have no delivery confirmation yet and are counted as neither.'
                    : null,
            ],

            'spend' => self::spend($ctx, $period),
        ];
    }

    /**
     * Spend, kept honest.
     *
     * Provider-reported and estimated are separate figures with separate
     * labels. The currency comes from each message's own `cost_currency`, and
     * messages in different currencies are returned as separate entries — never
     * summed, because a total with no stated conversion rate is a number that
     * is confidently wrong.
     *
     * @return array<string, mixed>
     */
    public static function spend(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $rows = Db::all(
            'SELECT COALESCE(m.cost_currency, :fallback) AS currency,
                    COALESCE(SUM(m.provider_cost_minor), 0) AS provider_minor,
                    COALESCE(SUM(m.estimated_cost_minor), 0) AS estimated_minor,
                    COUNT(*) FILTER (WHERE m.provider_cost_minor IS NOT NULL) AS priced,
                    COUNT(*) FILTER (WHERE m.direction = \'outbound\'
                        AND m.status IN (\'provider_accepted\',\'delivered\',\'read\')) AS billable_messages
             FROM messaging_messages m
             WHERE ' . $scope . ' AND m.created_at >= :from AND m.created_at < :to
               AND m.direction = \'outbound\'
             GROUP BY COALESCE(m.cost_currency, :fallback)',
            $params + ['fallback' => Settings::currency($ctx)],
        );

        $byCurrency = [];
        foreach ($rows as $row) {
            $priced = (int) $row['priced'];
            $billable = (int) $row['billable_messages'];

            $byCurrency[] = [
                'currency'              => (string) $row['currency'],
                'provider_cost_minor'   => (int) $row['provider_minor'],
                'estimated_cost_minor'  => (int) $row['estimated_minor'],
                'priced_messages'       => $priced,
                'billable_messages'     => $billable,
                // The honest caveat, on the figure rather than in a footnote
                // somebody will remove.
                'completeness'          => $billable > 0 && $priced < $billable
                    ? 'partial'
                    : ($billable === 0 ? 'no_messages' : 'complete'),
                'completeness_note'     => $billable > 0 && $priced < $billable
                    ? 'The provider has reported a cost for ' . $priced . ' of ' . $billable
                        . ' billable messages. The rest are not yet priced.'
                    : null,
            ];
        }

        return [
            'by_currency'   => $byCurrency,
            // Explicit, so no caller is tempted to add the list up.
            'combined'      => null,
            'combined_note' => count($byCurrency) > 1
                ? 'Messages were sent in more than one currency. No total is shown because no conversion source is configured.'
                : null,
        ];
    }

    /**
     * Median time to a FIRST HUMAN reply.
     *
     * `percentile_cont` over the conversations that actually got one. A
     * conversation still waiting is excluded from the median and reported
     * separately as "awaiting reply" — including it as a zero or as its
     * current age would move the median for a reason that has nothing to do
     * with how fast anybody replied.
     *
     * @return array<string, mixed>
     */
    public static function responseTimes(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('c');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $row = Db::first(
            'SELECT
                percentile_cont(0.5) WITHIN GROUP (
                    ORDER BY EXTRACT(EPOCH FROM (c.first_human_outbound_at - c.first_inbound_at))
                ) AS median_human_seconds,
                percentile_cont(0.9) WITHIN GROUP (
                    ORDER BY EXTRACT(EPOCH FROM (c.first_human_outbound_at - c.first_inbound_at))
                ) AS p90_human_seconds,
                COUNT(*) FILTER (WHERE c.first_human_outbound_at IS NOT NULL) AS answered,
                COUNT(*) AS total
             FROM messaging_conversations c
             WHERE ' . $scope . '
               AND c.first_inbound_at >= :from AND c.first_inbound_at < :to
               AND (c.first_human_outbound_at IS NULL OR c.first_human_outbound_at >= c.first_inbound_at)',
            $params,
        ) ?? [];

        $automated = Db::first(
            'SELECT percentile_cont(0.5) WITHIN GROUP (
                        ORDER BY EXTRACT(EPOCH FROM (c.first_automated_outbound_at - c.first_inbound_at))
                    ) AS median_automated_seconds
             FROM messaging_conversations c
             WHERE ' . $scope . '
               AND c.first_inbound_at >= :from AND c.first_inbound_at < :to
               AND c.first_automated_outbound_at IS NOT NULL',
            $params,
        ) ?? [];

        $answered = (int) ($row['answered'] ?? 0);
        $total = (int) ($row['total'] ?? 0);

        return [
            'median_first_human_reply_seconds' => $row['median_human_seconds'] !== null
                ? (int) round((float) $row['median_human_seconds']) : null,
            'p90_first_human_reply_seconds' => $row['p90_human_seconds'] !== null
                ? (int) round((float) $row['p90_human_seconds']) : null,
            // Reported apart, never blended into the headline.
            'median_first_automated_reply_seconds' => $automated['median_automated_seconds'] !== null
                ? (int) round((float) $automated['median_automated_seconds']) : null,
            'basis' => [
                'conversations_in_period' => $total,
                'answered_by_a_human'     => $answered,
                'still_awaiting_a_reply'  => max(0, $total - $answered),
                'note' => 'The median covers conversations that received a human reply. Conversations still '
                    . 'awaiting one are excluded and counted separately, so a slow day does not flatter the median.',
            ],
        ];
    }

    /** Conversations waiting on us. @return array<string, mixed> */
    public static function awaitingReply(Context $ctx): array
    {
        [$scope, $params] = $ctx->scopeClause('c');
        $settings = Settings::for($ctx);
        $target = $settings['first_response_target_minutes'] ?? null;

        $row = Db::first(
            'SELECT
                COUNT(*) AS waiting,
                COUNT(*) FILTER (WHERE c.assigned_to_uuid IS NULL) AS unassigned,
                MAX(EXTRACT(EPOCH FROM (NOW() - c.last_inbound_at))) AS longest_wait_seconds
             FROM messaging_conversations c
             WHERE ' . $scope . '
               AND c.status <> \'resolved\'
               AND c.last_inbound_at IS NOT NULL
               AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)',
            $params,
        ) ?? [];

        $breaching = null;
        if ($target !== null) {
            // Only computed when a target exists. With none configured this
            // stays null and the screen does not show a breach count against a
            // number nobody set.
            $breaching = (int) (Db::scalar(
                'SELECT COUNT(*) FROM messaging_conversations c
                 WHERE ' . $scope . '
                   AND c.status <> \'resolved\'
                   AND c.last_inbound_at IS NOT NULL
                   AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)
                   AND c.last_inbound_at < NOW() - (:target || \' minutes\')::interval',
                $params + ['target' => (string) (int) $target],
            ) ?? 0);
        }

        return [
            'awaiting_reply'        => (int) ($row['waiting'] ?? 0),
            'unassigned'            => (int) ($row['unassigned'] ?? 0),
            'longest_wait_seconds'  => $row['longest_wait_seconds'] !== null
                ? (int) round((float) $row['longest_wait_seconds']) : null,
            'response_target_minutes' => $target !== null ? (int) $target : null,
            'breaching_target'      => $breaching,
            'target_note'           => $target === null
                ? 'No first-response target is configured, so no breach count is shown.'
                : null,
        ];
    }

    /** AI-assisted conversation count. @return array<string, mixed> */
    public static function aiAssisted(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('c');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $row = Db::first(
            'SELECT COUNT(*) FILTER (WHERE c.ai_assisted) AS assisted, COUNT(*) AS total
             FROM messaging_conversations c
             WHERE ' . $scope . ' AND c.created_at >= :from AND c.created_at < :to',
            $params,
        ) ?? [];

        return [
            'ai_assisted_conversations' => (int) ($row['assisted'] ?? 0),
            'conversations'             => (int) ($row['total'] ?? 0),
            'definition' => 'A conversation where the assistant drafted or rewrote at least one message that was sent. '
                . 'A human reviewed and sent every one of them.',
        ];
    }

    /**
     * Delivery trend by channel, from the daily roll-up.
     *
     * @return list<array<string, mixed>>
     */
    public static function deliveryTrend(Context $ctx, Period $period): array
    {
        return Db::all(
            'SELECT metric_date, channel,
                    SUM(messages_accepted) AS accepted,
                    SUM(messages_delivered) AS delivered,
                    SUM(messages_failed) AS failed,
                    SUM(messages_unconfirmed) AS unconfirmed,
                    SUM(messages_inbound) AS inbound
             FROM messaging_daily_metrics
             WHERE cmp_id = :cmp AND metric_date >= :from AND metric_date <= :to
             GROUP BY metric_date, channel
             ORDER BY metric_date',
            ['cmp' => $ctx->cmpId, 'from' => $period->fromDate(), 'to' => $period->toDate()],
        );
    }

    /**
     * Agent workload.
     *
     * Returns uuids and counts. Names come from Manage, live, resolved by the
     * controller — there is no agent table here and a copied display name would
     * be a name that stays wrong after somebody's marriage.
     *
     * @return list<array<string, mixed>>
     */
    public static function agentWorkload(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('c');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        return Db::all(
            'SELECT c.assigned_to_uuid AS user_uuid,
                    COUNT(*) FILTER (WHERE c.status <> \'resolved\') AS open_conversations,
                    COUNT(*) FILTER (WHERE c.status <> \'resolved\'
                        AND c.last_inbound_at IS NOT NULL
                        AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)) AS awaiting_reply,
                    COUNT(*) FILTER (WHERE c.resolved_at >= :from AND c.resolved_at < :to) AS resolved_in_period,
                    percentile_cont(0.5) WITHIN GROUP (
                        ORDER BY EXTRACT(EPOCH FROM (c.first_human_outbound_at - c.first_inbound_at))
                    ) AS median_reply_seconds
             FROM messaging_conversations c
             WHERE ' . $scope . ' AND c.assigned_to_uuid IS NOT NULL
             GROUP BY c.assigned_to_uuid
             ORDER BY open_conversations DESC
             LIMIT 50',
            $params,
        );
    }

    // -----------------------------------------------------------------------

    /**
     * Upsert today's counters.
     *
     * `ON CONFLICT ... DO UPDATE` with additive deltas, because two workers
     * recording a delivery in the same millisecond must both count. A
     * read-modify-write here would lose one of them.
     *
     * @param array<string, int> $deltas
     */
    private static function bump(Context $ctx, string $channel, array $deltas, ?string $currency = null): void
    {
        if ($deltas === []) {
            return;
        }

        $columns = array_keys($deltas);
        foreach ($columns as $column) {
            if (preg_match('/^[a-z_]+$/', $column) !== 1) {
                return;
            }
        }

        $date = Clock::now()->setTimezone(Settings::timezone($ctx))->format('Y-m-d');

        $insertColumns = ['cmp_id', 'bo_id', 'metric_date', 'channel', 'cost_currency', ...$columns];
        $placeholders = [':cmp', ':bo', ':date', ':channel', ':currency'];
        $params = [
            'cmp'      => $ctx->cmpId,
            'bo'       => $ctx->boId,
            'date'     => $date,
            'channel'  => $channel,
            'currency' => $currency ?? Settings::currency($ctx),
        ];
        foreach ($columns as $index => $column) {
            $placeholders[] = ':v' . $index;
            $params['v' . $index] = max(0, $deltas[$column]);
        }

        $updates = [];
        foreach ($columns as $index => $column) {
            // GREATEST(…, 0) because `messages_unconfirmed` is decremented and
            // a negative count on a dashboard is a bug that erodes trust in
            // every other number beside it.
            $updates[] = sprintf(
                '%s = GREATEST(messaging_daily_metrics.%s + :d%d, 0)',
                $column,
                $column,
                $index,
            );
            $params['d' . $index] = $deltas[$column];
        }

        try {
            Db::run(
                'INSERT INTO messaging_daily_metrics (' . implode(', ', $insertColumns) . ')
                 VALUES (' . implode(', ', $placeholders) . ')
                 ON CONFLICT (cmp_id, bo_id, metric_date, channel) DO UPDATE SET '
                . implode(', ', $updates) . ', updated_at = NOW()',
                $params,
            );
        } catch (\Throwable $e) {
            // A metrics write must never be the reason a message fails to send.
            error_log('[metrics] counter update failed: ' . $e->getMessage());
        }
    }
}
