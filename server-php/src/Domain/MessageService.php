<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Drafts, approval and the message thread.
 *
 * ## The approval mechanism
 *
 * Approving a draft records the hash of what was approved. Editing a draft
 * recomputes the hash and clears the approval. Dispatch compares the two.
 * Three small pieces, and together they make "editing approved content
 * invalidates its approval" a property of the system rather than a rule
 * somebody has to remember.
 *
 * ## Concurrent drafts
 *
 * Two agents in the same conversation will both open the composer. A draft
 * carries a `row_version` and saving with a stale one is refused, so the second
 * agent is told the draft changed and can see what it says now — rather than
 * quietly replacing a colleague's work on a message about to go to a customer.
 */
final class MessageService
{
    /**
     * The thread.
     *
     * @return list<array<string, mixed>>
     */
    public static function thread(Context $ctx, string $conversationUuid, int $limit = 200): array
    {
        $rows = Db::all(
            'SELECT * FROM messaging_messages
             WHERE cmp_id = :cmp AND conversation_uuid = :uuid
             ORDER BY created_at ASC
             LIMIT :limit',
            ['cmp' => $ctx->cmpId, 'uuid' => $conversationUuid, 'limit' => max(1, min(500, $limit))],
        );

        $messages = array_map([self::class, 'shape'], $rows);

        // Attachments in one query rather than one per message.
        $uuids = array_column($messages, 'message_uuid');
        if ($uuids !== []) {
            $attachments = Db::all(
                'SELECT attachment_uuid, message_uuid, filename, media_type, byte_size, scan_status, storage
                 FROM messaging_message_attachments
                 WHERE cmp_id = :cmp AND message_uuid = ANY(:uuids)',
                ['cmp' => $ctx->cmpId, 'uuids' => '{' . implode(',', $uuids) . '}'],
            );
            $byMessage = [];
            foreach ($attachments as $attachment) {
                $byMessage[(string) $attachment['message_uuid']][] = [
                    'attachment_uuid' => (string) $attachment['attachment_uuid'],
                    'filename'        => (string) $attachment['filename'],
                    'media_type'      => (string) $attachment['media_type'],
                    'byte_size'       => (int) $attachment['byte_size'],
                    'scan_status'     => (string) $attachment['scan_status'],
                    'storage'         => (string) $attachment['storage'],
                ];
            }
            foreach ($messages as $index => $message) {
                $messages[$index]['attachments'] = $byMessage[$message['message_uuid']] ?? [];
            }
        }

        return $messages;
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, string $messageUuid): ?array
    {
        if (!Uuid::isValid($messageUuid)) {
            return null;
        }

        $row = Db::first(
            'SELECT * FROM messaging_messages WHERE cmp_id = :cmp AND message_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $messageUuid],
        );

        return $row === null ? null : $row;
    }

