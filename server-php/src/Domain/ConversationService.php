<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Conversations: the inbox list, one thread, assignment, resolution.
 *
 * ## Concurrency is the normal case here, not an edge case
 *
 * A busy inbox has several agents in it and two of them will reach for the same
 * unassigned conversation. Every state change therefore takes a `row_version`
 * from the client and refuses if it has moved — the second agent is told
 * somebody else got there, which is the truth, instead of silently overwriting
 * the first.
 *
 * ## No customer data is stored here beyond the transport address
 *
 * The list returns `contact_uuid` and the address. Names come from Contacts,
 * live, resolved in a batch by the controller. See Clients/ContactsClient for
 * why a `customer_name` column is not the shortcut it looks like.
 */
final class ConversationService
{
    /**
     * The inbox list.
     *
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public static function index(Context $ctx, Auth $auth, array $filters, int $limit, int $offset): array
    {
        [$scope, $params] = $ctx->scopeClause('c');
        $where = [$scope];

        // Somebody without view_all sees their own and the unassigned queue.
        // Enforced HERE rather than by hiding a tab, because the route is one
        // curl away from anybody who is curious.
        if (!Permissions::allows($ctx, $auth, 'messaging.conversations.view_all')) {
            $where[] = '(c.assigned_to_uuid = :self OR c.assigned_to_uuid IS NULL)';
            $params['self'] = $auth->uuid;
        }

        if (($filters['status'] ?? '') !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['channel'] ?? '') !== '') {
            $where[] = 'c.channel = :channel';
            $params['channel'] = $filters['channel'];
        }
        if (($filters['priority'] ?? '') !== '') {
            $where[] = 'c.priority = :priority';
            $params['priority'] = $filters['priority'];
        }
        if (($filters['assignment'] ?? '') === 'mine') {
            $where[] = 'c.assigned_to_uuid = :mine';
            $params['mine'] = $auth->uuid;
        } elseif (($filters['assignment'] ?? '') === 'unassigned') {
            $where[] = 'c.assigned_to_uuid IS NULL';
        }
        if (($filters['awaiting_reply'] ?? false) === true) {
            // Awaiting a reply means the customer spoke last. Expressed against
            // the timestamps rather than a flag, so it cannot drift out of step
            // with the messages themselves.
            $where[] = 'c.status <> :resolved AND c.last_inbound_at IS NOT NULL'
                . ' AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)';
            $params['resolved'] = 'resolved';
        }
        if (($filters['q'] ?? '') !== '') {
            // Searches the address and the conversation's own metadata. Message
            // bodies are searched by the dedicated search endpoint, which
            // applies the same permission rules.
            $where[] = '(c.customer_address ILIKE :q OR c.provider_profile_name ILIKE :q OR c.intent ILIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) (Db::scalar(
            'SELECT COUNT(*) FROM messaging_conversations c WHERE ' . $whereSql,
            $params,
        ) ?? 0);

        $rows = Db::all(
            'SELECT c.*,
                    conn.channel AS connection_channel,
                    conn.sender_address,
                    conn.display_name AS connection_name,
                    (SELECT m.body FROM messaging_messages m
                      WHERE m.conversation_uuid = c.conversation_uuid
                      ORDER BY m.created_at DESC LIMIT 1) AS last_message_body,
                    (SELECT m.direction FROM messaging_messages m
                      WHERE m.conversation_uuid = c.conversation_uuid
                      ORDER BY m.created_at DESC LIMIT 1) AS last_message_direction
             FROM messaging_conversations c
             JOIN messaging_channel_connections conn ON conn.connection_uuid = c.connection_uuid
             WHERE ' . $whereSql . '
             ORDER BY GREATEST(COALESCE(c.last_inbound_at, c.created_at), c.created_at) DESC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return ['rows' => array_map([self::class, 'shape'], $rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, string $conversationUuid): ?array
    {
        if (!Uuid::isValid($conversationUuid)) {
            return null;
        }

        [$scope, $params] = $ctx->scopeClause('c');

        $row = Db::first(
            'SELECT c.*, conn.sender_address, conn.provider, conn.display_name AS connection_name,
                    conn.status AS connection_status
             FROM messaging_conversations c
             JOIN messaging_channel_connections conn ON conn.connection_uuid = c.connection_uuid
             WHERE ' . $scope . ' AND c.conversation_uuid = :uuid',
            $params + ['uuid' => $conversationUuid],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Whether this caller may open this conversation.
     *
     * The tenant check has already happened in Controller::enter(). This is the
     * narrower question of whether an agent without `view_all` may see a
     * conversation assigned to a colleague.
     *
     * @param array<string, mixed> $conversation
     */
    public static function mayView(Context $ctx, Auth $auth, array $conversation): bool
    {
        if (Permissions::allows($ctx, $auth, 'messaging.conversations.view_all')) {
            return true;
        }
        $assigned = $conversation['assigned_to_uuid'] ?? null;

        return $assigned === null || $assigned === $auth->uuid;
    }

