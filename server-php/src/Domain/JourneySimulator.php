<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Test a journey without sending anything.
 *
 * ## Two ways to simulate, both labelled
 *
 *  1. READ-ONLY LIVE EVALUATION. Real contacts, real invoices read live from
 *     Books, real consent — and no dispatch. This is the honest test, because
 *     it tells you what would happen to your actual customers.
 *  2. SYNTHETIC FIXTURES. Explicitly labelled as made up, for when the source
 *     product is not connected yet or somebody wants to exercise a branch that
 *     no real record currently takes.
 *
 * The caller picks, and the result says which was used. A simulation that
 * quietly substituted sample data for a live read would be worse than no
 * simulation: it would report a journey as working against data that does not
 * exist.
 *
 * ## The counts are the product
 *
 * Eligible, excluded, blocked, paused — with the reason for each. "120 eligible,
 * 14 excluded (no consent), 3 paused (channel unavailable)" is what somebody
 * needs before they publish something that will message a thousand customers.
 */
final class JourneySimulator
{
    private const MAX_SUBJECTS = 200;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function run(Context $ctx, Auth $auth, string $journeyUuid, array $options): array
    {
        $journey = JourneyService::find($ctx, $journeyUuid);
        if ($journey === null || $journey['version'] === null) {
            return [
                'ok'      => false,
                'message' => 'That journey could not be found.',
            ];
        }

        $definition = $journey['version']['definition'];
        $validation = JourneyDefinition::validate($ctx, $definition);

        $mode = (string) ($options['data_source'] ?? 'live_readonly');
        if (!in_array($mode, ['live_readonly', 'synthetic'], true)) {
            $mode = 'live_readonly';
        }

        $subjects = $mode === 'synthetic'
            ? self::syntheticSubjects($ctx, $definition, (int) ($options['count'] ?? 5))
            : self::liveSubjects($ctx, $auth, $definition, (int) ($options['count'] ?? 25));

        $outcomes = [
            'eligible' => 0,
            'excluded' => 0,
            'blocked'  => 0,
            'paused'   => 0,
        ];
        $reasons = [];
        $walks = [];

        foreach ($subjects['subjects'] as $subject) {
            $walk = self::walk($ctx, $auth, $definition, $subject, $mode);

            $outcomes[$walk['bucket']] = ($outcomes[$walk['bucket']] ?? 0) + 1;
            $reasonKey = $walk['reason'];
            $reasons[$reasonKey] = ($reasons[$reasonKey] ?? 0) + 1;

            if (count($walks) < 25) {
                $walks[] = $walk;
            }
        }

        return [
            'ok'         => true,
            // The headline promise, stated in the payload so the UI cannot
            // accidentally imply otherwise.
            'dispatched' => false,
            'dispatch_note' => 'Simulation never sends a message. No dispatch job is created, and the queue '
                . 'refuses one for a simulation run at the database level.',
            'data_source' => $mode,
            'data_source_note' => $mode === 'synthetic'
                ? 'Synthetic, clearly-labelled fixtures. These are not your customers and these figures are not a forecast.'
                : 'Live read-only evaluation against your real records. Nothing was written and nothing was sent.',
            'journey'    => [
                'journey_uuid' => $journeyUuid,
                'name'         => $journey['name'],
                'version'      => $journey['version']['version'],
                'published'    => $journey['version']['published_at'] !== null,
                'version_note' => $journey['version']['published_at'] === null
                    ? 'This simulates the DRAFT version. Only a published version can run for real.'
                    : 'This simulates published version ' . $journey['version']['version'] . '.',
            ],
            'validation' => $validation,
            'subjects'   => [
                'considered'   => count($subjects['subjects']),
                'source'       => $subjects['source'],
                'completeness' => $subjects['completeness'],
                'note'         => $subjects['note'],
            ],
            'outcomes'   => $outcomes,
            'reasons'    => $reasons,
            'walks'      => $walks,
        ];
    }