    /**
     * Save a draft — new, or an edit of an existing one.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool, code:string, detail:string, message_uuid:?string}
     */
    public static function saveDraft(Context $ctx, Auth $auth, string $conversationUuid, array $input): array
    {
        $conversation = ConversationService::find($ctx, $conversationUuid);
        if ($conversation === null) {
            return ['ok' => false, 'code' => 'not_found', 'detail' => 'That conversation could not be found.', 'message_uuid' => null];
        }

        $body = trim((string) ($input['body'] ?? ''));
        $contentType = (string) ($input['content_type'] ?? 'text');
        $language = (string) ($input['language'] ?? $conversation['language'] ?? '');
        $templateUuid = ($input['template_uuid'] ?? null) ?: null;
        $templateVersion = isset($input['template_version']) ? (int) $input['template_version'] : null;
        $variables = is_array($input['template_variables'] ?? null) ? $input['template_variables'] : [];
        $aiGenerated = (bool) ($input['ai_generated'] ?? false);
        $aiRunUuid = ($input['ai_run_uuid'] ?? null) ?: null;

        if ($body === '' && $templateUuid === null) {
            return ['ok' => false, 'code' => 'empty', 'detail' => 'A draft needs either text or a template.', 'message_uuid' => null];
        }

        $hash = MessageState::contentHash($body, $contentType, $language, $templateUuid, $templateVersion, $variables);
        $existingUuid = ($input['message_uuid'] ?? null) ?: null;

        if ($existingUuid !== null) {
            return self::updateDraft($ctx, $auth, (string) $existingUuid, [
                'body' => $body, 'content_type' => $contentType, 'language' => $language,
                'template_uuid' => $templateUuid, 'template_version' => $templateVersion,
                'template_variables' => $variables, 'content_hash' => $hash,
                'ai_generated' => $aiGenerated, 'ai_run_uuid' => $aiRunUuid,
            ], (int) ($input['row_version'] ?? 0));
        }

        $uuid = Uuid::v4();
        Db::insert('messaging_messages', [
            'message_uuid'       => $uuid,
            'cmp_id'             => $ctx->cmpId,
            'bo_id'              => $ctx->boId,
            'conversation_uuid'  => $conversationUuid,
            'connection_uuid'    => $conversation['connection_uuid'],
            'channel'            => $conversation['channel'],
            'direction'          => 'outbound',
            'status'             => MessageState::DRAFT,
            'body'               => $body,
            'content_type'       => $contentType,
            'language'           => $language,
            'template_uuid'      => $templateUuid,
            'template_version'   => $templateVersion,
            'template_variables' => $variables !== [] ? $variables : null,
            // Proven by the credential, never read from the body.
            'origin'             => $auth->provenOrigin(),
            'ai_generated'       => $aiGenerated,
            'ai_run_uuid'        => $aiRunUuid,
            'author_uuid'        => $auth->uuid,
            'content_hash'       => $hash,
            'created_at'         => Clock::nowSql(),
        ], 'message_uuid');

        return ['ok' => true, 'code' => 'ok', 'detail' => 'Draft saved.', 'message_uuid' => $uuid];
    }

    /**
     * @param array<string, mixed> $values
     * @return array{ok:bool, code:string, detail:string, message_uuid:?string}
     */
    private static function updateDraft(Context $ctx, Auth $auth, string $messageUuid, array $values, int $expectedVersion): array
    {
        return Db::transaction(static function () use ($ctx, $auth, $messageUuid, $values, $expectedVersion): array {
            $row = Db::first(
                'SELECT status, row_version, content_hash, approved_content_hash
                 FROM messaging_messages WHERE cmp_id = :cmp AND message_uuid = :uuid FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'uuid' => $messageUuid],
            );
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'detail' => 'That draft could not be found.', 'message_uuid' => null];
            }

            $status = (string) $row['status'];
            if (!MessageState::isEditable($status) && $status !== MessageState::APPROVED) {
                return [
                    'ok'     => false,
                    'code'   => 'not_editable',
                    'detail' => 'This message is ' . MessageState::describe($status) . ' and can no longer be edited.',
                    'message_uuid' => null,
                ];
            }
            if ($expectedVersion > 0 && (int) $row['row_version'] !== $expectedVersion) {
                return [
                    'ok'     => false,
                    'code'   => 'version_conflict',
                    'detail' => 'This draft was changed elsewhere. Reload to see the current version before editing.',
                    'message_uuid' => null,
                ];
            }

            $contentChanged = !hash_equals((string) $row['content_hash'], (string) $values['content_hash']);

            // THE RULE. Editing content that had been approved removes the
            // approval, and the message goes back to being a draft. Nothing
            // downstream has to remember this.
            $resetApproval = $contentChanged && (string) ($row['approved_content_hash'] ?? '') !== '';

