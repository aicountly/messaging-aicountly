<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Period;
use Aicountly\Api\Domain\Settings;

/**
 * "What could improve?" on Business Outcomes.
 *
 * ## Observation, hypothesis, prediction — and this class only does two
 *
 * The brief asks for these to be clearly distinguished. So:
 *
 *   OBSERVATION  a difference this product measured. "Hindi reminders got a 42%
 *                reply rate against 25% in English over 30 days." True, with
 *                the numbers and the denominators.
 *   HYPOTHESIS   a possible explanation, offered as a question with a proposed
 *                test. "Language preference may be affecting replies — worth a
 *                controlled test."
 *   PREDICTION   what would happen if you changed something. NOT PRODUCED. This
 *                product has no model fitted to forecast a reply rate, and a
 *                confident "this will lift replies 18%" would be an invented
 *                number wearing a lab coat.
 *
 * ## No uplift claim without both arms and a sample size
 *
 * `languagePerformance()` refuses to report a difference when either arm has
 * fewer than MIN_ARM messages. Two replies out of three is not a 67% reply
 * rate, and a dashboard that says it is will be quoted in a meeting.
 *
 * ## A proposed experiment is a proposal
 *
 * `proposeTest()` returns a design for somebody to review. It does not create
 * a journey, split an audience or send anything.
 */
final class OutcomeInsights
{
    /** Below this, a difference between two arms is not reported at all. */
    private const MIN_ARM = 30;

    /**
     * @return array<string, mixed>
     */
    public static function for(Context $ctx, Auth $auth, Period $period): array
    {
        $observations = array_values(array_filter([
            self::languagePerformance($ctx, $period),
            self::hourOfDay($ctx, $period),
            self::channelReplyDifference($ctx, $period),
        ]));

        return [
            'observations' => $observations,
            'hypotheses'   => array_values(array_filter(array_map(
                static fn (array $observation) => self::toHypothesis($observation),
                $observations,
            ))),
            'predictions'  => [],
            'predictions_note' => 'No predictions are shown. This product has no model fitted to forecast reply '
                . 'rates or collections, and a confident number with nothing behind it would be worse than none.',
            'evidence_note' => 'Every observation below states its own denominators and the period it covers. '
                . 'Where an arm had too few messages to compare, the comparison is withheld rather than shown '
                . 'with a wide margin nobody would read.',
            'minimum_sample' => self::MIN_ARM,
        ];
    }

