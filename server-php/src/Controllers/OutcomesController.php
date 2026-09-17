<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\OutcomeInsights;
use Aicountly\Api\Audit;
use Aicountly\Api\Domain\OutcomeService;
use Aicountly\Api\Domain\Period;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class OutcomesController extends Controller
{
    public static function show(): void
    {
        [$auth, $ctx] = self::enter('messaging.outcomes.view');

        $period = Period::fromRequest($ctx, '30d');
        $windowHours = Http::intParam('window_hours', 72) ?? 72;

        Http::data(
            OutcomeService::overview($ctx, $auth, $period, ['window_hours' => $windowHours])
            + ['insights' => OutcomeInsights::for($ctx, $auth, $period)],
        );
    }

    public static function evidence(): void
    {
        [$auth, $ctx] = self::enter('messaging.outcomes.view');

        $period = Period::fromRequest($ctx, '30d');
        $params = Http::listParams(['outcome_at'], 'outcome_at');

        $result = OutcomeService::evidence($ctx, $period, [
            'owner_product' => Http::param('owner_product', ''),
            'primary_only'  => !Http::boolParam('include_superseded'),
        ], $params['limit'], $params['offset']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset'], [
            'period' => $period->describe(),
            'note'   => 'Each row records WHICH outcome was linked, by what method, and under which window. '
                . 'The amounts are fetched live from the owning product when the summary renders — they are not '
                . 'stored here, so a reversed payment stops counting.',
            'includes_superseded' => Http::boolParam('include_superseded'),
        ]);
    }

    /** Link an outcome by hand. Recorded as somebody's stated opinion. */
    public static function link(): void
    {
        [$auth, $ctx] = self::enter('messaging.outcomes.view');
        Permissions::assert($ctx, $auth, 'messaging.conversations.reply');

        $body = Http::body();

        $result = OutcomeService::link(
            $ctx,
            $auth,
            (string) ($body['owner_product'] ?? ''),
            (string) ($body['outcome_kind'] ?? ''),
            (string) ($body['external_id'] ?? ''),
            'manual',
            isset($body['conversation_uuid']) ? (string) $body['conversation_uuid'] : null,
            isset($body['message_uuid']) ? (string) $body['message_uuid'] : null,
            null,
            isset($body['message_at']) ? (string) $body['message_at'] : null,
            isset($body['outcome_at']) ? (string) $body['outcome_at'] : null,
            (string) ($body['external_label'] ?? ''),
            (int) ($body['window_hours'] ?? 72),
        );

        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'outcome.linked', 'outcome', $result['outcome_uuid'], null, [
            'owner_product' => (string) ($body['owner_product'] ?? ''),
            'external_id'   => (string) ($body['external_id'] ?? ''),
            'match_method'  => 'manual',
        ]);

        Http::data([
            'outcome_uuid' => $result['outcome_uuid'],
            'detail'       => $result['detail'],
            'note'         => 'Recorded as a manual link. The screen labels it as somebody\'s judgement rather '
                . 'than an automatic match.',
        ], 201);
    }

    /**
     * Export.
     *
     * ## Exports carry the SAME permissions as the screen
     *
     * `messaging.export` on top of `messaging.outcomes.view`, and the data is
     * fetched fresh under the caller's own session — so an export cannot show
     * something the screen would have refused.
     *
     * ## An export is not a synchronisation mechanism
     *
     * It is a file a person downloads. It is bounded, it is audited, and it
     * carries the attribution caveats in the file itself so a spreadsheet
     * detached from this screen still says what the numbers mean.
     */
    public static function export(): void
    {
        [$auth, $ctx] = self::enter('messaging.outcomes.view');
        Permissions::assert($ctx, $auth, 'messaging.export');

        $period = Period::fromRequest($ctx, '30d');
        $result = OutcomeService::evidence($ctx, $period, ['primary_only' => true], 1000, 0);

        Audit::record($ctx, $auth, 'outcomes.exported', 'outcome', null, null, [
            'period' => $period->key,
            'rows'   => count($result['rows']),
        ]);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                'outcome_at'        => (string) ($row['outcome_at'] ?? ''),
                'owner_product'     => (string) $row['owner_product'],
                'outcome_kind'      => (string) $row['outcome_kind'],
                'external_id'       => (string) $row['external_id'],
                'external_label'    => (string) ($row['external_label'] ?? ''),
                'match_method'      => (string) $row['match_method'],
                'window_hours'      => (int) $row['window_hours'],
                'window_timezone'   => (string) $row['window_timezone'],
                'message_at'        => (string) ($row['message_at'] ?? ''),
                'conversation'      => (string) ($row['customer_address'] ?? ''),
                'channel'           => (string) ($row['channel'] ?? ''),
                'journey'           => (string) ($row['journey_name'] ?? ''),
            ];
        }

        Http::data([
            'period'   => $period->describe(),
            'rows'     => $rows,
            'row_count' => count($rows),
            'truncated' => $result['total'] > count($rows),
            'truncated_note' => $result['total'] > count($rows)
                ? 'This export is limited to 1,000 rows and ' . $result['total'] . ' matched. Narrow the period.'
                : null,
            // The caveats travel WITH the data, because a spreadsheet outlives
            // the screen it came from.
            'caveats'  => [
                'These outcomes are LINKED to messaging activity within the stated window. That is correlation, '
                . 'not proof that messaging caused them.',
                'Amounts are deliberately absent from this export. They live in Aicountly Books, Sales and Pay and '
                . 'change after the fact — a payment can be reversed. Read them there against the references here.',
                'One outcome appears at most once: a payment preceded by several reminders is counted against the '
                . 'most recent one only.',
            ],
            'generated_at' => \Aicountly\Api\Support\Clock::iso(),
            'generated_by' => $auth->uuid,
        ]);
    }
}