            Db::run(
                'UPDATE messaging_messages
                 SET body = :body, content_type = :ctype, language = :lang,
                     template_uuid = :tpl, template_version = :tver, template_variables = :vars,
                     content_hash = :hash, ai_generated = :ai, ai_run_uuid = COALESCE(:airun, ai_run_uuid),
                     status = CASE WHEN :reset THEN :draft ELSE status END,
                     approved_at = CASE WHEN :reset THEN NULL ELSE approved_at END,
                     approved_by_uuid = CASE WHEN :reset THEN NULL ELSE approved_by_uuid END,
                     approved_content_hash = CASE WHEN :reset THEN NULL ELSE approved_content_hash END,
                     row_version = row_version + 1
                 WHERE message_uuid = :uuid',
                [
                    'body'  => $values['body'],
                    'ctype' => $values['content_type'],
                    'lang'  => $values['language'],
                    'tpl'   => $values['template_uuid'],
                    'tver'  => $values['template_version'],
                    'vars'  => $values['template_variables'] !== []
                        ? json_encode($values['template_variables'], JSON_UNESCAPED_UNICODE)
                        : null,
                    'hash'  => $values['content_hash'],
                    'ai'    => $values['ai_generated'] ? 'true' : 'false',
                    'airun' => $values['ai_run_uuid'],
                    'reset' => $resetApproval ? 'true' : 'false',
                    'draft' => MessageState::DRAFT,
                    'uuid'  => $messageUuid,
                ],
            );

