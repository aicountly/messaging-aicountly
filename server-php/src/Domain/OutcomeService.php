<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\AppointmentsClient;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\PayClient;
use Aicountly\Api\Clients\SalesClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Business Outcomes: linking conversations to business events, honestly.
 *
 * ## "Linked to messaging" is not "caused by messaging"
 *
 * Every figure this class produces is a correlation under a stated window, and
 * every payload says so. A customer who received a reminder and then paid may
 * have paid because of the reminder, because of a phone call, or because it was
 * payday. Messaging can say the first two things happened in that order; it
 * cannot say why, and a dashboard that implies otherwise is selling something.
 *
 * ## The definitions, all of them explicit in the payload
 *
 *  - ATTRIBUTION WINDOW: hours, stored per link as configured at the time.
 *  - MATCHING METHOD: how the link was made, from strongest to weakest —
 *    `payment_link_callback`, `journey_subject`, `reference_match`,
 *    `contact_window`, `manual`.
 *  - TIMEZONE: the window's, because "same day" is meaningless without it.
 *  - CARDINALITY: one outcome has at most ONE primary link. Enforced by a
 *    unique index, not by a convention.
 *  - CURRENCY: each outcome's own, never summed across currencies.
 *  - DENOMINATORS: stated beside every rate.
 *
 * ## Three specific mistakes this class does not make
 *
 *  1. It does not count a payment twice because five reminders preceded it.
 *     The unique partial index on `is_primary` makes that impossible.
 *  2. It does not derive appointment confirmations from delivered reminders.
 *     Confirmations come from Appointments, which is the only product that
 *     knows who confirmed.
 *  3. It does not derive collections from payment-link clicks. A click is not a
 *     payment; the amount comes from Pay or Books.
 *
 * ## Values are fetched live at render time
 *
 * `messaging_outcome_links` stores the reference and the attribution decision.
 * The amount and the status come from the owning product when this runs. A
 * refunded payment must stop counting, and a local `amount_minor` column would
 * count it forever.
 */
final class OutcomeService
{
    /** Default attribution window when none is configured. */
    private const DEFAULT_WINDOW_HOURS = 72;

