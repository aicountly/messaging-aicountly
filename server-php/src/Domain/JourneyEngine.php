<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Executes a published journey version.
 *
 * ## Simulation cannot send. Structurally.
 *
 * A run in `simulation` mode never reaches the dispatcher: the `send` node
 * checks the mode and records what WOULD have happened. Belt and braces, the
 * `mode` column on `messaging_dispatch_jobs` has `CHECK (mode = 'live')`, so a
 * simulation cannot produce a dispatch job even if this code were changed to
 * try. Two independent mechanisms, because "the test run sent real messages to
 * real customers" is not a mistake anybody recovers from.
 *
 * ## Every step says why
 *
 * A step run records an `explanation` in plain words and, where a product was
 * consulted, WHICH one and WHEN. That is the answer to "why did this customer
 * get a reminder?" and "why didn't this one?", which are the only two questions
 * anybody asks about an automation.
 *
 * ## The revalidate node is the point of the whole design
 *
 * A reminder is drafted, a human approves it an hour later, and in that hour
 * the customer pays. `revalidate` re-reads the invoice, sees it settled, and
 * cancels. No stored balance is consulted at any point — see SourceReader.
 */
final class JourneyEngine
{
    private const MAX_STEPS = 50;

    /**
     * Start a run.
     *
     * @param array<string, mixed> $trigger subject_product, subject_ref, contact_uuid, conversation_uuid, trigger_key
     * @return array{ok:bool, code:string, detail:string, run_uuid:?string, duplicate:bool}
     */
    public static function start(
        Context $ctx,
        Auth $auth,
        string $journeyUuid,
        array $trigger,
        string $mode = 'live',
        string $triggerSource = 'manual',
    ): array {
        $runnable = JourneyService::runnableVersion($ctx, $journeyUuid);

        if ($runnable === null) {
            if ($mode === 'live') {
                // A DRAFT CANNOT EXECUTE AS A PUBLISHED VERSION. This is the
                // refusal that makes the run history trustworthy.
                return [
                    'ok'     => false,
                    'code'   => 'not_published',
                    'detail' => 'This journey has no published version, so it cannot run. Publish it first.',
                    'run_uuid' => null,
                    'duplicate' => false,
                ];
            }

            // Simulation may run the draft — that is what a test is for — and
            // the run is labelled as a simulation of that draft version.
            $journey = JourneyService::find($ctx, $journeyUuid);
            if ($journey === null || $journey['version'] === null) {
                return ['ok' => false, 'code' => 'not_found', 'detail' => 'That journey could not be found.', 'run_uuid' => null, 'duplicate' => false];
            }
            $runnable = [
                'version'    => (int) $journey['version']['version'],
                'definition' => $journey['version']['definition'],
                'hash'       => (string) $journey['version']['definition_hash'],
            ];
        }

        $triggerKey = (string) ($trigger['trigger_key'] ?? '');

        // Idempotent triggering. The same invoice arriving twice from the same
        // event must not send two reminders.
        if ($triggerKey !== '' && $mode === 'live') {
            $existing = Db::first(
                'SELECT run_uuid FROM messaging_journey_runs
                 WHERE cmp_id = :cmp AND journey_uuid = :journey AND trigger_key = :key',
                ['cmp' => $ctx->cmpId, 'journey' => $journeyUuid, 'key' => $triggerKey],
            );
            if ($existing !== null) {
                return [
                    'ok'     => true,
                    'code'   => 'already_running',
                    'detail' => 'This journey has already run for that record.',
                    'run_uuid' => (string) $existing['run_uuid'],
                    'duplicate' => true,
                ];
            }
        }

        $runUuid = Uuid::v4();
        Db::insert('messaging_journey_runs', [
            'run_uuid'          => $runUuid,
            'cmp_id'            => $ctx->cmpId,
            'bo_id'             => $ctx->boId,
            'journey_uuid'      => $journeyUuid,
            'journey_version'   => $runnable['version'],
            'mode'              => $mode,
            'trigger_source'    => $triggerSource,
            'subject_product'   => (string) ($trigger['subject_product'] ?? ''),
            'subject_ref'       => (string) ($trigger['subject_ref'] ?? ''),
            'contact_uuid'      => ($trigger['contact_uuid'] ?? null) ?: null,
            'conversation_uuid' => ($trigger['conversation_uuid'] ?? null) ?: null,
            'status'            => 'running',
            'trigger_key'       => $triggerKey,
            'started_at'        => Clock::nowSql(),
        ], 'run_uuid');

        $outcome = self::advance($ctx, $auth, $runUuid, $runnable['definition'], $mode);

        return [
            'ok'        => true,
            'code'      => 'ok',
            'detail'    => $outcome['detail'],
            'run_uuid'  => $runUuid,
            'duplicate' => false,
        ];
    }