    /**
     * Find or open the conversation an inbound message belongs to.
     *
     * The unique index on (cmp, connection, address) WHERE status <> 'resolved'
     * is what stops two inbound messages arriving together from creating two
     * threads for the same person — an `ON CONFLICT` retry rather than a
     * check-then-insert, because between the check and the insert is exactly
     * where the race lives.
     */
    public static function findOrOpenForInbound(
        Context $ctx,
        string $connectionUuid,
        string $channel,
        string $address,
        string $profileName = '',
    ): string {
        $address = ConsentService::normaliseAddress($address);

        $existing = Db::first(
            'SELECT conversation_uuid FROM messaging_conversations
             WHERE cmp_id = :cmp AND connection_uuid = :conn AND customer_address = :addr
               AND status <> :resolved',
            ['cmp' => $ctx->cmpId, 'conn' => $connectionUuid, 'addr' => $address, 'resolved' => 'resolved'],
        );

        if ($existing !== null) {
            if ($profileName !== '') {
                Db::run(
                    'UPDATE messaging_conversations SET provider_profile_name = :name
                     WHERE conversation_uuid = :uuid AND provider_profile_name <> :name',
                    ['name' => $profileName, 'uuid' => $existing['conversation_uuid']],
                );
            }

            return (string) $existing['conversation_uuid'];
        }

        $uuid = Uuid::v4();
        try {
            Db::insert('messaging_conversations', [
                'conversation_uuid'     => $uuid,
                'cmp_id'                => $ctx->cmpId,
                'bo_id'                 => $ctx->boId,
                'connection_uuid'       => $connectionUuid,
                'channel'               => $channel,
                'customer_address'      => $address,
                'provider_profile_name' => $profileName,
                'status'                => 'open',
                'created_at'            => Clock::nowSql(),
                'updated_at'            => Clock::nowSql(),
            ], 'conversation_uuid');

            return $uuid;
        } catch (\PDOException $e) {
            // Lost the race. The other insert won and its row is the one to use.
            $row = Db::first(
                'SELECT conversation_uuid FROM messaging_conversations
                 WHERE cmp_id = :cmp AND connection_uuid = :conn AND customer_address = :addr
                   AND status <> :resolved',
                ['cmp' => $ctx->cmpId, 'conn' => $connectionUuid, 'addr' => $address, 'resolved' => 'resolved'],
            );
            if ($row !== null) {
                return (string) $row['conversation_uuid'];
            }
            throw $e;
        }
    }

    /**
     * Assign or unassign, with an optimistic version check.
     *
     * @return array{ok:bool, code:string, detail:string}
     */
    public static function assign(
        Context $ctx,
        Auth $auth,
        string $conversationUuid,
        ?string $assigneeUuid,
        int $expectedVersion,
        string $reason = '',
    ): array {
        return Db::transaction(static function () use ($ctx, $auth, $conversationUuid, $assigneeUuid, $expectedVersion, $reason): array {
            $row = Db::first(
                'SELECT conversation_uuid, row_version, assigned_to_uuid FROM messaging_conversations
                 WHERE cmp_id = :cmp AND conversation_uuid = :uuid FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'uuid' => $conversationUuid],
            );
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'detail' => 'That conversation could not be found.'];
            }
            if ((int) $row['row_version'] !== $expectedVersion) {
                return [
                    'ok'     => false,
                    'code'   => 'version_conflict',
                    'detail' => 'This conversation changed while you were looking at it. Reload to see who has it now.',
                ];
            }

            Db::run(
                'UPDATE messaging_conversations
                 SET assigned_to_uuid = :assignee, assigned_at = :now, updated_at = :now,
                     row_version = row_version + 1
                 WHERE conversation_uuid = :uuid',
                ['assignee' => $assigneeUuid, 'now' => Clock::nowSql(), 'uuid' => $conversationUuid],
            );

            Db::insert('messaging_conversation_assignments', [
                'assignment_uuid'   => Uuid::v4(),
                'cmp_id'            => $ctx->cmpId,
                'conversation_uuid' => $conversationUuid,
                'assigned_to_uuid'  => $assigneeUuid,
                'assigned_by_uuid'  => $auth->uuid,
                'reason'            => $reason !== '' ? $reason : null,
                'created_at'        => Clock::nowSql(),
            ], 'assignment_uuid');