    /**
     * The Business Outcomes payload.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function overview(Context $ctx, Auth $auth, Period $period, array $filters = []): array
    {
        $windowHours = max(1, min(720, (int) ($filters['window_hours'] ?? self::DEFAULT_WINDOW_HOURS)));

        $funnel = self::funnel($ctx, $period);
        $links = self::linksInPeriod($ctx, $period);
        $resolved = self::resolutionRate($ctx, $period);
        $costPerResolved = self::costPerResolvedConversation($ctx, $period, $resolved);

        // Each of these fetches its values live. Any that cannot is reported as
        // unavailable rather than as zero.
        $collections = self::collections($ctx, $auth, $links);
        $orders = self::orders($ctx, $auth, $links);
        $appointments = self::appointments($ctx, $auth, $links);

        return [
            'period'      => $period->describe(),
            'attribution' => [
                'window_hours'   => $windowHours,
                'timezone'       => $period->timezone->getName(),
                'matching_methods' => [
                    'payment_link_callback' => 'Aicountly Pay confirmed a payment against a link this conversation sent. Strongest.',
                    'journey_subject'       => 'The journey that sent the message was about this exact record.',
                    'reference_match'       => 'The customer quoted the reference, or the message named it.',
                    'contact_window'        => 'The same contact, within the window. Weakest — correlation only.',
                    'manual'                => 'Somebody linked these by hand.',
                ],
                'cardinality'    => 'One business outcome has at most one primary link. A payment preceded by several '
                    . 'reminders is counted once, against the most recent.',
                'deduplication'  => 'Enforced by a unique constraint on (company, product, outcome kind, external id) '
                    . 'for primary links.',
                'causation_note' => 'These outcomes are LINKED to messaging activity within the window. That is not '
                    . 'proof that messaging caused them — other calls, visits and marketing may have contributed.',
            ],
            'funnel'       => $funnel,
            'collections'  => $collections,
            'orders'       => $orders,
            'appointments' => $appointments,
            'resolution'   => $resolved,
            'cost_per_resolved_conversation' => $costPerResolved,
            'reply_rate'   => self::replyRate($ctx, $period),
            'channel_comparison' => self::channelComparison($ctx, $period),
            'journey_performance' => self::journeyPerformance($ctx, $period),
        ];
    }

    /**
     * Delivered → replied → qualified → linked outcome.
     *
     * Every stage names its own denominator. A funnel whose percentages are all
     * of the first stage tells a different story from one whose percentages are
     * of the previous stage, and a reader cannot tell which they are looking at
     * unless it says.
     *
     * @return array<string, mixed>
     */
    public static function funnel(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $row = Db::first(
            'SELECT
                COUNT(DISTINCT m.message_uuid) FILTER (
                    WHERE m.direction = \'outbound\' AND m.status IN (\'delivered\',\'read\')
                ) AS delivered,
                COUNT(DISTINCT m.conversation_uuid) FILTER (
                    WHERE m.direction = \'outbound\' AND m.status IN (\'delivered\',\'read\')
                ) AS conversations_delivered
             FROM messaging_messages m
             WHERE ' . $scope . ' AND m.created_at >= :from AND m.created_at < :to',
            $params,
        ) ?? [];

        $delivered = (int) ($row['delivered'] ?? 0);
        $conversationsDelivered = (int) ($row['conversations_delivered'] ?? 0);

        // A reply is an inbound message AFTER a delivered outbound one in the
        // same conversation. Not merely "the conversation has inbound messages",
        // which would count the enquiry that started it as a reply to us.
        $replied = (int) (Db::scalar(
            'SELECT COUNT(DISTINCT out_msg.conversation_uuid)
             FROM messaging_messages out_msg
             JOIN messaging_messages in_msg
               ON in_msg.conversation_uuid = out_msg.conversation_uuid
              AND in_msg.direction = \'inbound\'
              AND in_msg.created_at > out_msg.created_at
             WHERE ' . str_replace('m.', 'out_msg.', $scope) . '
               AND out_msg.direction = \'outbound\'
               AND out_msg.status IN (\'delivered\',\'read\')
               AND out_msg.created_at >= :from AND out_msg.created_at < :to',
            $params,
        ) ?? 0);

        // "Qualified" is ours to define and so it is defined here: a
        // conversation that was replied to AND resolved, or that carries a
        // linked outcome. Anything vaguer would be a number nobody can
        // reproduce.
        $qualified = (int) (Db::scalar(
            'SELECT COUNT(DISTINCT c.conversation_uuid)
             FROM messaging_conversations c
             WHERE ' . str_replace('m.', 'c.', $scope) . '
               AND c.last_inbound_at >= :from AND c.last_inbound_at < :to
               AND c.last_outbound_at IS NOT NULL
               AND (c.status = \'resolved\' OR EXISTS (
                     SELECT 1 FROM messaging_outcome_links o
                     WHERE o.conversation_uuid = c.conversation_uuid AND o.is_primary))',
            $params,
        ) ?? 0);

        $linked = (int) (Db::scalar(
            'SELECT COUNT(*) FROM messaging_outcome_links o
             WHERE o.cmp_id = :cmp AND o.is_primary
               AND o.outcome_at >= :from AND o.outcome_at < :to',
            ['cmp' => $ctx->cmpId, 'from' => $period->fromSql(), 'to' => $period->toSql()],
        ) ?? 0);

        return [
            'stages' => [
                [
                    'key' => 'delivered', 'label' => 'Delivered', 'count' => $delivered,
                    'denominator' => null,
                    'note' => 'Outbound messages a provider confirmed as delivered or read.',
                ],
                [
                    'key' => 'replied', 'label' => 'Replied', 'count' => $replied,
                    'denominator' => $conversationsDelivered,
                    'denominator_label' => 'conversations with a delivered message',
                    'share' => $conversationsDelivered > 0 ? round($replied / $conversationsDelivered, 4) : null,
                    'note' => 'Conversations where the customer sent something after a delivered message.',
                ],
                [
                    'key' => 'qualified', 'label' => 'Qualified', 'count' => $qualified,
                    'denominator' => $replied,
                    'denominator_label' => 'conversations that replied',
                    'share' => $replied > 0 ? round($qualified / $replied, 4) : null,
                    'note' => 'Replied conversations that were resolved or carry a linked business outcome.',
                ],
                [
                    'key' => 'linked', 'label' => 'Linked outcomes', 'count' => $linked,
                    'denominator' => $qualified,
                    'denominator_label' => 'qualified conversations',
                    'share' => $qualified > 0 ? round($linked / $qualified, 4) : null,
                    'note' => 'Business events linked to a conversation within the attribution window. Correlation.',
                ],
            ],
            'note' => 'Each stage states the denominator its share is computed against, so the percentages are '
                . 'stage-to-stage rather than all of the first stage.',
        ];
    }

