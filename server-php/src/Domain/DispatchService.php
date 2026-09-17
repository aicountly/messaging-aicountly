<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Channels\OutboundMessage;
use Aicountly\Api\Channels\SendResult;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * The send pipeline: enqueue, claim, dispatch, record.
 *
 * ## Exactly-once, at the storage layer
 *
 * There is a UNIQUE index on `messaging_dispatch_jobs.message_uuid`. One
 * message can have one job, ever. Two workers, a retried HTTP request and a
 * duplicated queue read all collapse onto that constraint rather than onto a
 * check somebody wrote in PHP.
 *
 * Claiming uses `FOR UPDATE SKIP LOCKED`, which is how two workers share a
 * queue without a broker: each takes rows the other has not locked, and neither
 * waits.
 *
 * ## The ambiguous submission
 *
 * If a provider call times out, we do not know whether it was accepted. This is
 * the case that separates a careful messaging product from a careless one:
 *
 *   - The message goes to `submission_unknown` and the job to `unknown`.
 *   - NOTHING IS RETRIED. A blind retry is how a customer gets two payment
 *     reminders for the same invoice.
 *   - Where the provider supports a status lookup, `reconcile()` asks it.
 *   - Where it does not, the message waits for a human on the Delivery
 *     Investigation screen. That is a worse user experience and a better
 *     product.
 *
 * ## The guard runs at claim time, not at enqueue time
 *
 * Consent is re-read, the invoice is re-read, the approval hash is re-compared
 * — in the worker, immediately before the provider call. A message queued an
 * hour ago whose customer has since opted out does not send. That is the
 * difference between checking consent and having checked consent.
 */
final class DispatchService
{
    /** Backoff for a retryable provider failure, in seconds by attempt. */
    private const BACKOFF = [0, 30, 120, 600, 3600];

    /**
     * Queue an approved message.
     *
     * @return array{ok:bool, code:string, detail:string, job_uuid:?string}
     */
    public static function enqueue(Context $ctx, Auth $auth, string $messageUuid): array
    {
        return Db::transaction(static function () use ($ctx, $auth, $messageUuid): array {
            $message = Db::first(
                'SELECT * FROM messaging_messages WHERE cmp_id = :cmp AND message_uuid = :uuid FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'uuid' => $messageUuid],
            );
            if ($message === null) {
                return ['ok' => false, 'code' => 'not_found', 'detail' => 'That message could not be found.', 'job_uuid' => null];
            }

            // Already queued? Return the existing job. An idempotent enqueue is
            // what makes a double-clicked Send button harmless.
            //
            // THIS IS CHECKED FIRST, and the order is not incidental: queueing
            // moves the message off `approved`, so a status check ahead of this
            // would answer the second click with "this message is queued to
            // send — only an approved message can be queued", which is both
            // self-contradictory and looks like the send failed.
            $existing = Db::first(
                'SELECT job_uuid, status FROM messaging_dispatch_jobs WHERE message_uuid = :uuid',
                ['uuid' => $messageUuid],
            );
            if ($existing !== null) {
                return [
                    'ok'       => true,
                    'code'     => 'already_queued',
                    'detail'   => 'This message is already queued to send.',
                    'job_uuid' => (string) $existing['job_uuid'],
                ];
            }

            $status = (string) $message['status'];
            if ($status !== MessageState::APPROVED) {
                return [
                    'ok'     => false,
                    'code'   => 'not_approved',
                    'detail' => 'This message is ' . MessageState::describe($status)
                        . '. Only an approved message can be queued.',
                    'job_uuid' => null,
                ];
            }

            $connection = ChannelConnection::find($ctx->cmpId, (string) $message['connection_uuid']);
            if ($connection === null) {
                return ['ok' => false, 'code' => 'channel_unavailable', 'detail' => 'The channel connection is missing.', 'job_uuid' => null];
            }

            // A pre-flight of the same gates, so an obviously-unsendable message
            // is refused while the user is still looking at it rather than
            // failing silently in a worker two seconds later. The worker runs
            // them again — this is a courtesy, not the check.
            $verdict = DispatchGuard::evaluate($ctx, $message, $connection);
            if (!$verdict['allowed'] && !$verdict['retryable']) {
                return ['ok' => false, 'code' => $verdict['code'], 'detail' => $verdict['detail'], 'job_uuid' => null];
            }

            $jobUuid = Uuid::v4();
            Db::insert('messaging_dispatch_jobs', [
                'job_uuid'              => $jobUuid,
                'cmp_id'                => $ctx->cmpId,
                'bo_id'                 => $ctx->boId,
                'message_uuid'          => $messageUuid,
                'run_uuid'              => $message['journey_run_uuid'],
                'connection_uuid'       => $message['connection_uuid'],
                'mode'                  => 'live',
                'approved_content_hash' => (string) ($message['approved_content_hash'] ?? ''),
                'status'                => 'queued',
                // Generated ONCE, here, and reused on every attempt. Generating
                // it per attempt would defeat the provider's own deduplication.
                'idempotency_key'       => 'msg-' . $messageUuid,
                'available_at'          => Clock::nowSql(),
                'created_at'            => Clock::nowSql(),
            ], 'job_uuid');

            Db::run(
                'UPDATE messaging_messages SET status = :queued, queued_at = :now, row_version = row_version + 1
                 WHERE message_uuid = :uuid',
                ['queued' => MessageState::QUEUED, 'now' => Clock::nowSql(), 'uuid' => $messageUuid],
            );

            Audit::record($ctx, $auth, 'message.queued', 'message', $messageUuid, null, ['job_uuid' => $jobUuid]);

            return ['ok' => true, 'code' => 'ok', 'detail' => 'Queued to send.', 'job_uuid' => $jobUuid];
        });
    }