            return ['ok' => true, 'code' => 'ok', 'detail' => 'Assignment saved.'];
        });
    }

    /**
     * Resolve or reopen.
     *
     * @return array{ok:bool, code:string, detail:string}
     */
    public static function setStatus(
        Context $ctx,
        Auth $auth,
        string $conversationUuid,
        string $status,
        int $expectedVersion,
    ): array {
        if (!in_array($status, ['open', 'pending', 'resolved'], true)) {
            return ['ok' => false, 'code' => 'invalid_status', 'detail' => 'Unknown conversation status.'];
        }

        return Db::transaction(static function () use ($ctx, $auth, $conversationUuid, $status, $expectedVersion): array {
            $row = Db::first(
                'SELECT row_version, status FROM messaging_conversations
                 WHERE cmp_id = :cmp AND conversation_uuid = :uuid FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'uuid' => $conversationUuid],
            );
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'detail' => 'That conversation could not be found.'];
            }
            if ((int) $row['row_version'] !== $expectedVersion) {
                return [
                    'ok'     => false,
                    'code'   => 'version_conflict',
                    'detail' => 'This conversation changed while you were looking at it. Reload and try again.',
                ];
            }

            $was = (string) $row['status'];
            $values = [
                'status'      => $status,
                'updated_at'  => Clock::nowSql(),
            ];

            if ($status === 'resolved') {
                $values['resolved_at'] = Clock::nowSql();
                $values['resolved_by_uuid'] = $auth->uuid;
            } elseif ($was === 'resolved') {
                // Reopening. The count is kept because a conversation that has
                // been reopened three times is a conversation that was never
                // actually resolved, and the resolution rate on Business
                // Outcomes should not pretend otherwise.
                $values['resolved_at'] = null;
                $values['resolved_by_uuid'] = null;
            }

            $sql = 'UPDATE messaging_conversations SET status = :status, updated_at = :updated_at';
            $params = ['status' => $status, 'updated_at' => $values['updated_at'], 'uuid' => $conversationUuid];

            if ($status === 'resolved') {
                $sql .= ', resolved_at = :resolved_at, resolved_by_uuid = :resolved_by';
                $params['resolved_at'] = $values['resolved_at'];
                $params['resolved_by'] = $auth->uuid;
            } elseif ($was === 'resolved') {
                $sql .= ', resolved_at = NULL, resolved_by_uuid = NULL, reopened_count = reopened_count + 1';
            }

            Db::run($sql . ', row_version = row_version + 1 WHERE conversation_uuid = :uuid', $params);

            return ['ok' => true, 'code' => 'ok', 'detail' => 'Conversation ' . $status . '.'];
        });
    }

    /** An internal note. Its own table, so it cannot be mistaken for a message. */
    public static function addNote(
        Context $ctx,
        Auth $auth,
        string $conversationUuid,
        string $body,
        bool $isHandoff = false,
    ): string {
        $uuid = Uuid::v4();
        Db::insert('messaging_internal_notes', [
            'note_uuid'         => $uuid,
            'cmp_id'            => $ctx->cmpId,
            'conversation_uuid' => $conversationUuid,
            'body'              => $body,
            'author_uuid'       => $auth->uuid,
            'is_handoff'        => $isHandoff,
            'created_at'        => Clock::nowSql(),
        ], 'note_uuid');

        return $uuid;
    }

    /** @return list<array<string, mixed>> */
    public static function notes(Context $ctx, string $conversationUuid): array
    {
        return Db::all(
            'SELECT note_uuid, body, author_uuid, is_handoff, created_at
             FROM messaging_internal_notes
             WHERE cmp_id = :cmp AND conversation_uuid = :uuid
             ORDER BY created_at ASC',
            ['cmp' => $ctx->cmpId, 'uuid' => $conversationUuid],
        );
    }

    /** Mark inbound messages as read by an agent. */
    public static function markRead(Context $ctx, string $conversationUuid): void
    {
        Db::run(
            'UPDATE messaging_conversations SET unread_inbound_count = 0, updated_at = :now
             WHERE cmp_id = :cmp AND conversation_uuid = :uuid AND unread_inbound_count > 0',
            ['now' => Clock::nowSql(), 'cmp' => $ctx->cmpId, 'uuid' => $conversationUuid],
        );
    }

    /**
     * Keep the derived timestamps in step after a message lands.
     *
     * `first_human_outbound_at` is set only for a human reply, which is what
     * makes the Command Centre's median first-response figure mean what it
     * says. An automated acknowledgement recorded here would make every median
     * look excellent and none of them true.
     */
    public static function touchForMessage(
        string $conversationUuid,
        string $direction,
        string $origin,
        bool $aiGenerated,
    ): void {
        $now = Clock::nowSql();

        if ($direction === 'inbound') {
            Db::run(
                'UPDATE messaging_conversations
                 SET last_inbound_at = :now,
                     first_inbound_at = COALESCE(first_inbound_at, :now),
                     unread_inbound_count = unread_inbound_count + 1,
                     status = CASE WHEN status = :resolved THEN :open ELSE status END,
                     reopened_count = CASE WHEN status = :resolved THEN reopened_count + 1 ELSE reopened_count END,
                     resolved_at = CASE WHEN status = :resolved THEN NULL ELSE resolved_at END,
                     updated_at = :now
                 WHERE conversation_uuid = :uuid',
                ['now' => $now, 'uuid' => $conversationUuid, 'resolved' => 'resolved', 'open' => 'open'],
            );

            return;
        }

        // A human reply is one an agent authored, whether or not the AI drafted
        // it: a person reviewed and sent it, which is the thing being measured.
        $isHuman = $origin === 'AGENT';

        Db::run(
            'UPDATE messaging_conversations
             SET last_outbound_at = :now,
                 first_human_outbound_at = CASE WHEN :is_human THEN COALESCE(first_human_outbound_at, :now)
                                                ELSE first_human_outbound_at END,
                 first_automated_outbound_at = CASE WHEN :is_human THEN first_automated_outbound_at
                                                    ELSE COALESCE(first_automated_outbound_at, :now) END,
                 ai_assisted = ai_assisted OR :ai,
                 updated_at = :now
             WHERE conversation_uuid = :uuid',
            ['now' => $now, 'is_human' => $isHuman ? 'true' : 'false', 'ai' => $aiGenerated ? 'true' : 'false', 'uuid' => $conversationUuid],
        );
    }

    /**
     * Link a conversation to another product's record.
     *
     * A reference and a label. Never the record — see the note at the top of
     * migration 002.
     */
    public static function linkExternal(
        Context $ctx,
        string $conversationUuid,
        string $ownerProduct,
        string $externalId,
        string $relationship = 'subject',
        string $label = '',
    ): void {
        try {
            Db::insert('messaging_external_references', [
                'cmp_id'         => $ctx->cmpId,
                'entity_type'    => 'conversation',
                'entity_uuid'    => $conversationUuid,
                'owner_product'  => $ownerProduct,
                'external_id'    => $externalId,
                'external_label' => $label,
                'relationship'   => $relationship,
                'created_at'     => Clock::nowSql(),
            ], 'reference_id');
        } catch (\PDOException) {
            // The unique constraint means it is already linked, which is the
            // desired end state.
        }
    }

    /** @return list<array<string, mixed>> */
    public static function externalReferences(Context $ctx, string $conversationUuid): array
    {
        return Db::all(
            'SELECT owner_product, external_id, external_label, relationship, created_at
             FROM messaging_external_references
             WHERE cmp_id = :cmp AND entity_type = :type AND entity_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'type' => 'conversation', 'uuid' => $conversationUuid],
        );
    }

    /**
     * The shape an API response uses.
     *
     * An allowlist, so a column added to the table later does not silently
     * start appearing in responses.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'conversation_uuid'     => (string) $row['conversation_uuid'],
            'connection_uuid'       => (string) $row['connection_uuid'],
            'channel'               => (string) $row['channel'],
            'customer_address'      => (string) $row['customer_address'],
            'contact_uuid'          => $row['contact_uuid'] !== null ? (string) $row['contact_uuid'] : null,
            'provider_profile_name' => (string) ($row['provider_profile_name'] ?? ''),
            'status'                => (string) $row['status'],
            'priority'              => (string) $row['priority'],
            'intent'                => (string) ($row['intent'] ?? ''),
            'intent_source'         => (string) ($row['intent_source'] ?? 'none'),
            'assigned_to_uuid'      => $row['assigned_to_uuid'] !== null ? (string) $row['assigned_to_uuid'] : null,
            'language'              => (string) ($row['language'] ?? ''),
            'unread_inbound_count'  => (int) ($row['unread_inbound_count'] ?? 0),
            'ai_assisted'           => (bool) ($row['ai_assisted'] ?? false),
            'reopened_count'        => (int) ($row['reopened_count'] ?? 0),
            'first_inbound_at'      => $row['first_inbound_at'] ?? null,
            'first_human_outbound_at' => $row['first_human_outbound_at'] ?? null,
            'last_inbound_at'       => $row['last_inbound_at'] ?? null,
            'last_outbound_at'      => $row['last_outbound_at'] ?? null,
            'resolved_at'           => $row['resolved_at'] ?? null,
            'created_at'            => $row['created_at'] ?? null,
            'row_version'           => (int) ($row['row_version'] ?? 1),
            'sender_address'        => (string) ($row['sender_address'] ?? ''),
            'connection_name'       => (string) ($row['connection_name'] ?? ''),
            'last_message_body'     => $row['last_message_body'] ?? null,
            'last_message_direction' => $row['last_message_direction'] ?? null,
        ];
    }
}