    /**
     * Collections linked to messaging.
     *
     * The links are ours; the AMOUNTS come from Books and Pay, live. A link
     * whose amount cannot be fetched is counted in `unresolved` and its value
     * is excluded — never guessed, and never carried over from a previous read.
     *
     * @param list<array<string, mixed>> $links
     * @return array<string, mixed>
     */
    private static function collections(Context $ctx, Auth $auth, array $links): array
    {
        $relevant = array_values(array_filter(
            $links,
            static fn (array $l) => in_array((string) $l['outcome_kind'], ['invoice_payment', 'payment_link_paid'], true),
        ));

        if ($relevant === []) {
            return self::emptyOutcome('No collections are linked to messaging in this period.');
        }

        $books = new BooksClient();
        $pay = new PayClient();

        if (!$books->configured() && !$pay->configured()) {
            return [
                'state'   => 'pending',
                'message' => 'Aicountly Books and Aicountly Pay are not connected, so the value of these '
                    . count($relevant) . ' linked payment(s) cannot be read. The links are recorded.',
                'linked_count' => count($relevant),
                'by_currency'  => [],
                'unresolved'   => count($relevant),
                'completeness' => 'unavailable',
            ];
        }

        $booksClient = $auth->sesKey() !== '' ? $books->withSession($auth->sesKey()) : $books->withService($auth->uuid);

        $byCurrency = [];
        $unresolved = 0;
        $resolved = 0;

        foreach ($relevant as $link) {
            $product = (string) $link['owner_product'];
            $externalId = (string) $link['external_id'];

            $result = $product === 'pay'
                ? $pay->paymentLink($ctx, $externalId)
                : $booksClient->invoice($ctx, $externalId);

            if (!$result['ok']) {
                $unresolved++;
                continue;
            }

            $body = $result['body']['data'] ?? $result['body'] ?? [];
            $status = strtoupper((string) ($body['status'] ?? ''));

            // A reversed or cancelled payment must stop counting. This is
            // exactly what a stored amount could not do.
            if (in_array($status, ['CANCELLED', 'REFUNDED', 'REVERSED', 'FAILED'], true)) {
                continue;
            }

            $currency = strtoupper((string) ($body['currency'] ?? Settings::currency($ctx)));
            $amount = isset($body['amount_minor'])
                ? (int) $body['amount_minor']
                : (isset($body['paid_amount']) ? (int) round((float) $body['paid_amount'] * 100) : 0);

            if ($amount === 0) {
                $unresolved++;
                continue;
            }

            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $amount;
            $resolved++;
        }

        $totals = [];
        foreach ($byCurrency as $currency => $minor) {
            $totals[] = ['currency' => $currency, 'amount_minor' => $minor];
        }

        return [
            'state'        => $unresolved > 0 ? 'partial' : 'ready',
            'message'      => $unresolved > 0
                ? $unresolved . ' of ' . count($relevant) . ' linked payments could not be valued right now, so this '
                    . 'total is partial. It is not a complete figure.'
                : '',
            'linked_count' => count($relevant),
            'valued_count' => $resolved,
            'by_currency'  => $totals,
            'unresolved'   => $unresolved,
            'completeness' => $unresolved > 0 ? 'partial' : 'complete',
            'combined'     => null,
            'combined_note' => count($totals) > 1
                ? 'Payments were made in more than one currency and are shown separately.'
                : null,
            'source_note'  => 'Values read live from Aicountly Books and Aicountly Pay when this screen loaded. '
                . 'Messaging stores the link, never the amount.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return array<string, mixed>
     */
    private static function orders(Context $ctx, Auth $auth, array $links): array
    {
        $relevant = array_values(array_filter($links, static fn (array $l) => (string) $l['outcome_kind'] === 'order'));

        if ($relevant === []) {
            return self::emptyOutcome('No orders are linked to messaging in this period.');
        }

        $sales = new SalesClient();
        if (!$sales->configured()) {
            return [
                'state'        => 'pending',
                'message'      => 'Aicountly Sales is not connected, so these ' . count($relevant)
                    . ' linked order(s) cannot be valued.',
                'linked_count' => count($relevant),
                'by_currency'  => [],
                'unresolved'   => count($relevant),
                'completeness' => 'unavailable',
            ];
        }

        $client = $auth->sesKey() !== '' ? $sales->withSession($auth->sesKey()) : $sales->withService($auth->uuid);

        $byCurrency = [];
        $unresolved = 0;
        $counted = 0;

        foreach ($relevant as $link) {
            $result = $client->order($ctx, (string) $link['external_id']);
            if (!$result['ok']) {
                $unresolved++;
                continue;
            }

            $body = $result['body']['data'] ?? $result['body'] ?? [];
            $status = strtoupper((string) ($body['status'] ?? ''));

            // A cancelled order is not an outcome. Stated in the payload, so a
            // reader knows what the count includes.
            if (in_array($status, ['CANCELLED', 'DRAFT', 'REJECTED'], true)) {
                continue;
            }

            $currency = strtoupper((string) ($body['currency'] ?? Settings::currency($ctx)));
            $amount = isset($body['total_amount']) ? (int) round((float) $body['total_amount'] * 100) : 0;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $amount;
            $counted++;
        }

        $totals = [];
        foreach ($byCurrency as $currency => $minor) {
            $totals[] = ['currency' => $currency, 'amount_minor' => $minor];
        }

        return [
            'state'            => $unresolved > 0 ? 'partial' : 'ready',
            'message'          => $unresolved > 0
                ? $unresolved . ' of ' . count($relevant) . ' linked orders could not be read, so this is partial.'
                : '',
            'linked_count'     => count($relevant),
            'counted'          => $counted,
            'by_currency'      => $totals,
            'unresolved'       => $unresolved,
            'completeness'     => $unresolved > 0 ? 'partial' : 'complete',
            'included_statuses' => 'All order statuses except cancelled, draft and rejected.',
            'source_note'      => 'Read live from Aicountly Sales.',
        ];
    }

    /**
     * Appointment confirmations.
     *
     * READ FROM APPOINTMENTS. Not derived from delivered reminders, which is
     * the mistake the brief calls out: a delivery receipt says a phone buzzed.
     *
     * @param list<array<string, mixed>> $links
     * @return array<string, mixed>
     */
    private static function appointments(Context $ctx, Auth $auth, array $links): array
    {
        $relevant = array_values(array_filter(
            $links,
            static fn (array $l) => (string) $l['outcome_kind'] === 'appointment_confirmed',
        ));

        if ($relevant === []) {
            return self::emptyOutcome('No appointment confirmations are linked to messaging in this period.');
        }

        $client = new AppointmentsClient();
        if (!$client->configured()) {
            return [
                'state'        => 'pending',
                'message'      => 'Aicountly Appointments is not connected. ' . count($relevant) . ' confirmation(s) '
                    . 'are linked but cannot be verified. Messaging does not infer a confirmation from a delivered '
                    . 'reminder — a delivery receipt is not a confirmation.',
                'linked_count' => count($relevant),
                'confirmed'    => null,
                'completeness' => 'unavailable',
            ];
        }

        $scoped = $auth->sesKey() !== '' ? $client->withSession($auth->sesKey()) : $client->withService($auth->uuid);

        $confirmed = 0;
        $unresolved = 0;
        foreach ($relevant as $link) {
            $result = $scoped->booking($ctx, (string) $link['external_id']);
            if (!$result['ok']) {
                $unresolved++;
                continue;
            }
            $body = $result['body']['data'] ?? $result['body'] ?? [];
            if (in_array(strtoupper((string) ($body['status'] ?? '')), ['CONFIRMED', 'ARRIVED', 'IN_PROGRESS', 'COMPLETED'], true)) {
                $confirmed++;
            }
        }

        return [
            'state'        => $unresolved > 0 ? 'partial' : 'ready',
            'message'      => $unresolved > 0
                ? $unresolved . ' linked appointment(s) could not be read, so this is partial.'
                : '',
            'linked_count' => count($relevant),
            'confirmed'    => $confirmed,
            'unresolved'   => $unresolved,
            'completeness' => $unresolved > 0 ? 'partial' : 'complete',
            'source_note'  => 'Confirmation status read live from Aicountly Appointments, which is the product that '
                . 'knows who confirmed. Not derived from delivered reminders.',
        ];
    }

    /** @return array<string, mixed> */
    public static function resolutionRate(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('c');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $row = Db::first(
            'SELECT
                COUNT(*) AS opened,
                COUNT(*) FILTER (WHERE c.status = \'resolved\') AS resolved,
                COUNT(*) FILTER (WHERE c.reopened_count > 0) AS reopened,
                percentile_cont(0.5) WITHIN GROUP (
                    ORDER BY EXTRACT(EPOCH FROM (c.resolved_at - c.created_at))
                ) AS median_resolution_seconds
             FROM messaging_conversations c
             WHERE ' . $scope . ' AND c.created_at >= :from AND c.created_at < :to',
            $params,
        ) ?? [];

        $opened = (int) ($row['opened'] ?? 0);
        $resolved = (int) ($row['resolved'] ?? 0);

        return [
            'conversations_opened'   => $opened,
            'conversations_resolved' => $resolved,
            'reopened'               => (int) ($row['reopened'] ?? 0),
            'resolution_rate'        => $opened > 0 ? round($resolved / $opened, 4) : null,
            'median_resolution_seconds' => $row['median_resolution_seconds'] !== null
                ? (int) round((float) $row['median_resolution_seconds']) : null,
            'basis' => [
                'numerator'   => 'conversations opened in this period that are currently resolved',
                'denominator' => 'conversations opened in this period',
                'note'        => 'A conversation opened in this period and resolved after it still counts. '
                    . 'Reopened conversations are reported separately: a conversation reopened three times was '
                    . 'not really resolved the first two.',
            ],
        ];
    }

    /**
     * Cost per resolved conversation.
     *
     * Provider-reported cost only, divided by resolved conversations. An
     * estimate mixed in would make this a number that changes when the bill
     * arrives, and the label would still say the same thing.
     *
     * @param array<string, mixed> $resolution
     * @return array<string, mixed>
     */
    private static function costPerResolvedConversation(Context $ctx, Period $period, array $resolution): array
    {
        $spend = MetricsService::spend($ctx, $period);
        $resolved = (int) ($resolution['conversations_resolved'] ?? 0);

        if ($resolved === 0) {
            return [
                'state'   => 'unavailable',
                'message' => 'No conversations were resolved in this period, so a cost per resolved conversation '
                    . 'cannot be computed.',
                'by_currency' => [],
            ];
        }

        $out = [];
        $anyPartial = false;
        foreach ($spend['by_currency'] as $entry) {
            $partial = ($entry['completeness'] ?? '') === 'partial';
            $anyPartial = $anyPartial || $partial;

            $out[] = [
                'currency'           => $entry['currency'],
                'cost_minor'         => (int) $entry['provider_cost_minor'],
                'resolved'           => $resolved,
                'cost_per_resolved_minor' => (int) round(((int) $entry['provider_cost_minor']) / $resolved),
                'completeness'       => $entry['completeness'],
                'note'               => $partial
                    ? 'Partial: the provider has not priced every message yet, so the real cost is higher.'
                    : null,
            ];
        }

        return [
            'state'       => $anyPartial ? 'partial' : 'ready',
            'message'     => $anyPartial
                ? 'Some messages are not yet priced by the provider, so this is a floor rather than the final figure.'
                : '',
            'by_currency' => $out,
            'basis'       => 'Provider-reported cost only, divided by conversations resolved in this period. '
                . 'Estimated cost is excluded so this figure does not move when the bill arrives.',
        ];
    }

    /** @return array<string, mixed> */
    private static function replyRate(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $rows = Db::all(
            'SELECT EXTRACT(HOUR FROM m.created_at AT TIME ZONE :tz)::int AS hour,
                    COUNT(*) AS delivered,
                    COUNT(*) FILTER (WHERE EXISTS (
                        SELECT 1 FROM messaging_messages r
                        WHERE r.conversation_uuid = m.conversation_uuid
                          AND r.direction = \'inbound\'
                          AND r.created_at > m.created_at
                    )) AS replied
             FROM messaging_messages m
             WHERE ' . $scope . '
               AND m.direction = \'outbound\' AND m.status IN (\'delivered\',\'read\')
               AND m.created_at >= :from AND m.created_at < :to
             GROUP BY 1 ORDER BY 1',
            $params + ['tz' => $period->timezone->getName()],
        );

        $totalDelivered = 0;
        $totalReplied = 0;
        $byHour = [];
        foreach ($rows as $row) {
            $delivered = (int) $row['delivered'];
            $replied = (int) $row['replied'];
            $totalDelivered += $delivered;
            $totalReplied += $replied;

            $byHour[] = [
                'hour'      => (int) $row['hour'],
                'delivered' => $delivered,
                'replied'   => $replied,
                'rate'      => $delivered > 0 ? round($replied / $delivered, 4) : null,
            ];
        }

        return [
            'overall_rate' => $totalDelivered > 0 ? round($totalReplied / $totalDelivered, 4) : null,
            'delivered'    => $totalDelivered,
            'replied'      => $totalReplied,
            'by_hour'      => $byHour,
            'timezone'     => $period->timezone->getName(),
            'basis'        => 'Share of delivered outbound messages followed by any inbound message in the same '
                . 'conversation. Hours are in the company timezone.',
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function channelComparison(Context $ctx, Period $period): array
    {
        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        return Db::all(
            'SELECT m.channel,
                    COUNT(*) FILTER (WHERE m.direction = \'outbound\'
                        AND m.status IN (\'provider_accepted\',\'delivered\',\'read\')) AS accepted,
                    COUNT(*) FILTER (WHERE m.direction = \'outbound\'
                        AND m.status IN (\'delivered\',\'read\')) AS delivered,
                    COUNT(*) FILTER (WHERE m.direction = \'outbound\' AND m.status = \'failed\') AS failed,
                    COUNT(*) FILTER (WHERE m.direction = \'outbound\'
                        AND m.status = \'provider_accepted\') AS unconfirmed,
                    COALESCE(SUM(m.provider_cost_minor), 0) AS cost_minor,
                    COUNT(*) FILTER (WHERE m.provider_cost_minor IS NOT NULL) AS priced
             FROM messaging_messages m
             WHERE ' . $scope . ' AND m.created_at >= :from AND m.created_at < :to
             GROUP BY m.channel ORDER BY accepted DESC',
            $params,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function journeyPerformance(Context $ctx, Period $period): array
    {
        return Db::all(
            'SELECT j.journey_uuid, j.name, j.kind,
                    COUNT(r.run_uuid) AS runs,
                    COUNT(*) FILTER (WHERE r.outcome = \'sent\') AS sent,
                    COUNT(*) FILTER (WHERE r.status = \'cancelled\') AS cancelled,
                    COUNT(*) FILTER (WHERE r.status = \'paused\') AS paused,
                    COUNT(*) FILTER (WHERE r.status = \'awaiting_approval\') AS awaiting_approval,
                    COUNT(*) FILTER (WHERE r.outcome = \'source_unavailable\') AS source_unavailable,
                    COUNT(*) FILTER (WHERE r.outcome IN (\'no_consent\',\'suppressed\',\'withdrawn\')) AS not_eligible,
                    (SELECT COUNT(*) FROM messaging_outcome_links o
                      WHERE o.run_uuid IN (SELECT run_uuid FROM messaging_journey_runs rr
                                            WHERE rr.journey_uuid = j.journey_uuid)
                        AND o.is_primary) AS linked_outcomes
             FROM messaging_journeys j
             LEFT JOIN messaging_journey_runs r
                    ON r.journey_uuid = j.journey_uuid
                   AND r.mode = \'live\'
                   AND r.started_at >= :from AND r.started_at < :to
             WHERE j.cmp_id = :cmp
             GROUP BY j.journey_uuid, j.name, j.kind
             ORDER BY runs DESC',
            ['cmp' => $ctx->cmpId, 'from' => $period->fromSql(), 'to' => $period->toSql()],
        );
    }

    /**
     * Link a business outcome to a conversation.
     *
     * The primary-link rule is applied here: an existing primary link for the
     * same outcome is demoted, and the new one becomes primary. So five
     * reminders preceding one payment produce five links and ONE count.
     *
     * @return array{ok:bool, code:string, detail:string, outcome_uuid:?string}
     */
    public static function link(
        Context $ctx,
        Auth $auth,
        string $ownerProduct,
        string $outcomeKind,
        string $externalId,
        string $matchMethod,
        ?string $conversationUuid = null,
        ?string $messageUuid = null,
        ?string $runUuid = null,
        ?string $messageAt = null,
        ?string $outcomeAt = null,
        string $externalLabel = '',
        int $windowHours = self::DEFAULT_WINDOW_HOURS,
    ): array {
        if ($externalId === '') {
            return ['ok' => false, 'code' => 'validation_failed', 'detail' => 'An outcome needs a reference.', 'outcome_uuid' => null];
        }

        return Db::transaction(static function () use (
            $ctx, $auth, $ownerProduct, $outcomeKind, $externalId, $matchMethod,
            $conversationUuid, $messageUuid, $runUuid, $messageAt, $outcomeAt, $externalLabel, $windowHours
        ): array {
            $uuid = Uuid::v4();

            // Demote the existing primary, if any. The new link supersedes it,
            // and the old row keeps a pointer so the decision is auditable
            // rather than overwritten.
            $existing = Db::first(
                'SELECT outcome_uuid FROM messaging_outcome_links
                 WHERE cmp_id = :cmp AND owner_product = :product AND outcome_kind = :kind
                   AND external_id = :ext AND is_primary
                 FOR UPDATE',
                [
                    'cmp' => $ctx->cmpId, 'product' => $ownerProduct,
                    'kind' => $outcomeKind, 'ext' => $externalId,
                ],
            );

            if ($existing !== null) {
                Db::run(
                    'UPDATE messaging_outcome_links SET is_primary = FALSE, superseded_by = :new
                     WHERE outcome_uuid = :old',
                    ['new' => $uuid, 'old' => $existing['outcome_uuid']],
                );
            }

            Db::insert('messaging_outcome_links', [
                'outcome_uuid'      => $uuid,
                'cmp_id'            => $ctx->cmpId,
                'bo_id'             => $ctx->boId,
                'conversation_uuid' => $conversationUuid,
                'message_uuid'      => $messageUuid,
                'run_uuid'          => $runUuid,
                'owner_product'     => $ownerProduct,
                'outcome_kind'      => $outcomeKind,
                'external_id'       => $externalId,
                'external_label'    => $externalLabel,
                'match_method'      => $matchMethod,
                // Stored as configured NOW, so changing the window later does
                // not silently rewrite this decision.
                'window_hours'      => $windowHours,
                'window_timezone'   => Settings::timezone($ctx)->getName(),
                'message_at'        => $messageAt,
                'outcome_at'        => $outcomeAt ?? Clock::nowSql(),
                'is_primary'        => true,
                'created_at'        => Clock::nowSql(),
                'created_by_uuid'   => $auth->uuid,
                'created_by_kind'   => $auth->kind === 'user' ? 'user' : 'engine',
            ], 'outcome_uuid');

            return [
                'ok'     => true,
                'code'   => 'ok',
                'detail' => $existing !== null
                    ? 'Linked. An earlier link to the same outcome was superseded, so it is counted once.'
                    : 'Linked.',
                'outcome_uuid' => $uuid,
            ];
        });
    }

    /**
     * The evidence table.
     *
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public static function evidence(Context $ctx, Period $period, array $filters, int $limit, int $offset): array
    {
        $params = [
            'cmp'  => $ctx->cmpId,
            'from' => $period->fromSql(),
            'to'   => $period->toSql(),
        ];
        $where = ['o.cmp_id = :cmp', 'o.outcome_at >= :from', 'o.outcome_at < :to'];

        if (($filters['primary_only'] ?? true) !== false) {
            $where[] = 'o.is_primary';
        }
        if (($filters['owner_product'] ?? '') !== '') {
            $where[] = 'o.owner_product = :product';
            $params['product'] = $filters['owner_product'];
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) (Db::scalar('SELECT COUNT(*) FROM messaging_outcome_links o WHERE ' . $whereSql, $params) ?? 0);

        $rows = Db::all(
            'SELECT o.*, c.customer_address, c.channel, j.name AS journey_name,
                    m.body AS message_body, m.language AS message_language
             FROM messaging_outcome_links o
             LEFT JOIN messaging_conversations c ON c.conversation_uuid = o.conversation_uuid
             LEFT JOIN messaging_messages m ON m.message_uuid = o.message_uuid
             LEFT JOIN messaging_journey_runs r ON r.run_uuid = o.run_uuid
             LEFT JOIN messaging_journeys j ON j.journey_uuid = r.journey_uuid
             WHERE ' . $whereSql . '
             ORDER BY o.outcome_at DESC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    private static function linksInPeriod(Context $ctx, Period $period): array
    {
        return Db::all(
            'SELECT outcome_uuid, owner_product, outcome_kind, external_id, external_label,
                    match_method, window_hours, message_at, outcome_at, conversation_uuid, run_uuid
             FROM messaging_outcome_links
             WHERE cmp_id = :cmp AND is_primary AND outcome_at >= :from AND outcome_at < :to
             ORDER BY outcome_at DESC
             LIMIT 500',
            ['cmp' => $ctx->cmpId, 'from' => $period->fromSql(), 'to' => $period->toSql()],
        );
    }

    /** @return array<string, mixed> */
    private static function emptyOutcome(string $message): array
    {
        return [
            'state'        => 'ready',
            'message'      => $message,
            'linked_count' => 0,
            'by_currency'  => [],
            'unresolved'   => 0,
            'completeness' => 'complete',
        ];
    }
}