    /**
     * Claim up to $limit jobs for this worker.
     *
     * `SKIP LOCKED` is what makes this safe to run in several processes. The
     * claim and the status change happen in one transaction, so a worker that
     * dies between them leaves the job queued rather than claimed-forever.
     *
     * @return list<array<string, mixed>>
     */
    public static function claim(string $workerId, int $limit = 10): array
    {
        return Db::transaction(static function () use ($workerId, $limit): array {
            $jobs = Db::all(
                'SELECT * FROM messaging_dispatch_jobs
                 WHERE status = :queued AND available_at <= :now
                 ORDER BY available_at
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED',
                // :now rather than SQL NOW(). The backoff that sets
                // available_at is written from Clock, and a queue that reads
                // one clock and writes another is a queue whose backoff is
                // approximately observed.
                ['queued' => 'queued', 'now' => Clock::nowSql(), 'limit' => max(1, min(100, $limit))],
            );

            foreach ($jobs as $job) {
                Db::run(
                    'UPDATE messaging_dispatch_jobs
                     SET status = :claimed, claimed_at = :now, claimed_by = :worker, attempts = attempts + 1
                     WHERE job_uuid = :uuid',
                    ['claimed' => 'claimed', 'now' => Clock::nowSql(), 'worker' => $workerId, 'uuid' => $job['job_uuid']],
                );
            }

            return $jobs;
        });
    }