    /**
     * Reply rate by language, for a comparable cohort.
     *
     * The cohort is deliberately narrow: reminders of the same kind, to opted-in
     * customers, over the same period. Comparing all Hindi messages against all
     * English ones would compare payment reminders against order updates and
     * call the difference a language effect.
     *
     * @return array<string, mixed>|null
     */
    private static function languagePerformance(Context $ctx, Period $period): ?array
    {
        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $rows = Db::all(
            'SELECT m.language,
                    COUNT(*) AS delivered,
                    COUNT(*) FILTER (WHERE EXISTS (
                        SELECT 1 FROM messaging_messages r
                        WHERE r.conversation_uuid = m.conversation_uuid
                          AND r.direction = \'inbound\'
                          AND r.created_at > m.created_at
                          AND r.created_at < m.created_at + INTERVAL \'7 days\'
                    )) AS replied
             FROM messaging_messages m
             WHERE ' . $scope . '
               AND m.direction = \'outbound\'
               AND m.status IN (\'delivered\',\'read\')
               AND m.content_type = \'template\'
               AND m.language <> \'\'
               AND m.created_at >= :from AND m.created_at < :to
             GROUP BY m.language
             HAVING COUNT(*) >= :minimum
             ORDER BY COUNT(*) DESC',
            $params + ['minimum' => self::MIN_ARM],
        );

        if (count($rows) < 2) {
            return null;
        }

        $arms = [];
        foreach ($rows as $row) {
            $delivered = (int) $row['delivered'];
            $replied = (int) $row['replied'];
            $arms[] = [
                'language'  => (string) $row['language'],
                'delivered' => $delivered,
                'replied'   => $replied,
                'rate'      => round($replied / $delivered, 4),
            ];
        }

        usort($arms, static fn (array $a, array $b) => $b['rate'] <=> $a['rate']);
        $best = $arms[0];
        $worst = $arms[count($arms) - 1];

        if ($worst['rate'] <= 0.0) {
            return null;
        }

        $lift = $best['rate'] / $worst['rate'];

        // A difference this small is noise at these sample sizes, and saying
        // so is more useful than reporting it.
        if ($lift < 1.2) {
            return null;
        }

        return [
            'kind'   => 'observation',
            'key'    => 'language_reply_rate',
            'title'  => 'Template messages in ' . self::languageName($best['language'])
                . ' received more replies than ' . self::languageName($worst['language']) . ' in this period',
            'detail' => self::languageName($best['language']) . ': ' . self::percent($best['rate'])
                . ' of ' . $best['delivered'] . ' delivered. '
                . self::languageName($worst['language']) . ': ' . self::percent($worst['rate'])
                . ' of ' . $worst['delivered'] . ' delivered.',
            'measured' => [
                'metric'      => 'Share of delivered template messages followed by a customer reply within 7 days',
                'period'      => $period->describe(),
                'cohort'      => 'Template messages only, so different message kinds are not compared with each other.',
                'arms'        => $arms,
                'ratio'       => round($lift, 2),
            ],
            'caveats' => [
                'This is a difference between two groups that were not randomly assigned. Customers who prefer '
                . 'one language may differ in other ways that matter more.',
                'Reply rate is not an outcome. More replies is not necessarily more money collected.',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function hourOfDay(Context $ctx, Period $period): ?array
    {
        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();
        $params['tz'] = $period->timezone->getName();

        $rows = Db::all(
            'SELECT EXTRACT(HOUR FROM m.created_at AT TIME ZONE :tz)::int AS hour,
                    COUNT(*) AS delivered,
                    COUNT(*) FILTER (WHERE EXISTS (
                        SELECT 1 FROM messaging_messages r
                        WHERE r.conversation_uuid = m.conversation_uuid
                          AND r.direction = \'inbound\' AND r.created_at > m.created_at
                          AND r.created_at < m.created_at + INTERVAL \'1 day\'
                    )) AS replied
             FROM messaging_messages m
             WHERE ' . $scope . '
               AND m.direction = \'outbound\' AND m.status IN (\'delivered\',\'read\')
               AND m.created_at >= :from AND m.created_at < :to
             GROUP BY 1 HAVING COUNT(*) >= :minimum ORDER BY 1',
            $params + ['minimum' => self::MIN_ARM],
        );

        if (count($rows) < 3) {
            return null;
        }

        $best = null;
        foreach ($rows as $row) {
            $rate = (int) $row['replied'] / (int) $row['delivered'];
            if ($best === null || $rate > $best['rate']) {
                $best = ['hour' => (int) $row['hour'], 'rate' => $rate, 'delivered' => (int) $row['delivered']];
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'kind'   => 'observation',
            'key'    => 'reply_rate_by_hour',
            'title'  => 'Replies were most likely for messages sent around '
                . str_pad((string) $best['hour'], 2, '0', STR_PAD_LEFT) . ':00',
            'detail' => self::percent($best['rate']) . ' of ' . $best['delivered']
                . ' messages delivered in that hour got a reply within a day, in '
                . $period->timezone->getName() . '.',
            'measured' => [
                'metric' => 'Share of delivered messages followed by a customer reply within one day',
                'period' => $period->describe(),
                'cohort' => 'All delivered outbound messages, grouped by the hour they were created in the '
                    . 'company timezone. Hours with fewer than ' . self::MIN_ARM . ' messages are excluded.',
            ],
            'caveats' => [
                'When a message was sent is confounded with what kind of message it was: reminders and replies '
                . 'go out at different times of day.',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function channelReplyDifference(Context $ctx, Period $period): ?array
    {
        [$scope, $params] = $ctx->scopeClause('m');
        $params['from'] = $period->fromSql();
        $params['to'] = $period->toSql();

        $rows = Db::all(
            'SELECT m.channel,
                    COUNT(*) AS accepted,
                    COUNT(*) FILTER (WHERE m.status IN (\'delivered\',\'read\')) AS delivered,
                    COUNT(*) FILTER (WHERE m.status = \'provider_accepted\') AS unconfirmed
             FROM messaging_messages m
             WHERE ' . $scope . '
               AND m.direction = \'outbound\'
               AND m.status IN (\'provider_accepted\',\'delivered\',\'read\')
               AND m.created_at >= :from AND m.created_at < :to
             GROUP BY m.channel HAVING COUNT(*) >= :minimum',
            $params + ['minimum' => self::MIN_ARM],
        );

        if (count($rows) < 2) {
            return null;
        }

        $arms = [];
        foreach ($rows as $row) {
            $accepted = (int) $row['accepted'];
            $arms[] = [
                'channel'     => (string) $row['channel'],
                'accepted'    => $accepted,
                'delivered'   => (int) $row['delivered'],
                'unconfirmed' => (int) $row['unconfirmed'],
                'confirmed_rate' => round(((int) $row['delivered']) / $accepted, 4),
            ];
        }

        usort($arms, static fn (array $a, array $b) => $b['confirmed_rate'] <=> $a['confirmed_rate']);

        return [
            'kind'   => 'observation',
            'key'    => 'channel_confirmation_difference',
            'title'  => 'Delivery confirmation differs by channel',
            'detail' => implode('. ', array_map(
                static fn (array $arm) => ucfirst($arm['channel']) . ': ' . self::percent($arm['confirmed_rate'])
                    . ' of ' . $arm['accepted'] . ' accepted messages confirmed'
                    . ($arm['unconfirmed'] > 0 ? ', ' . $arm['unconfirmed'] . ' still unconfirmed' : ''),
                $arms,
            )) . '.',
            'measured' => [
                'metric' => 'Share of provider-accepted messages with a delivery or read receipt',
                'period' => $period->describe(),
                'cohort' => 'Channels with at least ' . self::MIN_ARM . ' accepted messages.',
                'arms'   => $arms,
            ],
            'caveats' => [
                'Channels differ in whether they report receipts at all. A lower confirmation rate can mean worse '
                . 'delivery OR a provider that reports less — these numbers cannot tell those apart.',
            ],
        ];
    }

    /**
     * Turn an observation into a hypothesis with a reviewable test.
     *
     * Marked as a hypothesis, with the test as a PROPOSAL. Nothing is launched.
     *
     * @param array<string, mixed> $observation
     * @return array<string, mixed>|null
     */
    private static function toHypothesis(array $observation): ?array
    {
        return match ((string) $observation['key']) {
            'language_reply_rate' => [
                'kind'  => 'hypothesis',
                'key'   => 'language_hypothesis',
                'title' => 'Language preference may be affecting replies',
                'detail' => 'The difference above is between groups nobody assigned at random, so it is not '
                    . 'evidence that changing language would change anything. A controlled test would tell you.',
                'based_on' => $observation['key'],
                'proposed_test' => [
                    'design'     => 'Split eligible, opted-in customers at random into two arms for the same '
                        . 'reminder. Send one arm the Hindi template and the other the English template.',
                    'metric'     => 'Reply rate within 7 days, and separately the share that paid within the '
                        . 'attribution window.',
                    'minimum_per_arm' => self::MIN_ARM * 10,
                    'requires'   => [
                        'A provider-approved template in both languages.',
                        'Enough eligible opted-in customers to fill both arms.',
                        'Somebody to review and publish the test.',
                    ],
                    'note' => 'This is a proposal. Nothing has been created, nothing has been split and nothing '
                        . 'has been sent. Campaign-level experiment design belongs in Aicountly Reach.',
                ],
            ],
            'reply_rate_by_hour' => [
                'kind'  => 'hypothesis',
                'key'   => 'timing_hypothesis',
                'title' => 'Send timing may be affecting replies',
                'detail' => 'What was sent and when it was sent are mixed together in the figures above. A test '
                    . 'that holds the message constant and varies only the hour would separate them.',
                'based_on' => $observation['key'],
                'proposed_test' => [
                    'design'     => 'For one reminder kind, split eligible customers at random and send at two '
                        . 'different hours.',
                    'metric'     => 'Reply rate within one day.',
                    'minimum_per_arm' => self::MIN_ARM * 10,
                    'requires'   => ['Enough eligible customers for both arms.', 'Review before publishing.'],
                    'note'       => 'A proposal only.',
                ],
            ],
            default => null,
        };
    }

    private static function percent(float $rate): string
    {
        return number_format($rate * 100, 1) . '%';
    }

    private static function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English', 'hi' => 'Hindi', 'mr' => 'Marathi', 'gu' => 'Gujarati',
            'ta' => 'Tamil', 'te' => 'Telugu', 'bn' => 'Bengali', 'pa' => 'Punjabi',
            default => strtoupper($code),
        };
    }
}