            return [
                'ok'     => true,
                'code'   => 'ok',
                'detail' => $resetApproval
                    ? 'Draft saved. It was edited after approval, so it needs approving again.'
                    : 'Draft saved.',
                'message_uuid' => $messageUuid,
            ];
        });
    }

    /**
     * Approve a draft.
     *
     * Records the hash of what is being approved. Everything downstream
     * compares against it.
     *
     * @return array{ok:bool, code:string, detail:string}
     */
    public static function approve(Context $ctx, Auth $auth, string $messageUuid, int $expectedVersion): array
    {
        return Db::transaction(static function () use ($ctx, $auth, $messageUuid, $expectedVersion): array {
            $row = Db::first(
                'SELECT status, row_version, content_hash FROM messaging_messages
                 WHERE cmp_id = :cmp AND message_uuid = :uuid FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'uuid' => $messageUuid],
            );
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'detail' => 'That message could not be found.'];
            }
            $status = (string) $row['status'];
            if (!MessageState::isEditable($status)) {
                return [
                    'ok'     => false,
                    'code'   => 'not_approvable',
                    'detail' => 'This message is ' . MessageState::describe($status) . '.',
                ];
            }
            if ($expectedVersion > 0 && (int) $row['row_version'] !== $expectedVersion) {
                return [
                    'ok'     => false,
                    'code'   => 'version_conflict',
                    'detail' => 'This draft changed since you read it. Review the current text before approving.',
                ];
            }

            $hash = (string) $row['content_hash'];

            Db::run(
                'UPDATE messaging_messages
                 SET status = :approved, approved_at = :now, approved_by_uuid = :actor,
                     approved_content_hash = :hash, row_version = row_version + 1
                 WHERE message_uuid = :uuid',
                [
                    'approved' => MessageState::APPROVED,
                    'now'      => Clock::nowSql(),
                    'actor'    => $auth->uuid,
                    'hash'     => $hash,
                    'uuid'     => $messageUuid,
                ],
            );

            Db::insert('messaging_approvals', [
                'approval_uuid'   => Uuid::v4(),
                'cmp_id'          => $ctx->cmpId,
                'subject_type'    => 'message',
                'subject_uuid'    => $messageUuid,
                'content_hash'    => $hash,
                'decision'        => 'approved',
                'decided_by_uuid' => $auth->uuid,
                'decided_at'      => Clock::nowSql(),
            ], 'approval_uuid');

            return ['ok' => true, 'code' => 'ok', 'detail' => 'Approved.'];
        });
    }

    /**
     * Record an inbound message.
     *
     * Called from the webhook pipeline. Deduplicated on the provider's own
     * message id, because a provider that does not get a 200 will send the same
     * inbound message again and the customer should not appear to have written
     * twice.
     *
     * @param array<string, mixed> $input
     * @return array{message_uuid:string, duplicate:bool}
     */
    public static function recordInbound(Context $ctx, string $conversationUuid, array $input): array
    {
        $providerMessageId = (string) ($input['provider_message_id'] ?? '');
        $connectionUuid = (string) $input['connection_uuid'];

        if ($providerMessageId !== '') {
            $existing = Db::first(
                'SELECT message_uuid FROM messaging_messages
                 WHERE connection_uuid = :conn AND provider_message_id = :pid',
                ['conn' => $connectionUuid, 'pid' => $providerMessageId],
            );
            if ($existing !== null) {
                return ['message_uuid' => (string) $existing['message_uuid'], 'duplicate' => true];
            }
        }

        $uuid = Uuid::v4();
        $body = (string) ($input['body'] ?? '');

        Db::insert('messaging_messages', [
            'message_uuid'        => $uuid,
            'cmp_id'              => $ctx->cmpId,
            'bo_id'               => $ctx->boId,
            'conversation_uuid'   => $conversationUuid,
            'connection_uuid'     => $connectionUuid,
            'channel'             => (string) $input['channel'],
            'direction'           => 'inbound',
            // An inbound message is delivered by definition: it is here.
            'status'              => MessageState::DELIVERED,
            'body'                => $body,
            'content_type'        => (string) ($input['content_type'] ?? 'text'),
            'origin'              => 'CUSTOMER',
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'created_at'          => Clock::nowSql(),
            'delivered_at'        => Clock::nowSql(),
            'sent_at'             => $input['occurred_at'] ?? null,
        ], 'message_uuid');

        return ['message_uuid' => $uuid, 'duplicate' => false];
    }

    /**
     * The shape an API response uses.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shape(array $row): array
    {
        return [
            'message_uuid'       => (string) $row['message_uuid'],
            'conversation_uuid'  => (string) $row['conversation_uuid'],
            'direction'          => (string) $row['direction'],
            'status'             => (string) $row['status'],
            'status_label'       => MessageState::describe((string) $row['status']),
            'body'               => (string) ($row['body'] ?? ''),
            'content_type'       => (string) ($row['content_type'] ?? 'text'),
            'language'           => (string) ($row['language'] ?? ''),
            'origin'             => (string) ($row['origin'] ?? ''),
            'ai_generated'       => (bool) ($row['ai_generated'] ?? false),
            'author_uuid'        => (string) ($row['author_uuid'] ?? ''),
            'template_uuid'      => $row['template_uuid'] ?? null,
            'template_version'   => $row['template_version'] !== null ? (int) $row['template_version'] : null,
            'template_variables' => Db::jsonColumn($row['template_variables'] ?? null),
            'journey_run_uuid'   => $row['journey_run_uuid'] ?? null,
            'approved_at'        => $row['approved_at'] ?? null,
            'approved_by_uuid'   => $row['approved_by_uuid'] ?? null,
            // Whether the approval still matches the content. The UI needs this
            // to show "edited after approval" without recomputing a hash.
            'approval_current'   => (string) ($row['approved_content_hash'] ?? '') !== ''
                && hash_equals((string) $row['approved_content_hash'], (string) ($row['content_hash'] ?? '')),
            'failure_code'       => $row['failure_code'] ?? null,
            'failure_detail'     => $row['failure_detail'] ?? null,
            'provider_cost_minor'  => $row['provider_cost_minor'] !== null ? (int) $row['provider_cost_minor'] : null,
            'estimated_cost_minor' => $row['estimated_cost_minor'] !== null ? (int) $row['estimated_cost_minor'] : null,
            'cost_currency'      => $row['cost_currency'] ?? null,
            'created_at'         => $row['created_at'] ?? null,
            'dispatched_at'      => $row['dispatched_at'] ?? null,
            'delivered_at'       => $row['delivered_at'] ?? null,
            'read_at'            => $row['read_at'] ?? null,
            'sent_at'            => $row['sent_at'] ?? null,
            'row_version'        => (int) ($row['row_version'] ?? 1),
            'attachments'        => [],
        ];
    }
}