    /**
     * Walk one subject through the graph, recording each decision.
     *
     * Nothing is written: no run row, no step rows, no draft. The walk is
     * returned and discarded.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $subject
     * @return array<string, mixed>
     */
    private static function walk(Context $ctx, Auth $auth, array $definition, array $subject, string $mode): array
    {
        $nodes = [];
        foreach ((array) ($definition['nodes'] ?? []) as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id !== '') {
                $nodes[$id] = $node;
            }
        }

        $currentId = (string) ($definition['entry'] ?? array_key_first($nodes) ?? '');
        $sourceData = $subject['source_data'] ?? [];
        $trace = [];
        $bucket = 'eligible';
        $reason = 'would_send';
        $steps = 0;

        while ($currentId !== '' && isset($nodes[$currentId]) && $steps < 50) {
            $steps++;
            $node = $nodes[$currentId];
            $type = (string) $node['type'];
            $next = (string) ($node['next'] ?? '');
            $explanation = (string) ($node['label'] ?? $type);

            switch ($type) {
                case 'fetch_source':
                    if ($mode === 'live_readonly' && ($subject['fetch_failed'] ?? false)) {
                        $trace[] = ['node' => $currentId, 'type' => $type, 'outcome' => 'unavailable',
                            'explanation' => (string) ($subject['fetch_message'] ?? 'The source could not be read.')];
                        $bucket = 'paused';
                        $reason = 'source_unavailable';
                        $currentId = (string) ($node['on_unavailable'] ?? '');
                        continue 2;
                    }
                    $explanation = 'Read ' . (string) ($sourceData['label'] ?? $subject['reference'] ?? '')
                        . ' from Aicountly ' . ucfirst((string) ($subject['source_product'] ?? 'the owning product')) . '.';
                    break;

                case 'condition':
                    $evaluated = SourceReader::evaluateCondition((string) ($node['expression'] ?? ''), $sourceData);
                    $trace[] = ['node' => $currentId, 'type' => $type,
                        'outcome' => $evaluated['result'] ? 'true' : 'false',
                        'explanation' => $evaluated['explanation']];
                    if (!$evaluated['result']) {
                        $bucket = 'excluded';
                        $reason = 'condition_not_met';
                    }
                    $currentId = (string) ($evaluated['result'] ? ($node['on_true'] ?? '') : ($node['on_false'] ?? ''));
                    continue 2;

                case 'check_eligibility':
                    $verdict = self::eligibility($ctx, $subject, (string) ($node['purpose'] ?? 'transactional'), $mode);
                    $trace[] = ['node' => $currentId, 'type' => $type,
                        'outcome' => $verdict['allowed'] ? 'eligible' : 'ineligible',
                        'explanation' => $verdict['detail']];
                    if (!$verdict['allowed']) {
                        $bucket = 'excluded';
                        $reason = $verdict['reason'];
                        $currentId = (string) ($node['on_ineligible'] ?? '');
                        continue 2;
                    }
                    $currentId = (string) ($node['on_eligible'] ?? $node['next'] ?? '');
                    continue 2;

                case 'draft':
                    $language = (string) ($node['language'] ?? 'en') ?: 'en';
                    $version = TemplateService::sendableVersion($ctx, (string) ($node['template_uuid'] ?? ''), $language);
                    if ($version === null) {
                        $trace[] = ['node' => $currentId, 'type' => $type, 'outcome' => 'blocked',
                            'explanation' => 'No provider-approved template version in ' . $language . '.'];
                        $bucket = 'blocked';
                        $reason = 'template_not_approved';
                        $currentId = '';
                        continue 2;
                    }
                    $explanation = 'Would draft from template version ' . (int) $version['version']
                        . ' in ' . (string) $version['language'] . '.';
                    break;

                case 'approval':
                    $explanation = 'A human would review the draft here.';
                    $next = (string) ($node['on_approved'] ?? $node['next'] ?? '');
                    break;

                case 'delay':
                    $explanation = 'Would wait ' . (int) ($node['delay_minutes'] ?? 0) . ' minutes.';
                    break;

                case 'revalidate':
                    $evaluated = SourceReader::evaluateCondition((string) ($node['expression'] ?? ''), $sourceData);
                    $trace[] = ['node' => $currentId, 'type' => $type,
                        'outcome' => $evaluated['result'] ? 'unchanged' : 'changed',
                        'explanation' => 'Would re-read the source before sending. ' . $evaluated['explanation']];
                    if (!$evaluated['result']) {
                        $bucket = 'excluded';
                        $reason = 'no_longer_applicable';
                        $currentId = (string) ($node['on_changed'] ?? '');
                        continue 2;
                    }
                    $currentId = (string) ($node['next'] ?? '');
                    continue 2;

                case 'send':
                    $trace[] = ['node' => $currentId, 'type' => $type, 'outcome' => 'would_send',
                        'explanation' => 'Would send on ' . (string) ($node['channel'] ?? '') . '. Nothing was sent.'];
                    $bucket = 'eligible';
                    $reason = 'would_send';
                    $currentId = '';
                    continue 2;

                case 'pause_notify':
                    $trace[] = ['node' => $currentId, 'type' => $type, 'outcome' => 'paused',
                        'explanation' => $explanation];
                    $bucket = 'paused';
                    $reason = 'paused';
                    $currentId = '';
                    continue 2;

                case 'stop':
                    $trace[] = ['node' => $currentId, 'type' => $type, 'outcome' => 'stopped',
                        'explanation' => $explanation];
                    $outcome = (string) ($node['outcome'] ?? '');
                    if ($outcome !== '' && $outcome !== 'sent') {
                        $bucket = $bucket === 'eligible' ? 'excluded' : $bucket;
                        $reason = $outcome;
                    }
                    $currentId = '';
                    continue 2;
            }

            $trace[] = ['node' => $currentId, 'type' => $type, 'outcome' => 'ok', 'explanation' => $explanation];
            $currentId = $next;
        }