    /**
     * Walk the graph from wherever the run is.
     *
     * Re-entrant: an approval or a delay parks the run, and this is called again
     * when the approval lands or the delay expires.
     *
     * @param array<string, mixed> $definition
     * @return array{detail:string, status:string}
     */
    public static function advance(Context $ctx, Auth $auth, string $runUuid, array $definition, string $mode): array
    {
        $run = Db::first('SELECT * FROM messaging_journey_runs WHERE run_uuid = :uuid', ['uuid' => $runUuid]);
        if ($run === null) {
            return ['detail' => 'That run could not be found.', 'status' => 'failed'];
        }

        $nodes = [];
        foreach ((array) ($definition['nodes'] ?? []) as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id !== '') {
                $nodes[$id] = $node;
            }
        }

        $sequence = (int) (Db::scalar(
            'SELECT COALESCE(MAX(sequence), 0) FROM messaging_journey_step_runs WHERE run_uuid = :uuid',
            ['uuid' => $runUuid],
        ) ?? 0);

        // Resume from the step after the last one, or from the entry.
        $lastStep = Db::first(
            'SELECT node_id, status FROM messaging_journey_step_runs
             WHERE run_uuid = :uuid ORDER BY sequence DESC LIMIT 1',
            ['uuid' => $runUuid],
        );

        $currentId = (string) ($definition['entry'] ?? array_key_first($nodes) ?? '');
        if ($lastStep !== null) {
            $resumeFrom = self::nextAfterPause($nodes, (string) $lastStep['node_id']);
            if ($resumeFrom === null) {
                return ['detail' => 'The run has nothing left to do.', 'status' => (string) $run['status']];
            }
            $currentId = $resumeFrom;
        }

        // The run's working memory. Lives for this call only — nothing here is
        // written to a row.
        $sourceData = [];
        $sourceProvenance = [];
        $draftMessageUuid = null;
        $steps = 0;