    /**
     * Run one claimed job.
     *
     * @param array<string, mixed> $job
     * @return array{outcome:string, detail:string}
     */
    public static function process(array $job): array
    {
        $ctx = Context::forCompany((int) $job['cmp_id'], (int) ($job['bo_id'] ?? 0));
        $messageUuid = (string) $job['message_uuid'];

        $message = Db::first(
            'SELECT * FROM messaging_messages WHERE message_uuid = :uuid',
            ['uuid' => $messageUuid],
        );
        if ($message === null) {
            self::finishJob($job, 'cancelled', 'message_missing', 'The message no longer exists.');

            return ['outcome' => 'cancelled', 'detail' => 'The message no longer exists.'];
        }

        // Already dispatched? Another worker got there, or this job is a replay.
        // Either way nothing more should be sent.
        if (MessageState::isDispatched((string) $message['status'])) {
            self::finishJob($job, 'sent', null, null);

            return ['outcome' => 'already_sent', 'detail' => 'This message has already been dispatched.'];
        }

        $connection = ChannelConnection::find($ctx->cmpId, (string) $message['connection_uuid']);
        if ($connection === null) {
            self::fail($ctx, $message, $job, 'channel_unavailable', 'The channel connection is missing.', false);

            return ['outcome' => 'failed', 'detail' => 'The channel connection is missing.'];
        }

        // THE GATES, RUN NOW. Consent re-read, approval hash re-compared,
        // promised resources re-checked.
        $verdict = DispatchGuard::evaluate($ctx, $message, $connection);
        if (!$verdict['allowed']) {
            if ($verdict['retryable']) {
                // Quiet hours, mostly. Come back later rather than failing.
                self::defer($job, $verdict['code'], $verdict['detail']);

                return ['outcome' => 'deferred', 'detail' => $verdict['detail']];
            }

            self::fail($ctx, $message, $job, $verdict['code'], $verdict['detail'], false);

            return ['outcome' => 'blocked', 'detail' => $verdict['detail']];
        }

        $adapter = ChannelRegistry::adapterFor($connection);
        if ($adapter === null) {
            self::fail($ctx, $message, $job, 'channel_unavailable', 'No adapter for this provider.', false);

            return ['outcome' => 'failed', 'detail' => 'No adapter for this provider.'];
        }

        $conversation = Db::first(
            'SELECT customer_address FROM messaging_conversations WHERE conversation_uuid = :uuid',
            ['uuid' => $message['conversation_uuid']],
        );

        Db::run(
            'UPDATE messaging_messages SET status = :dispatching, dispatched_at = :now WHERE message_uuid = :uuid',
            ['dispatching' => MessageState::DISPATCHING, 'now' => Clock::nowSql(), 'uuid' => $messageUuid],
        );

        $outbound = self::buildOutbound($ctx, $message, (string) $conversation['customer_address']);
        $result = $adapter->send($connection, $outbound, (string) $job['idempotency_key']);

        return self::recordSendResult($ctx, $message, $job, $result);
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $job
     * @return array{outcome:string, detail:string}
     */
    private static function recordSendResult(Context $ctx, array $message, array $job, SendResult $result): array
    {
        $messageUuid = (string) $message['message_uuid'];

        if ($result->isAccepted()) {
            Db::transaction(static function () use ($messageUuid, $result, $job): void {
                Db::run(
                    'UPDATE messaging_messages
                     SET status = :accepted, provider_message_id = :pid, provider_accepted_at = :now,
                         provider_cost_minor = COALESCE(:cost, provider_cost_minor),
                         cost_currency = COALESCE(:currency, cost_currency),
                         failure_code = NULL, failure_detail = NULL,
                         row_version = row_version + 1
                     WHERE message_uuid = :uuid',
                    [
                        'accepted' => MessageState::PROVIDER_ACCEPTED,
                        'pid'      => $result->providerMessageId,
                        'now'      => Clock::nowSql(),
                        'cost'     => $result->costMinor,
                        'currency' => $result->costCurrency,
                        'uuid'     => $messageUuid,
                    ],
                );
                Db::run(
                    'UPDATE messaging_dispatch_jobs SET status = :sent, finished_at = :now WHERE job_uuid = :uuid',
                    ['sent' => 'sent', 'now' => Clock::nowSql(), 'uuid' => $job['job_uuid']],
                );
            });

            ConversationService::touchForMessage(
                (string) $message['conversation_uuid'],
                'outbound',
                (string) $message['origin'],
                (bool) $message['ai_generated'],
            );
            MetricsService::recordSent($ctx, $message);

            return ['outcome' => 'accepted', 'detail' => 'Accepted by the provider.'];
        }

        if ($result->isUnknown()) {
            // THE AMBIGUOUS CASE. Neither sent nor failed, and deliberately not
            // retried. A human or a provider status lookup resolves it.
            Db::transaction(static function () use ($messageUuid, $result, $job): void {
                Db::run(
                    'UPDATE messaging_messages
                     SET status = :unknown, failure_code = :code, failure_detail = :detail,
                         row_version = row_version + 1
                     WHERE message_uuid = :uuid',
                    [
                        'unknown' => MessageState::SUBMISSION_UNKNOWN,
                        'code'    => $result->errorCode,
                        'detail'  => $result->errorDetail,
                        'uuid'    => $messageUuid,
                    ],
                );
                Db::run(
                    'UPDATE messaging_dispatch_jobs
                     SET status = :unknown, finished_at = :now, last_error_code = :code, last_error_detail = :detail
                     WHERE job_uuid = :uuid',
                    [
                        'unknown' => 'unknown',
                        'now'     => Clock::nowSql(),
                        'code'    => $result->errorCode,
                        'detail'  => $result->errorDetail,
                        'uuid'    => $job['job_uuid'],
                    ],
                );
            });

            error_log('[dispatch] submission_unknown message=' . $messageUuid . ' — needs reconciliation');

            return ['outcome' => 'unknown', 'detail' => (string) $result->errorDetail];
        }

        $attempts = (int) ($job['attempts'] ?? 1);
        $maxAttempts = (int) ($job['max_attempts'] ?? 5);

        if ($result->retryable && $attempts < $maxAttempts) {
            self::defer($job, (string) $result->errorCode, (string) $result->errorDetail);

            return ['outcome' => 'retrying', 'detail' => (string) $result->errorDetail];
        }

        self::fail($ctx, $message, $job, (string) $result->errorCode, (string) $result->errorDetail, false);

        return ['outcome' => 'failed', 'detail' => (string) $result->errorDetail];
    }

    /**
     * Ask the provider what happened to a message we are unsure about.
     *
     * The only correct way out of `submission_unknown`. Returns false when the
     * provider cannot say, which leaves the message for a human — not for a
     * resend.
     *
     * @return array{resolved:bool, status:string, detail:string}
     */
    public static function reconcile(Context $ctx, string $messageUuid): array
    {
        $message = Db::first(
            'SELECT * FROM messaging_messages WHERE cmp_id = :cmp AND message_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $messageUuid],
        );
        if ($message === null) {
            return ['resolved' => false, 'status' => '', 'detail' => 'That message could not be found.'];
        }
        if ((string) $message['status'] !== MessageState::SUBMISSION_UNKNOWN) {
            return [
                'resolved' => true,
                'status'   => (string) $message['status'],
                'detail'   => 'This message is ' . MessageState::describe((string) $message['status']) . '.',
            ];
        }

        $connection = ChannelConnection::find($ctx->cmpId, (string) $message['connection_uuid']);
        $adapter = $connection !== null ? ChannelRegistry::adapterFor($connection) : null;
        $providerId = (string) ($message['provider_message_id'] ?? '');

        if ($adapter === null || $connection === null || $providerId === '') {
            return [
                'resolved' => false,
                'status'   => MessageState::SUBMISSION_UNKNOWN,
                'detail'   => $providerId === ''
                    ? 'The provider returned no message id, so its status cannot be looked up. '
                        . 'Check the customer\'s thread on the provider console before resending anything.'
                    : 'This provider does not support a status lookup. Resolve this by hand.',
            ];
        }

        $result = $adapter->lookupStatus($connection, $providerId);
        if ($result === null) {
            return [
                'resolved' => false,
                'status'   => MessageState::SUBMISSION_UNKNOWN,
                'detail'   => 'The provider could not say what happened to this message. It needs investigating by hand — '
                    . 'do not resend without checking, because the customer may already have received it.',
            ];
        }

        if ($result->isAccepted()) {
            Db::run(
                'UPDATE messaging_messages
                 SET status = :accepted, provider_accepted_at = COALESCE(provider_accepted_at, :now),
                     failure_code = NULL, failure_detail = NULL, row_version = row_version + 1
                 WHERE message_uuid = :uuid',
                ['accepted' => MessageState::PROVIDER_ACCEPTED, 'now' => Clock::nowSql(), 'uuid' => $messageUuid],
            );

            return ['resolved' => true, 'status' => MessageState::PROVIDER_ACCEPTED, 'detail' => 'The provider did accept this message.'];
        }

        Db::run(
            'UPDATE messaging_messages
             SET status = :failed, failed_at = :now, failure_code = :code, failure_detail = :detail,
                 row_version = row_version + 1
             WHERE message_uuid = :uuid',
            [
                'failed' => MessageState::FAILED,
                'now'    => Clock::nowSql(),
                'code'   => $result->errorCode,
                'detail' => $result->errorDetail,
                'uuid'   => $messageUuid,
            ],
        );

        return ['resolved' => true, 'status' => MessageState::FAILED, 'detail' => 'The provider never accepted this message.'];
    }

    /**
     * Cancel a queued message.
     *
     * Used when a journey decides the reminder is obsolete — the invoice was
     * paid between queueing and sending.
     */
    public static function cancel(Context $ctx, string $messageUuid, string $reason): bool
    {
        return Db::transaction(static function () use ($ctx, $messageUuid, $reason): bool {
            $message = Db::first(
                'SELECT status FROM messaging_messages WHERE cmp_id = :cmp AND message_uuid = :uuid FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'uuid' => $messageUuid],
            );
            if ($message === null || MessageState::isDispatched((string) $message['status'])) {
                // Too late. A dispatched message cannot be unsent, and
                // pretending otherwise would be the worst kind of lie here.
                return false;
            }

            Db::run(
                'UPDATE messaging_messages
                 SET status = :cancelled, failure_code = :code, failure_detail = :detail, row_version = row_version + 1
                 WHERE message_uuid = :uuid',
                ['cancelled' => MessageState::CANCELLED, 'code' => 'cancelled', 'detail' => $reason, 'uuid' => $messageUuid],
            );
            Db::run(
                'UPDATE messaging_dispatch_jobs SET status = :cancelled, finished_at = :now, last_error_detail = :detail
                 WHERE message_uuid = :uuid AND status IN (:queued, :claimed)',
                [
                    'cancelled' => 'cancelled', 'now' => Clock::nowSql(), 'detail' => $reason,
                    'uuid' => $messageUuid, 'queued' => 'queued', 'claimed' => 'claimed',
                ],
            );

            return true;
        });
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $message */
    private static function buildOutbound(Context $ctx, array $message, string $toAddress): OutboundMessage
    {
        $variables = Db::jsonColumn($message['template_variables'] ?? null);
        $providerTemplateId = null;

        if ((string) ($message['content_type'] ?? '') === 'template' && $message['template_uuid'] !== null) {
            $version = Db::first(
                'SELECT provider_template_id, variable_schema FROM messaging_template_versions
                 WHERE cmp_id = :cmp AND template_uuid = :tpl AND version = :ver AND language = :lang',
                [
                    'cmp'  => $ctx->cmpId,
                    'tpl'  => $message['template_uuid'],
                    'ver'  => $message['template_version'],
                    'lang' => (string) ($message['language'] ?? 'en') !== '' ? (string) $message['language'] : 'en',
                ],
            );
            $providerTemplateId = $version !== null ? (string) $version['provider_template_id'] : null;

            // Order the bound values by the schema, because the provider takes
            // body parameters positionally and an alphabetical map would put
            // the amount where the name should be.
            if ($version !== null) {
                $ordered = [];
                foreach (Db::jsonColumn($version['variable_schema'] ?? null) as $declared) {
                    $name = (string) ($declared['name'] ?? '');
                    if ($name !== '') {
                        $ordered[$name] = (string) ($variables[$name] ?? '');
                    }
                }
                $variables = $ordered;
            }
        }

        $attachments = [];
        foreach (Db::all(
            'SELECT attachment_uuid, storage, storage_ref, media_type, filename
             FROM messaging_message_attachments WHERE message_uuid = :uuid',
            ['uuid' => $message['message_uuid']],
        ) as $row) {
            $url = AttachmentService::authorisedUrl($ctx, $row);
            if ($url !== null) {
                $attachments[] = [
                    'url'        => $url,
                    'media_type' => (string) $row['media_type'],
                    'filename'   => (string) $row['filename'],
                ];
            }
        }

        return new OutboundMessage(
            messageUuid: (string) $message['message_uuid'],
            toAddress: $toAddress,
            body: (string) ($message['body'] ?? ''),
            contentType: (string) ($message['content_type'] ?? 'text'),
            language: (string) ($message['language'] ?? ''),
            providerTemplateId: $providerTemplateId,
            variables: $variables,
            attachments: $attachments,
        );
    }

    /** @param array<string, mixed> $job */
    private static function defer(array $job, string $code, string $detail): void
    {
        $attempts = (int) ($job['attempts'] ?? 1);
        $delay = self::BACKOFF[min($attempts, count(self::BACKOFF) - 1)];

        Db::run(
            'UPDATE messaging_dispatch_jobs
             SET status = :queued, claimed_at = NULL, claimed_by = NULL,
                 available_at = NOW() + (:delay || \' seconds\')::interval,
                 last_error_code = :code, last_error_detail = :detail
             WHERE job_uuid = :uuid',
            ['queued' => 'queued', 'delay' => (string) $delay, 'code' => $code, 'detail' => $detail, 'uuid' => $job['job_uuid']],
        );
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $job
     */
    private static function fail(Context $ctx, array $message, array $job, string $code, string $detail, bool $retryable): void
    {
        Db::transaction(static function () use ($message, $job, $code, $detail): void {
            Db::run(
                'UPDATE messaging_messages
                 SET status = :failed, failed_at = :now, failure_code = :code, failure_detail = :detail,
                     row_version = row_version + 1
                 WHERE message_uuid = :uuid',
                [
                    'failed' => MessageState::FAILED, 'now' => Clock::nowSql(),
                    'code' => $code, 'detail' => $detail, 'uuid' => $message['message_uuid'],
                ],
            );
            Db::run(
                'UPDATE messaging_dispatch_jobs
                 SET status = :failed, finished_at = :now, last_error_code = :code, last_error_detail = :detail
                 WHERE job_uuid = :uuid',
                [
                    'failed' => 'failed', 'now' => Clock::nowSql(),
                    'code' => $code, 'detail' => $detail, 'uuid' => $job['job_uuid'],
                ],
            );
        });

        MetricsService::recordFailed($ctx, $message);
    }

    /** @param array<string, mixed> $job */
    private static function finishJob(array $job, string $status, ?string $code, ?string $detail): void
    {
        Db::run(
            'UPDATE messaging_dispatch_jobs
             SET status = :status, finished_at = :now, last_error_code = :code, last_error_detail = :detail
             WHERE job_uuid = :uuid',
            ['status' => $status, 'now' => Clock::nowSql(), 'code' => $code, 'detail' => $detail, 'uuid' => $job['job_uuid']],
        );
    }

    /**
     * Queue health for the Channels & Trust screen.
     *
     * @return array<string, int>
     */
    public static function queueHealth(Context $ctx): array
    {
        $rows = Db::all(
            'SELECT status, COUNT(*) AS n FROM messaging_dispatch_jobs
             WHERE cmp_id = :cmp GROUP BY status',
            ['cmp' => $ctx->cmpId],
        );

        $out = ['queued' => 0, 'claimed' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'unknown' => 0];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        $out['retrying'] = (int) (Db::scalar(
            'SELECT COUNT(*) FROM messaging_dispatch_jobs
             WHERE cmp_id = :cmp AND status = :queued AND attempts > 0',
            ['cmp' => $ctx->cmpId, 'queued' => 'queued'],
        ) ?? 0);

        return $out;
    }
}
