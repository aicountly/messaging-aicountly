<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Features;
use Aicountly\Api\Permissions;

/**
 * "Your next best moves" on the Command Centre.
 *
 * ## These are RULES, and they say so
 *
 * Every suggestion here is computed by a SQL query over this product's own
 * data. Not one of them is a model's opinion, and each is labelled
 * `kind: verified_fact` because that is what it is: "12 payment reminders are
 * waiting for review" is a count, not a prediction.
 *
 * The model's only role is optional prose (`narrate()`), and where no model is
 * configured the rule-based sentence is used instead. NOTHING IS FABRICATED
 * WHEN A MODEL IS UNAVAILABLE — the suggestions are all still there, because
 * they never needed one.
 *
 * ## No manufactured predictions
 *
 * The brief says not to manufacture predictions when a suitable model or
 * evidence is unavailable, and there are deliberately none here. There is no
 * "this customer is 73% likely to pay" suggestion, because this product has no
 * model trained to say that and inventing a number would be worse than
 * silence.
 *
 * ## Every suggestion carries its own evidence
 *
 * `why`, `evidence`, `source`, `fetched_at`, `kind`, and a concrete `action`
 * that lands on a real screen with real filters. A suggestion a user cannot
 * act on or verify is a nag.
 */
final class NextBestActions
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function for(Context $ctx, Auth $auth): array
    {
        $dismissed = self::dismissedKeys($ctx, $auth);
        $suggestions = [];

        foreach ([
            self::draftsAwaitingApproval($ctx, $auth),
            self::enquiriesAwaitingReply($ctx, $auth),
            self::atRiskOfMissingTarget($ctx, $auth),
            self::deliveryFailures($ctx, $auth),
            self::submissionsUnknown($ctx, $auth),
            self::missingContextBlockingReplies($ctx, $auth),
            self::channelsNeedingAttention($ctx, $auth),
            self::templatesRejected($ctx, $auth),
            self::journeysPaused($ctx, $auth),
        ] as $suggestion) {
            if ($suggestion === null) {
                continue;
            }
            if (in_array($suggestion['key'], $dismissed, true)) {
                continue;
            }
            $suggestions[] = $suggestion;
        }

        // Highest impact first. `weight` is ours and is documented on each
        // suggestion, rather than an opaque "priority score".
        usort($suggestions, static fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return array_slice($suggestions, 0, 6);
    }

    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function draftsAwaitingApproval(Context $ctx, Auth $auth): ?array
    {
        if (!Permissions::allows($ctx, $auth, 'messaging.drafts.approve')) {
            return null;
        }

        [$scope, $params] = $ctx->scopeClause('m');

        $row = Db::first(
            'SELECT COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE m.journey_run_uuid IS NOT NULL) AS from_journeys,
                    MIN(m.created_at) AS oldest
             FROM messaging_messages m
             WHERE ' . $scope . ' AND m.status = :awaiting',
            $params + ['awaiting' => 'awaiting_approval'],
        ) ?? [];

        $count = (int) ($row['n'] ?? 0);
        if ($count === 0) {
            return null;
        }

        return [
            'key'      => 'drafts_awaiting_approval',
            'weight'   => 90,
            'title'    => $count . ' ' . ($count === 1 ? 'draft needs' : 'drafts need') . ' review',
            'detail'   => (int) ($row['from_journeys'] ?? 0) > 0
                ? (int) $row['from_journeys'] . ' of them were prepared by a journey. Nothing sends until somebody reads them.'
                : 'Nothing sends until somebody reads them.',
            'kind'     => 'verified_fact',
            'why'      => 'These messages are in the awaiting_approval state in this company\'s own records.',
            'evidence' => [
                ['label' => 'Drafts awaiting approval', 'value' => (string) $count, 'source' => 'Messaging'],
                ['label' => 'Oldest', 'value' => (string) ($row['oldest'] ?? ''), 'source' => 'Messaging'],
            ],
            'source'     => 'messaging',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'inbox', 'label' => 'Review drafts', 'params' => ['status' => 'awaiting_approval']],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function enquiriesAwaitingReply(Context $ctx, Auth $auth): ?array
    {
        [$scope, $params] = $ctx->scopeClause('c');

        $row = Db::first(
            'SELECT COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE c.assigned_to_uuid IS NULL) AS unassigned,
                    MAX(EXTRACT(EPOCH FROM (NOW() - c.last_inbound_at))) AS longest
             FROM messaging_conversations c
             WHERE ' . $scope . '
               AND c.status <> \'resolved\'
               AND c.last_inbound_at IS NOT NULL
               AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)',
            $params,
        ) ?? [];

        $count = (int) ($row['n'] ?? 0);
        if ($count === 0) {
            return null;
        }

        $longestHours = $row['longest'] !== null ? (int) floor(((float) $row['longest']) / 3600) : 0;

        return [
            'key'      => 'awaiting_reply',
            'weight'   => 80 + min(15, $longestHours),
            'title'    => $count . ' ' . ($count === 1 ? 'conversation is' : 'conversations are') . ' awaiting a reply',
            'detail'   => (int) ($row['unassigned'] ?? 0) > 0
                ? (int) $row['unassigned'] . ' of them are unassigned.'
                : 'All of them are assigned.',
            'kind'     => 'verified_fact',
            'why'      => 'In each of these the customer spoke last, and the conversation is not resolved.',
            'evidence' => [
                ['label' => 'Awaiting a reply', 'value' => (string) $count, 'source' => 'Messaging'],
                ['label' => 'Unassigned', 'value' => (string) ($row['unassigned'] ?? 0), 'source' => 'Messaging'],
                ['label' => 'Longest wait', 'value' => $longestHours > 0 ? $longestHours . ' hours' : 'under an hour', 'source' => 'Messaging'],
            ],
            'source'     => 'messaging',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'inbox', 'label' => 'Open inbox', 'params' => ['awaiting_reply' => '1']],
        ];
    }

    /**
     * Conversations at risk of missing the CONFIGURED response target.
     *
     * Returns null when no target is configured. A warning against a number
     * nobody agreed to is noise, and inventing a default would be worse.
     *
     * @return array<string, mixed>|null
     */
    private static function atRiskOfMissingTarget(Context $ctx, Auth $auth): ?array
    {
        $target = Settings::for($ctx)['first_response_target_minutes'] ?? null;
        if ($target === null) {
            return null;
        }

        [$scope, $params] = $ctx->scopeClause('c');
        $target = (int) $target;

        // "At risk" is the last quarter of the window. Stated, not implied.
        $warnAfter = (int) max(1, floor($target * 0.75));

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE c.last_inbound_at < NOW() - (:warn || \' minutes\')::interval
                                   AND c.last_inbound_at >= NOW() - (:target || \' minutes\')::interval) AS at_risk,
                COUNT(*) FILTER (WHERE c.last_inbound_at < NOW() - (:target || \' minutes\')::interval) AS breached
             FROM messaging_conversations c
             WHERE ' . $scope . '
               AND c.status <> \'resolved\'
               AND c.last_inbound_at IS NOT NULL
               AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)',
            $params + ['warn' => (string) $warnAfter, 'target' => (string) $target],
        ) ?? [];

        $atRisk = (int) ($row['at_risk'] ?? 0);
        $breached = (int) ($row['breached'] ?? 0);

        if ($atRisk === 0 && $breached === 0) {
            return null;
        }

        return [
            'key'      => 'response_target_risk',
            'weight'   => 85,
            'title'    => $breached > 0
                ? $breached . ' ' . ($breached === 1 ? 'conversation has' : 'conversations have') . ' missed your response target'
                : $atRisk . ' ' . ($atRisk === 1 ? 'conversation is' : 'conversations are') . ' close to your response target',
            'detail'   => 'Your first-response target is ' . $target . ' minutes.'
                . ($atRisk > 0 && $breached > 0 ? ' ' . $atRisk . ' more are close to it.' : ''),
            'kind'     => 'verified_fact',
            'why'      => 'Measured against the ' . $target . '-minute first-response target configured for this '
                . 'company in Settings. "Close" means the last quarter of that window.',
            'evidence' => [
                ['label' => 'Target', 'value' => $target . ' minutes', 'source' => 'Messaging settings'],
                ['label' => 'Past target', 'value' => (string) $breached, 'source' => 'Messaging'],
                ['label' => 'Approaching', 'value' => (string) $atRisk, 'source' => 'Messaging'],
            ],
            'source'     => 'messaging',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'inbox', 'label' => 'Open inbox', 'params' => ['awaiting_reply' => '1', 'sort' => 'waiting']],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function deliveryFailures(Context $ctx, Auth $auth): ?array
    {
        if (!Permissions::allows($ctx, $auth, 'messaging.channels.view')) {
            return null;
        }

        [$scope, $params] = $ctx->scopeClause('m');

        $rows = Db::all(
            "SELECT m.failure_code, COUNT(*) AS n
             FROM messaging_messages m
             WHERE " . $scope . " AND m.status = 'failed'
               AND m.failed_at >= NOW() - INTERVAL '24 hours'
             GROUP BY m.failure_code ORDER BY n DESC LIMIT 5",
            $params,
        );

        $total = array_sum(array_map(static fn (array $r) => (int) $r['n'], $rows));
        if ($total === 0) {
            return null;
        }

        $evidence = [];
        foreach ($rows as $row) {
            $evidence[] = [
                'label'  => (string) ($row['failure_code'] ?? 'unknown'),
                'value'  => (string) $row['n'] . ' message(s)',
                'source' => 'Provider delivery events',
            ];
        }

        return [
            'key'      => 'delivery_failures',
            'weight'   => 75,
            'title'    => $total . ' ' . ($total === 1 ? 'message' : 'messages') . ' failed in the last 24 hours',
            'detail'   => 'Check the provider events before resending anything.',
            'kind'     => 'verified_fact',
            'why'      => 'These messages carry a failure reported by the provider. The codes are the provider\'s own.',
            'evidence' => $evidence,
            'source'     => 'messaging',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'delivery', 'label' => 'Investigate', 'params' => ['status' => 'failed']],
        ];
    }

    /**
     * Messages whose submission is genuinely ambiguous.
     *
     * The highest-weighted suggestion in the list, because it is the only one
     * where doing nothing risks a customer either not hearing from us or
     * hearing twice — and where the obvious action (resend) is the wrong one.
     *
     * @return array<string, mixed>|null
     */
    private static function submissionsUnknown(Context $ctx, Auth $auth): ?array
    {
        if (!Permissions::allows($ctx, $auth, 'messaging.dispatch.manage')) {
            return null;
        }

        [$scope, $params] = $ctx->scopeClause('m');

        $count = (int) (Db::scalar(
            'SELECT COUNT(*) FROM messaging_messages m
             WHERE ' . $scope . ' AND m.status = :unknown',
            $params + ['unknown' => 'submission_unknown'],
        ) ?? 0);

        if ($count === 0) {
            return null;
        }

        return [
            'key'      => 'submission_unknown',
            'weight'   => 95,
            'title'    => $count . ' ' . ($count === 1 ? 'message needs' : 'messages need') . ' investigation',
            'detail'   => 'The provider did not confirm whether it took these. They have not been resent, because '
                . 'the customer may already have received them.',
            'kind'     => 'verified_fact',
            'why'      => 'A send timed out after the provider may already have accepted it. Resending risks a '
                . 'duplicate; discarding risks a message the customer got and we think failed.',
            'evidence' => [
                ['label' => 'Submission unknown', 'value' => (string) $count, 'source' => 'Messaging'],
            ],
            'source'     => 'messaging',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'delivery', 'label' => 'Reconcile', 'params' => ['status' => 'submission_unknown']],
        ];
    }

    /**
     * Conversations that cannot be answered because information is missing.
     *
     * The brief's "missing information preventing a reply". Expressed against
     * facts: a conversation with no matched contact, where Contacts is
     * connected, cannot have its history read.
     *
     * @return array<string, mixed>|null
     */
    private static function missingContextBlockingReplies(Context $ctx, Auth $auth): ?array
    {
        if (!Features::enabled('CONTACTS')) {
            return null;
        }

        [$scope, $params] = $ctx->scopeClause('c');

        $count = (int) (Db::scalar(
            'SELECT COUNT(*) FROM messaging_conversations c
             WHERE ' . $scope . '
               AND c.status <> \'resolved\'
               AND c.contact_uuid IS NULL
               AND c.last_inbound_at IS NOT NULL
               AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)',
            $params,
        ) ?? 0);

        if ($count === 0) {
            return null;
        }

        return [
            'key'      => 'unmatched_contacts',
            'weight'   => 60,
            'title'    => $count . ' waiting ' . ($count === 1 ? 'conversation is' : 'conversations are') . ' from an unknown number',
            'detail'   => 'Their order and invoice history cannot be shown until they are matched to a contact.',
            'kind'     => 'verified_fact',
            'why'      => 'These conversations have no contact_uuid, so Aicountly Contacts cannot be asked who they '
                . 'are and Books cannot be asked what they owe.',
            'evidence' => [
                ['label' => 'Unmatched waiting conversations', 'value' => (string) $count, 'source' => 'Messaging'],
            ],
            'source'     => 'messaging',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'inbox', 'label' => 'Match contacts', 'params' => ['unmatched' => '1']],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function channelsNeedingAttention(Context $ctx, Auth $auth): ?array
    {
        if (!Permissions::allows($ctx, $auth, 'messaging.channels.view')) {
            return null;
        }

        $rows = Db::all(
            'SELECT channel, status, status_detail FROM messaging_channel_connections
             WHERE cmp_id = :cmp AND is_active = TRUE AND status <> :connected',
            ['cmp' => $ctx->cmpId, 'connected' => 'connected'],
        );

        if ($rows === []) {
            return null;
        }

        $evidence = [];
        foreach ($rows as $row) {
            $evidence[] = [
                'label'  => ucfirst((string) $row['channel']),
                'value'  => str_replace('_', ' ', (string) $row['status'])
                    . ((string) ($row['status_detail'] ?? '') !== '' ? ' — ' . (string) $row['status_detail'] : ''),
                'source' => 'Messaging channel configuration',
            ];
        }

        return [
            'key'      => 'channels_not_connected',
            'weight'   => 70,
            'title'    => count($rows) . ' ' . (count($rows) === 1 ? 'channel needs' : 'channels need') . ' setting up',
            'detail'   => 'Messages cannot be sent on a channel that is not connected.',
            'kind'     => 'verified_fact',
            'why'      => 'These channel connections exist but are not in the connected state.',
            'evidence' => $evidence,
            'source'     => 'messaging',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'trust', 'label' => 'Finish setup', 'params' => []],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function templatesRejected(Context $ctx, Auth $auth): ?array
    {
        if (!Permissions::allows($ctx, $auth, 'messaging.templates.view')) {
            return null;
        }

        $rows = Db::all(
            'SELECT t.name, v.version, v.language, v.provider_status, v.provider_rejection_reason
             FROM messaging_template_versions v
             JOIN messaging_templates t ON t.template_uuid = v.template_uuid
             WHERE v.cmp_id = :cmp AND v.provider_status IN (:rejected, :paused, :disabled)
             ORDER BY v.provider_status_read_at DESC NULLS LAST LIMIT 5',
            ['cmp' => $ctx->cmpId, 'rejected' => 'rejected', 'paused' => 'paused', 'disabled' => 'disabled'],
        );

        if ($rows === []) {
            return null;
        }

        $evidence = [];
        foreach ($rows as $row) {
            $evidence[] = [
                'label'  => (string) $row['name'] . ' v' . (int) $row['version'] . ' (' . (string) $row['language'] . ')',
                'value'  => (string) $row['provider_status']
                    . ((string) ($row['provider_rejection_reason'] ?? '') !== ''
                        ? ' — ' . (string) $row['provider_rejection_reason'] : ''),
                'source' => 'Provider template status',
            ];
        }

        return [
            'key'      => 'templates_not_approved',
            'weight'   => 65,
            'title'    => count($rows) . ' ' . (count($rows) === 1 ? 'template was' : 'templates were')
                . ' rejected or paused by the provider',
            'detail'   => 'Journeys that use them cannot send.',
            'kind'     => 'verified_fact',
            'why'      => 'The provider reported these statuses. Messaging records them and does not decide them.',
            'evidence' => $evidence,
            'source'     => 'provider',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'templates', 'label' => 'Fix templates', 'params' => ['status' => 'rejected']],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function journeysPaused(Context $ctx, Auth $auth): ?array
    {
        if (!Permissions::allows($ctx, $auth, 'messaging.journeys.view')) {
            return null;
        }

        [$scope, $params] = $ctx->scopeClause('r');

        $rows = Db::all(
            'SELECT j.name, r.outcome, COUNT(*) AS n
             FROM messaging_journey_runs r
             JOIN messaging_journeys j ON j.journey_uuid = r.journey_uuid
             WHERE ' . $scope . ' AND r.mode = \'live\' AND r.status = :paused
             GROUP BY j.name, r.outcome ORDER BY n DESC LIMIT 5',
            $params + ['paused' => 'paused'],
        );

        $total = array_sum(array_map(static fn (array $r) => (int) $r['n'], $rows));
        if ($total === 0) {
            return null;
        }

        $evidence = [];
        foreach ($rows as $row) {
            $evidence[] = [
                'label'  => (string) $row['name'],
                'value'  => (int) $row['n'] . ' paused — ' . str_replace('_', ' ', (string) $row['outcome']),
                'source' => 'Messaging journey runs',
            ];
        }

        return [
            'key'      => 'journey_runs_paused',
            'weight'   => 72,
            'title'    => $total . ' journey ' . ($total === 1 ? 'run is' : 'runs are') . ' paused',
            'detail'   => 'Each one stopped rather than sending from data it could not confirm.',
            'kind'     => 'verified_fact',
            'why'      => 'A run pauses when a source cannot be read, a template is not approved, or a channel is '
                . 'unavailable. Nothing was sent.',
            'evidence' => $evidence,
            'source'     => 'messaging',
            'fetched_at' => gmdate('c'),
            'action'     => ['route' => 'journeys', 'label' => 'Review runs', 'params' => ['status' => 'paused']],
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Optional prose over the rule-based suggestions.
     *
     * Where no model is configured this returns null and the UI shows the
     * rule-based titles, which were never dependent on a model. The brief's
     * "do not manufacture predictions when a suitable model is unavailable" is
     * satisfied by there being nothing to manufacture.
     *
     * @param list<array<string, mixed>> $suggestions
     */
    public static function narrate(Context $ctx, Auth $auth, array $suggestions): ?array
    {
        if ($suggestions === [] || !AiClient::isAvailable()) {
            return null;
        }
        if (!(bool) (Settings::for($ctx)['ai_suggest_allowed'] ?? false)) {
            return null;
        }

        $facts = [];
        foreach ($suggestions as $suggestion) {
            $facts[] = '- ' . $suggestion['title'] . '. ' . $suggestion['detail'];
        }

        $system = <<<'PROMPT'
        You write one short note for the person running a messaging operation.

        RULES:
        - Use ONLY the counts in the list. Never introduce a figure.
        - No predictions, no probabilities, no invented precision.
        - Say which one to do first and why, in plain English.
        - Two sentences at most. No preamble, no bullet points.
        PROMPT;

        $result = AiClient::complete($system, implode("\n", $facts), 180);
        AiClient::logRun($ctx, $auth, \Aicountly\Api\Support\Uuid::v4(), 'narrate_actions', null, $result);

        if (!$result['ok']) {
            return null;
        }

        return [
            'text'      => (string) $result['text'],
            'kind'      => 'observation',
            'kind_note' => 'A model wrote this sentence from the counts above. The counts themselves are not its work.',
        ];
    }

    /** @return list<string> */
    private static function dismissedKeys(Context $ctx, Auth $auth): array
    {
        $rows = Db::all(
            'SELECT suggestion_key FROM messaging_dismissed_suggestions
             WHERE cmp_id = :cmp AND user_uuid = :uuid
               AND (expires_at IS NULL OR expires_at > NOW())',
            ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
        );

        return array_map(static fn (array $r) => (string) $r['suggestion_key'], $rows);
    }

    /**
     * Dismiss a suggestion for a while.
     *
     * With an expiry, because "3 delivery failures need investigating" is worth
     * raising again next week if it is still true.
     */
    public static function dismiss(Context $ctx, Auth $auth, string $key, int $days = 7): void
    {
        Db::run(
            'INSERT INTO messaging_dismissed_suggestions (cmp_id, user_uuid, suggestion_key, dismissed_at, expires_at)
             VALUES (:cmp, :uuid, :key, NOW(), NOW() + (:days || \' days\')::interval)
             ON CONFLICT (cmp_id, user_uuid, suggestion_key)
             DO UPDATE SET dismissed_at = NOW(), expires_at = NOW() + (:days || \' days\')::interval',
            ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid, 'key' => $key, 'days' => (string) max(1, min(90, $days))],
        );
    }
}