        while ($currentId !== '' && isset($nodes[$currentId]) && $steps < self::MAX_STEPS) {
            $steps++;
            $sequence++;
            $node = $nodes[$currentId];
            $type = (string) $node['type'];
            $startedAt = Clock::nowSql();

            $result = match ($type) {
                'fetch_source'      => self::doFetch($ctx, $auth, $node, $run, $sourceData, $sourceProvenance),
                'condition'         => self::doCondition($node, $sourceData),
                'check_eligibility' => self::doEligibility($ctx, $node, $run),
                'draft'             => self::doDraft($ctx, $auth, $node, $run, $sourceData, $draftMessageUuid),
                'approval'          => self::doApproval($ctx, $node, $draftMessageUuid, $mode),
                'delay'             => self::doDelay($node, $mode),
                'revalidate'        => self::doRevalidate($ctx, $auth, $node, $run, $sourceData, $sourceProvenance),
                'send'              => self::doSend($ctx, $auth, $node, $run, $draftMessageUuid, $mode),
                'pause_notify'      => ['status' => 'paused', 'branch' => '', 'explanation' => 'Paused. ' . (string) ($node['label'] ?? ''), 'next' => null, 'run_status' => 'paused', 'outcome' => 'paused'],
                'stop'              => ['status' => 'ok', 'branch' => '', 'explanation' => (string) ($node['label'] ?? 'Stopped.'), 'next' => null, 'run_status' => 'completed', 'outcome' => (string) ($node['outcome'] ?? 'completed')],
                default             => ['status' => 'failed', 'branch' => '', 'explanation' => 'Unknown step type "' . $type . '".', 'next' => null, 'run_status' => 'failed', 'outcome' => 'failed'],
            };

            Db::insert('messaging_journey_step_runs', [
                'run_uuid'          => $runUuid,
                'cmp_id'            => $ctx->cmpId,
                'node_id'           => $currentId,
                'node_type'         => $type,
                'sequence'          => $sequence,
                'status'            => $result['status'],
                'branch'            => (string) ($result['branch'] ?? ''),
                'explanation'       => (string) $result['explanation'],
                'source_product'    => (string) ($sourceProvenance['product'] ?? ''),
                'source_fetched_at' => $sourceProvenance['fetched_at'] ?? null,
                'message_uuid'      => $result['message_uuid'] ?? $draftMessageUuid,
                'started_at'        => $startedAt,
                'finished_at'       => Clock::nowSql(),
            ], 'step_run_id');

            if (isset($result['message_uuid'])) {
                $draftMessageUuid = (string) $result['message_uuid'];
            }

            if (isset($result['run_status'])) {
                Db::run(
                    'UPDATE messaging_journey_runs
                     SET status = :status, outcome = :outcome, outcome_detail = :detail,
                         finished_at = CASE WHEN :status IN (\'completed\',\'cancelled\',\'failed\') THEN :now ELSE NULL END,
                         resume_after = :resume,
                         conversation_uuid = COALESCE(:conv, conversation_uuid)
                     WHERE run_uuid = :uuid',
                    [
                        'status'  => $result['run_status'],
                        'outcome' => (string) ($result['outcome'] ?? ''),
                        'detail'  => (string) $result['explanation'],
                        'now'     => Clock::nowSql(),
                        'resume'  => $result['resume_after'] ?? null,
                        'conv'    => $result['conversation_uuid'] ?? null,
                        'uuid'    => $runUuid,
                    ],
                );

                return ['detail' => (string) $result['explanation'], 'status' => (string) $result['run_status']];
            }

            $currentId = (string) ($result['next'] ?? '');
        }

        if ($steps >= self::MAX_STEPS) {
            Db::run(
                'UPDATE messaging_journey_runs SET status = :failed, outcome = :outcome,
                        outcome_detail = :detail, finished_at = :now WHERE run_uuid = :uuid',
                [
                    'failed'  => 'failed',
                    'outcome' => 'step_limit',
                    'detail'  => 'The journey did not finish within ' . self::MAX_STEPS . ' steps. It probably loops.',
                    'now'     => Clock::nowSql(),
                    'uuid'    => $runUuid,
                ],
            );

            return ['detail' => 'The journey did not finish within ' . self::MAX_STEPS . ' steps.', 'status' => 'failed'];
        }

        Db::run(
            'UPDATE messaging_journey_runs SET status = :completed, finished_at = :now WHERE run_uuid = :uuid',
            ['completed' => 'completed', 'now' => Clock::nowSql(), 'uuid' => $runUuid],
        );

        return ['detail' => 'The journey finished.', 'status' => 'completed'];
    }