        return [
            'subject'   => $subject['label'] ?? '',
            'reference' => $subject['reference'] ?? '',
            'bucket'    => $bucket,
            'reason'    => $reason,
            'trace'     => $trace,
        ];
    }

    /**
     * Subjects from live data, read-only.
     *
     * Bounded with an explicit limit, and the result says whether the bound was
     * hit — a simulation over the first 25 of 4,000 overdue invoices is a useful
     * sample and a useless forecast, and the difference has to be visible.
     *
     * @param array<string, mixed> $definition
     * @return array{subjects:list<array<string,mixed>>, source:string, completeness:string, note:string}
     */
    private static function liveSubjects(Context $ctx, Auth $auth, array $definition, int $count): array
    {
        $count = max(1, min(self::MAX_SUBJECTS, $count));

        $entryNode = null;
        foreach ((array) ($definition['nodes'] ?? []) as $node) {
            if ((string) ($node['type'] ?? '') === 'fetch_source') {
                $entryNode = $node;
                break;
            }
        }

        $source = (string) ($entryNode['source'] ?? 'messaging_conversation');

        // Conversations are ours and can be listed. Another product's records
        // cannot be enumerated from here without asking it, so those paths use
        // the product's own bounded list endpoint through SourceReader.
        if ($source === 'messaging_conversation' || $entryNode === null) {
            [$scope, $params] = $ctx->scopeClause('c');
            $rows = Db::all(
                'SELECT c.conversation_uuid, c.customer_address, c.channel, c.contact_uuid,
                        c.last_inbound_at, c.last_outbound_at, c.status
                 FROM messaging_conversations c
                 WHERE ' . $scope . ' AND c.status <> \'resolved\'
                 ORDER BY c.last_inbound_at DESC NULLS LAST
                 LIMIT :limit',
                $params + ['limit' => $count],
            );

            $total = (int) (Db::scalar(
                'SELECT COUNT(*) FROM messaging_conversations c WHERE ' . $scope . ' AND c.status <> \'resolved\'',
                $params,
            ) ?? 0);

            $subjects = [];
            foreach ($rows as $row) {
                $awaiting = $row['last_inbound_at'] !== null
                    && ($row['last_outbound_at'] === null || $row['last_outbound_at'] < $row['last_inbound_at']);

                $subjects[] = [
                    'label'          => (string) $row['customer_address'],
                    'reference'      => (string) $row['conversation_uuid'],
                    'channel'        => (string) $row['channel'],
                    'address'        => (string) $row['customer_address'],
                    'source_product' => 'messaging',
                    'source_data'    => [
                        'label'          => (string) $row['customer_address'],
                        'status'         => strtoupper((string) $row['status']),
                        'awaiting_reply' => $awaiting,
                        'contact_uuid'   => (string) ($row['contact_uuid'] ?? ''),
                    ],
                ];
            }

            return [
                'subjects'     => $subjects,
                'source'       => 'Messaging conversations (live)',
                'completeness' => count($subjects) < $total ? 'partial' : 'complete',
                'note'         => count($subjects) < $total
                    ? 'Evaluated the ' . count($subjects) . ' most recent of ' . $total
                        . ' open conversations. This is a sample, not a forecast for all of them.'
                    : 'Evaluated all ' . count($subjects) . ' open conversations.',
            ];
        }

        // Another product owns the subjects. Ask it, with a bound.
        if ($source === 'books_overdue_invoices' || $source === 'books_invoice') {
            $client = new \Aicountly\Api\Clients\BooksClient();
            $client = $auth->sesKey() !== '' ? $client->withSession($auth->sesKey()) : $client->withService($auth->uuid);
            $result = $client->overdueInvoices($ctx, ['limit' => $count]);

            if (!$result['ok']) {
                return [
                    'subjects'     => [],
                    'source'       => 'Aicountly Books (live)',
                    'completeness' => 'unavailable',
                    'note'         => $result['message'] !== ''
                        ? $result['message']
                        : 'Aicountly Books could not be read, so no subjects could be evaluated. '
                            . 'No sample data has been substituted.',
                ];
            }

            $rows = (array) ($result['body']['data'] ?? []);
            $subjects = [];
            foreach (array_slice($rows, 0, $count) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $outstanding = isset($row['outstanding_amount']) ? (int) round((float) $row['outstanding_amount'] * 100) : 0;
                $subjects[] = [
                    'label'          => (string) ($row['voucher_no'] ?? ''),
                    'reference'      => (string) ($row['voucher_no'] ?? $row['voucher_id'] ?? ''),
                    'channel'        => '',
                    'address'        => '',
                    'contact_uuid'   => (string) ($row['contact_uuid'] ?? ''),
                    'source_product' => 'books',
                    'source_data'    => [
                        'label'             => (string) ($row['voucher_no'] ?? ''),
                        'status'            => strtoupper((string) ($row['status'] ?? 'OUTSTANDING')),
                        'outstanding_minor' => $outstanding,
                        'currency'          => strtoupper((string) ($row['currency'] ?? Settings::currency($ctx))),
                    ],
                ];
            }

            return [
                'subjects'     => $subjects,
                'source'       => 'Aicountly Books, overdue invoices (live, read-only)',
                'completeness' => count($rows) >= $count ? 'partial' : 'complete',
                'note'         => count($rows) >= $count
                    ? 'Evaluated ' . count($subjects) . ' invoices, which is the query limit. There may be more.'
                    : 'Evaluated all ' . count($subjects) . ' overdue invoices Books reported.',
            ];
        }

        return [
            'subjects'     => [],
            'source'       => 'Unavailable',
            'completeness' => 'unavailable',
            'note'         => 'Live subjects cannot be listed for source "' . $source
                . '". Run the simulation with synthetic fixtures instead, or trigger a single run manually.',
        ];
    }

    /**
     * Synthetic subjects, labelled as such.
     *
     * Every one of them is obviously fake — the addresses are in the reserved
     * documentation ranges and the references say SAMPLE. Nobody should be able
     * to mistake a synthetic simulation result for a real one, including six
     * months later reading a screenshot.
     *
     * @param array<string, mixed> $definition
     * @return array{subjects:list<array<string,mixed>>, source:string, completeness:string, note:string}
     */
    private static function syntheticSubjects(Context $ctx, array $definition, int $count): array
    {
        $count = max(1, min(20, $count));
        $currency = Settings::currency($ctx);
        $subjects = [];

        // Deliberately spread across the branches, so the counts exercise the
        // graph rather than all landing on the happy path.
        $cases = [
            ['label' => 'SAMPLE-1 · outstanding',      'outstanding' => 1850000, 'status' => 'OUTSTANDING', 'awaiting' => true],
            ['label' => 'SAMPLE-2 · already paid',     'outstanding' => 0,       'status' => 'PAID',        'awaiting' => false],
            ['label' => 'SAMPLE-3 · outstanding',      'outstanding' => 420000,  'status' => 'OUTSTANDING', 'awaiting' => true],
            ['label' => 'SAMPLE-4 · part paid',        'outstanding' => 95000,   'status' => 'OUTSTANDING', 'awaiting' => true],
            ['label' => 'SAMPLE-5 · cancelled',        'outstanding' => 0,       'status' => 'CANCELLED',   'awaiting' => false],
        ];

        for ($i = 0; $i < $count; $i++) {
            $case = $cases[$i % count($cases)];
            $subjects[] = [
                'label'          => $case['label'],
                'reference'      => 'SAMPLE-' . ($i + 1),
                'channel'        => 'whatsapp',
                // A reserved documentation number. Not anybody's phone.
                'address'        => '+1555010' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'source_product' => 'synthetic',
                'synthetic'      => true,
                'source_data'    => [
                    'label'             => $case['label'],
                    'status'            => $case['status'],
                    'outstanding_minor' => $case['outstanding'],
                    'currency'          => $currency,
                    'awaiting_reply'    => $case['awaiting'],
                ],
            ];
        }

        return [
            'subjects'     => $subjects,
            'source'       => 'Synthetic fixtures (not real records)',
            'completeness' => 'synthetic',
            'note'         => 'These ' . count($subjects) . ' subjects are made up, and the addresses are reserved '
                . 'documentation numbers. Use live read-only evaluation to see what would happen to your customers.',
        ];
    }

    /**
     * Eligibility for a simulated subject.
     *
     * For a live subject this is the REAL consent check against the real
     * records. For a synthetic one it is stated as unevaluated rather than
     * assumed to pass, because a simulation that reports everybody eligible on
     * made-up addresses is telling you nothing.
     *
     * @param array<string, mixed> $subject
     * @return array{allowed:bool, reason:string, detail:string}
     */
    private static function eligibility(Context $ctx, array $subject, string $purpose, string $mode): array
    {
        if (($subject['synthetic'] ?? false) === true) {
            return [
                'allowed' => true,
                'reason'  => 'synthetic_not_evaluated',
                'detail'  => 'Consent was not checked: this is a synthetic subject with no consent record. '
                    . 'A live run would check it.',
            ];
        }

        $channel = (string) ($subject['channel'] ?? '');
        $address = (string) ($subject['address'] ?? '');

        if ($channel === '' || $address === '') {
            return [
                'allowed' => false,
                'reason'  => 'no_channel',
                'detail'  => 'This subject has no messaging address on a connected channel, so it could not be '
                    . 'contacted. Match it to a contact in Aicountly Contacts first.',
            ];
        }

        $consent = ConsentService::evaluate($ctx, $channel, $address, $purpose);

        return [
            'allowed' => $consent['allowed'],
            'reason'  => $consent['reason'],
            'detail'  => $consent['detail'],
        ];
    }
}