    // -----------------------------------------------------------------------
    // Node handlers. Each returns: status, branch, explanation, next,
    // and optionally run_status / outcome / message_uuid / resume_after.
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $run
     * @param array<string, mixed> $sourceData
     * @param array<string, mixed> $provenance
     * @return array<string, mixed>
     */
    private static function doFetch(Context $ctx, Auth $auth, array $node, array $run, array &$sourceData, array &$provenance): array
    {
        $source = (string) ($node['source'] ?? '');
        $reference = (string) ($node['reference'] ?? $run['subject_ref'] ?? '');

        if ($source === 'messaging_conversation' && $reference === '') {
            $reference = (string) ($run['conversation_uuid'] ?? '');
        }

        if ($reference === '') {
            return [
                'status' => 'failed', 'branch' => '', 'next' => (string) ($node['on_unavailable'] ?? ''),
                'explanation' => 'This step had nothing to look up: the run carries no subject reference.',
                'run_status' => (string) ($node['on_unavailable'] ?? '') === '' ? 'failed' : null,
                'outcome' => 'no_subject',
            ];
        }

        $reading = SourceReader::read($ctx, $auth, $source, $reference);
        $provenance = ['product' => $reading['source'], 'fetched_at' => $reading['fetched_at']];

        if (!$reading['ok']) {
            // NEVER continue from a source we could not read. This is what
            // makes "source outages pause dependent sends" true.
            $next = (string) ($node['on_unavailable'] ?? '');

            return [
                'status'      => 'blocked',
                'branch'      => 'unavailable',
                'explanation' => 'Aicountly ' . ucfirst($reading['source']) . ' could not be read, so nothing was sent. '
                    . $reading['message'],
                'next'        => $next !== '' ? $next : null,
                'run_status'  => $next === '' ? 'paused' : null,
                'outcome'     => 'source_unavailable',
            ];
        }

        $sourceData = $reading['data'];

        return [
            'status'      => 'ok',
            'branch'      => '',
            'explanation' => 'Read ' . ($sourceData['label'] ?? $reference) . ' from Aicountly '
                . ucfirst($reading['source']) . '.',
            'next'        => (string) ($node['next'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $sourceData
     * @return array<string, mixed>
     */
    private static function doCondition(array $node, array $sourceData): array
    {
        $evaluated = SourceReader::evaluateCondition((string) ($node['expression'] ?? ''), $sourceData);

        return [
            'status'      => 'ok',
            'branch'      => $evaluated['result'] ? 'true' : 'false',
            'explanation' => $evaluated['explanation'],
            'next'        => (string) ($evaluated['result'] ? ($node['on_true'] ?? '') : ($node['on_false'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private static function doEligibility(Context $ctx, array $node, array $run): array
    {
        $conversationUuid = (string) ($run['conversation_uuid'] ?? '');
        if ($conversationUuid === '') {
            return [
                'status'      => 'blocked',
                'branch'      => 'ineligible',
                'explanation' => 'There is no conversation to send on, so eligibility could not be checked.',
                'next'        => (string) ($node['on_ineligible'] ?? ''),
                'run_status'  => (string) ($node['on_ineligible'] ?? '') === '' ? 'cancelled' : null,
                'outcome'     => 'no_conversation',
            ];
        }

        $conversation = Db::first(
            'SELECT customer_address, channel FROM messaging_conversations WHERE conversation_uuid = :uuid',
            ['uuid' => $conversationUuid],
        );
        if ($conversation === null) {
            return [
                'status' => 'blocked', 'branch' => 'ineligible',
                'explanation' => 'The conversation could not be found.',
                'next' => (string) ($node['on_ineligible'] ?? ''),
                'run_status' => 'cancelled', 'outcome' => 'no_conversation',
            ];
        }

        $purpose = (string) ($node['purpose'] ?? 'transactional');
        $consent = ConsentService::evaluate($ctx, (string) $conversation['channel'], (string) $conversation['customer_address'], $purpose);

        if (!$consent['allowed']) {
            return [
                'status'      => 'blocked',
                'branch'      => 'ineligible',
                'explanation' => 'Not eligible: ' . $consent['detail'],
                'next'        => (string) ($node['on_ineligible'] ?? ''),
                'run_status'  => (string) ($node['on_ineligible'] ?? '') === '' ? 'cancelled' : null,
                'outcome'     => $consent['reason'],
            ];
        }

        return [
            'status'      => 'ok',
            'branch'      => 'eligible',
            'explanation' => 'Eligible. ' . $consent['detail'],
            'next'        => (string) ($node['on_eligible'] ?? $node['next'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $run
     * @param array<string, mixed> $sourceData
     * @return array<string, mixed>
     */
    private static function doDraft(Context $ctx, Auth $auth, array $node, array $run, array $sourceData, ?string $existing): array
    {
        $conversationUuid = (string) ($run['conversation_uuid'] ?? '');
        if ($conversationUuid === '') {
            return [
                'status' => 'failed', 'branch' => '',
                'explanation' => 'There is no conversation to draft into.',
                'next' => null, 'run_status' => 'failed', 'outcome' => 'no_conversation',
            ];
        }

        $templateUuid = (string) ($node['template_uuid'] ?? '');
        $language = (string) ($node['language'] ?? 'en') ?: 'en';

        $version = TemplateService::sendableVersion($ctx, $templateUuid, $language);
        if ($version === null) {
            // The provider has not approved a version. Refused here as well as
            // at publish time, because a template can be paused by the
            // provider after a journey was published.
            return [
                'status'      => 'blocked',
                'branch'      => '',
                'explanation' => 'The template has no provider-approved version in ' . $language
                    . ', so nothing was drafted or sent.',
                'next'        => null,
                'run_status'  => 'paused',
                'outcome'     => 'template_not_approved',
            ];
        }

        $variables = self::bindVariables(Db::jsonColumn($version['variable_schema'] ?? null), $node, $sourceData, $ctx);

        $saved = MessageService::saveDraft($ctx, $auth, $conversationUuid, [
            'message_uuid'       => $existing,
            'body'               => TemplateService::render((string) $version['body'], $variables),
            'content_type'       => 'template',
            'language'           => (string) $version['language'],
            'template_uuid'      => $templateUuid,
            'template_version'   => (int) $version['version'],
            'template_variables' => $variables,
        ]);

        if (!$saved['ok']) {
            return [
                'status' => 'failed', 'branch' => '',
                'explanation' => 'The draft could not be prepared: ' . $saved['detail'],
                'next' => null, 'run_status' => 'failed', 'outcome' => 'draft_failed',
            ];
        }

        Db::run(
            'UPDATE messaging_messages SET journey_run_uuid = :run, origin = :origin WHERE message_uuid = :uuid',
            ['run' => $run['run_uuid'], 'origin' => 'JOURNEY', 'uuid' => $saved['message_uuid']],
        );

        return [
            'status'       => 'ok',
            'branch'       => '',
            'explanation'  => 'Drafted from template version ' . (int) $version['version']
                . ' in ' . (string) $version['language'] . '.',
            'next'         => (string) ($node['next'] ?? ''),
            'message_uuid' => $saved['message_uuid'],
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function doApproval(Context $ctx, array $node, ?string $messageUuid, string $mode): array
    {
        if ($mode === 'simulation') {
            return [
                'status'      => 'skipped',
                'branch'      => 'simulated',
                'explanation' => 'A human would review the draft here. Simulation does not wait for one.',
                'next'        => (string) ($node['on_approved'] ?? $node['next'] ?? ''),
            ];
        }

        if ($messageUuid === null) {
            return [
                'status' => 'failed', 'branch' => '',
                'explanation' => 'There is no draft to approve.',
                'next' => null, 'run_status' => 'failed', 'outcome' => 'no_draft',
            ];
        }

        $message = Db::first(
            'SELECT status, approved_content_hash, content_hash FROM messaging_messages WHERE message_uuid = :uuid',
            ['uuid' => $messageUuid],
        );
        if ($message === null) {
            return [
                'status' => 'failed', 'branch' => '',
                'explanation' => 'The draft could not be found.',
                'next' => null, 'run_status' => 'failed', 'outcome' => 'no_draft',
            ];
        }

        $approval = DispatchGuard::checkApproval($message + ['status' => MessageState::APPROVED]);
        $isApproved = (string) $message['status'] === MessageState::APPROVED && $approval['allowed'];

        if ($isApproved) {
            return [
                'status'      => 'ok',
                'branch'      => 'approved',
                'explanation' => 'Approved by a reviewer, and the content matches what they approved.',
                'next'        => (string) ($node['on_approved'] ?? $node['next'] ?? ''),
            ];
        }

        // Park the run. It resumes when somebody approves, through
        // ApprovalController → JourneyEngine::resume().
        Db::run(
            'UPDATE messaging_messages SET status = :awaiting WHERE message_uuid = :uuid AND status = :draft',
            ['awaiting' => MessageState::AWAITING_APPROVAL, 'draft' => MessageState::DRAFT, 'uuid' => $messageUuid],
        );

        return [
            'status'      => 'paused',
            'branch'      => 'awaiting',
            'explanation' => 'Waiting for a human to review the draft. Nothing is sent until somebody does.',
            'next'        => null,
            'run_status'  => 'awaiting_approval',
            'outcome'     => 'awaiting_approval',
        ];
    }

    /** @param array<string, mixed> $node @return array<string, mixed> */
    private static function doDelay(array $node, string $mode): array
    {
        $minutes = max(0, (int) ($node['delay_minutes'] ?? 0));

        if ($mode === 'simulation' || $minutes === 0) {
            return [
                'status'      => 'skipped',
                'branch'      => 'simulated',
                'explanation' => $minutes === 0
                    ? 'No delay configured.'
                    : 'Would wait ' . $minutes . ' minutes. Simulation does not wait.',
                'next'        => (string) ($node['next'] ?? ''),
            ];
        }

        return [
            'status'       => 'paused',
            'branch'       => 'waiting',
            'explanation'  => 'Waiting ' . $minutes . ' minutes before the next step.',
            'next'         => null,
            'run_status'   => 'running',
            'outcome'      => 'waiting',
            'resume_after' => Clock::now()->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:sP'),
        ];
    }

    /**
     * Re-read the source immediately before sending.
     *
     * THE STEP THAT MAKES A PAYMENT REMINDER SAFE. An hour may have passed
     * waiting for approval; the invoice may have been paid in it.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $run
     * @param array<string, mixed> $sourceData
     * @param array<string, mixed> $provenance
     * @return array<string, mixed>
     */
    private static function doRevalidate(Context $ctx, Auth $auth, array $node, array $run, array &$sourceData, array &$provenance): array
    {
        $source = (string) ($node['source'] ?? '');
        $reference = (string) ($node['reference'] ?? $run['subject_ref'] ?? '');
        if ($source === 'messaging_conversation' && $reference === '') {
            $reference = (string) ($run['conversation_uuid'] ?? '');
        }

        $reading = SourceReader::read($ctx, $auth, $source, $reference);
        $provenance = ['product' => $reading['source'], 'fetched_at' => $reading['fetched_at']];

        if (!$reading['ok']) {
            $next = (string) ($node['on_unavailable'] ?? '');

            return [
                'status'      => 'blocked',
                'branch'      => 'unavailable',
                'explanation' => 'Aicountly ' . ucfirst($reading['source']) . ' could not be re-read before sending, '
                    . 'so nothing was sent. ' . $reading['message'],
                'next'        => $next !== '' ? $next : null,
                'run_status'  => $next === '' ? 'paused' : null,
                'outcome'     => 'source_unavailable',
            ];
        }

        $sourceData = $reading['data'];
        $evaluated = SourceReader::evaluateCondition((string) ($node['expression'] ?? ''), $sourceData);

        if (!$evaluated['result']) {
            // The world changed. Cancel rather than send.
            $next = (string) ($node['on_changed'] ?? '');

            return [
                'status'      => 'ok',
                'branch'      => 'changed',
                'explanation' => 'Re-read Aicountly ' . ucfirst($reading['source'])
                    . ' before sending and the message is no longer appropriate. ' . $evaluated['explanation']
                    . ' Nothing was sent.',
                'next'        => $next !== '' ? $next : null,
                'run_status'  => $next === '' ? 'cancelled' : null,
                'outcome'     => 'no_longer_applicable',
            ];
        }

        return [
            'status'      => 'ok',
            'branch'      => 'unchanged',
            'explanation' => 'Re-read Aicountly ' . ucfirst($reading['source']) . ' immediately before sending. '
                . $evaluated['explanation'],
            'next'        => (string) ($node['next'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private static function doSend(Context $ctx, Auth $auth, array $node, array $run, ?string $messageUuid, string $mode): array
    {
        if ($messageUuid === null) {
            return [
                'status' => 'failed', 'branch' => '',
                'explanation' => 'There is no draft to send.',
                'next' => null, 'run_status' => 'failed', 'outcome' => 'no_draft',
            ];
        }

        // SIMULATION SENDS NOTHING. First of two independent guarantees; the
        // second is the CHECK constraint on messaging_dispatch_jobs.mode.
        if ($mode === 'simulation') {
            $message = Db::first(
                'SELECT * FROM messaging_messages WHERE message_uuid = :uuid',
                ['uuid' => $messageUuid],
            );
            $connection = $message !== null
                ? \Aicountly\Api\Channels\ChannelConnection::find($ctx->cmpId, (string) $message['connection_uuid'])
                : null;

            // The gates are still evaluated, so a simulation reports what would
            // actually have blocked the send rather than an optimistic "would
            // send".
            $verdict = ($message !== null && $connection !== null)
                ? DispatchGuard::evaluate($ctx, ['status' => MessageState::APPROVED, 'approved_content_hash' => $message['content_hash']] + $message, $connection)
                : ['allowed' => false, 'detail' => 'No channel connection.'];

            return [
                'status'      => 'skipped',
                'branch'      => 'simulated',
                'explanation' => $verdict['allowed']
                    ? 'Would send on ' . (string) ($node['channel'] ?? '') . '. No message was sent — this is a test run.'
                    : 'Would NOT send: ' . $verdict['detail'] . ' No message was sent — this is a test run.',
                'next'        => null,
                'run_status'  => 'completed',
                'outcome'     => $verdict['allowed'] ? 'simulated_send' : 'simulated_blocked',
            ];
        }

        // A journey send is approved by the journey's publication plus, where
        // the graph includes one, a human approval step. The message is marked
        // approved against its own content hash so the dispatcher's comparison
        // is meaningful.
        Db::run(
            'UPDATE messaging_messages
             SET status = :approved,
                 approved_at = COALESCE(approved_at, :now),
                 approved_by_uuid = COALESCE(approved_by_uuid, :actor),
                 approved_content_hash = content_hash
             WHERE message_uuid = :uuid AND status IN (:draft, :awaiting, :approved)',
            [
                'approved' => MessageState::APPROVED,
                'now'      => Clock::nowSql(),
                'actor'    => $auth->uuid,
                'uuid'     => $messageUuid,
                'draft'    => MessageState::DRAFT,
                'awaiting' => MessageState::AWAITING_APPROVAL,
            ],
        );

        $queued = DispatchService::enqueue($ctx, $auth, $messageUuid);

        if (!$queued['ok']) {
            return [
                'status'      => 'blocked',
                'branch'      => '',
                'explanation' => 'The message could not be queued: ' . $queued['detail'],
                'next'        => null,
                'run_status'  => 'paused',
                'outcome'     => $queued['code'],
            ];
        }

        return [
            'status'      => 'ok',
            'branch'      => '',
            'explanation' => 'Queued to send once on ' . (string) ($node['channel'] ?? '') . '.',
            'next'        => (string) ($node['next'] ?? ''),
        ];
    }

    /**
     * Resume a run that was waiting for an approval or a delay.
     *
     * @return array{detail:string, status:string}
     */
    public static function resume(Context $ctx, Auth $auth, string $runUuid): array
    {
        $run = Db::first(
            'SELECT * FROM messaging_journey_runs WHERE cmp_id = :cmp AND run_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $runUuid],
        );
        if ($run === null) {
            return ['detail' => 'That run could not be found.', 'status' => 'failed'];
        }
        if (!in_array((string) $run['status'], ['awaiting_approval', 'paused', 'running'], true)) {
            return [
                'detail' => 'This run is ' . (string) $run['status'] . ' and cannot be resumed.',
                'status'  => (string) $run['status'],
            ];
        }

        $version = Db::first(
            'SELECT definition FROM messaging_journey_versions
             WHERE journey_uuid = :uuid AND version = :ver',
            ['uuid' => $run['journey_uuid'], 'ver' => $run['journey_version']],
        );
        if ($version === null) {
            return ['detail' => 'The journey version this run executed is missing.', 'status' => 'failed'];
        }

        Db::run(
            'UPDATE messaging_journey_runs SET status = :running, resume_after = NULL WHERE run_uuid = :uuid',
            ['running' => 'running', 'uuid' => $runUuid],
        );

        return self::advance($ctx, $auth, $runUuid, Db::jsonColumn($version['definition'] ?? null), (string) $run['mode']);
    }

    // -----------------------------------------------------------------------

    /**
     * Bind template variables from the source reading and the node's own map.
     *
     * The node may map a variable to a source field (`{"amount": "source.outstanding_minor"}`)
     * or to a literal. Anything unmapped and undeclared stays unbound, which
     * DispatchGuard refuses — better an explicit refusal than a message
     * containing "{{amount}}".
     *
     * @param list<array<string, mixed>> $schema
     * @param array<string, mixed>       $node
     * @param array<string, mixed>       $sourceData
     * @return array<string, string>
     */
    private static function bindVariables(array $schema, array $node, array $sourceData, Context $ctx): array
    {
        $map = is_array($node['variables'] ?? null) ? $node['variables'] : [];
        $bound = [];

        foreach ($schema as $variable) {
            $name = (string) ($variable['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $expression = (string) ($map[$name] ?? '');
            $value = '';

            if (str_starts_with($expression, 'source.')) {
                $field = substr($expression, 7);
                $raw = $sourceData[$field] ?? null;

                // Money is formatted here rather than shipped as minor units.
                // "Your invoice for 1850000 is overdue" is a message nobody
                // should be able to send by accident.
                if ($raw !== null && str_ends_with($field, '_minor')) {
                    $currency = (string) ($sourceData['currency'] ?? Settings::currency($ctx));
                    $value = self::formatMoney((int) $raw, $currency);
                } elseif (is_scalar($raw)) {
                    $value = (string) $raw;
                }
            } elseif ($expression !== '') {
                $value = $expression;
            } elseif (isset($sourceData[$name]) && is_scalar($sourceData[$name])) {
                $value = (string) $sourceData[$name];
            }

            if ($value !== '') {
                $bound[$name] = $value;
            }
        }

        return $bound;
    }

    private static function formatMoney(int $minor, string $currency): string
    {
        $major = number_format($minor / 100, 2, '.', ',');

        return match (strtoupper($currency)) {
            'INR'   => '₹' . $major,
            'USD'   => '$' . $major,
            'EUR'   => '€' . $major,
            'GBP'   => '£' . $major,
            default => strtoupper($currency) . ' ' . $major,
        };
    }

    /**
     * Where to resume after a pause, given the node we paused on.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private static function nextAfterPause(array $nodes, string $nodeId): ?string
    {
        $node = $nodes[$nodeId] ?? null;
        if ($node === null) {
            return null;
        }

        $next = (string) ($node['on_approved'] ?? $node['next'] ?? '');

        return $next !== '' ? $next : null;
    }
}
