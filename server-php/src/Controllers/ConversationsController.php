<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Channels\Capability;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Domain\BusinessContextService;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Domain\ConversationService;
use Aicountly\Api\Domain\MessageService;
use Aicountly\Api\Domain\MessageState;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * The Unified Inbox.
 */
final class ConversationsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $params = Http::listParams(['last_inbound_at', 'created_at'], 'last_inbound_at');

        $result = ConversationService::index($ctx, $auth, [
            'status'         => Http::param('status', ''),
            'channel'        => Http::param('channel', ''),
            'priority'       => Http::param('priority', ''),
            'assignment'     => Http::param('assignment', ''),
            'awaiting_reply' => Http::boolParam('awaiting_reply'),
            'q'              => $params['q'],
        ], $params['limit'], $params['offset']);

        // Names resolved LIVE, in one batched call. See ContactsClient for why
        // there is no name column to read instead.
        $names = self::resolveContactNames($ctx, $auth, array_column($result['rows'], 'contact_uuid'));

        $rows = [];
        foreach ($result['rows'] as $row) {
            $contactUuid = $row['contact_uuid'];
            $rows[] = $row + [
                'contact_name' => $contactUuid !== null ? ($names['names'][$contactUuid] ?? null) : null,
            ];
        }

        Http::list($rows, $result['total'], $params['limit'], $params['offset'], [
            'contact_source' => $names['state'],
            'contact_note'   => $names['note'],
            'counts'         => self::counts($ctx, $auth),
        ]);
    }

    public static function show(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $conversation = ConversationService::find($ctx, $conversationUuid);
        if ($conversation === null) {
            Http::notFound('That conversation could not be found.');
        }
        if (!ConversationService::mayView($ctx, $auth, $conversation)) {
            Http::forbidden('That conversation is assigned to somebody else.');
        }

        if (Permissions::allows($ctx, $auth, 'messaging.conversations.reply')) {
            ConversationService::markRead($ctx, $conversationUuid);
        }

        $connection = ChannelConnection::find($ctx->cmpId, $conversation['connection_uuid']);
        $capabilities = $connection !== null ? ChannelRegistry::capabilities($connection) : [];
        $adapter = $connection !== null ? ChannelRegistry::adapterFor($connection) : null;

        // WHY THE COMPOSER MIGHT BE DISABLED, in words. An SMS sender that
        // cannot receive replies is not a bug and an agent should not have to
        // guess; nor should they be given a box that silently discards what
        // they type.
        $canReply = Permissions::allows($ctx, $auth, 'messaging.conversations.reply');
        $composer = self::composerState($ctx, $conversation, $connection, $capabilities, $adapter, $canReply);

        Http::data([
            'conversation' => $conversation,
            'messages'     => MessageService::thread($ctx, $conversationUuid),
            'notes'        => Permissions::allows($ctx, $auth, 'messaging.conversations.view')
                ? ConversationService::notes($ctx, $conversationUuid)
                : [],
            'context'      => BusinessContextService::for($ctx, $auth, $conversation),
            'channel'      => [
                'connection_uuid' => $connection?->connectionUuid,
                'provider'        => $connection?->provider,
                'sender_address'  => $connection?->senderAddress,
                'status'          => $connection?->status,
                'capabilities'    => self::describeCapabilities($capabilities),
            ],
            'composer'     => $composer,
            'consent'      => self::consentState($ctx, $auth, $conversation),
            'external_references' => ConversationService::externalReferences($ctx, $conversationUuid),
            'permissions'  => [
                'can_reply'   => $canReply,
                'can_approve' => Permissions::allows($ctx, $auth, 'messaging.drafts.approve'),
                'can_send'    => Permissions::allows($ctx, $auth, 'messaging.messages.send'),
                'can_assign'  => Permissions::allows($ctx, $auth, 'messaging.conversations.assign'),
                'can_resolve' => Permissions::allows($ctx, $auth, 'messaging.conversations.resolve'),
                'can_note'    => Permissions::allows($ctx, $auth, 'messaging.conversations.note'),
            ],
        ]);
    }

    public static function update(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $conversation = ConversationService::find($ctx, $conversationUuid);
        if ($conversation === null) {
            Http::notFound('That conversation could not be found.');
        }

        $body = Http::body();
        $changed = [];

        if (isset($body['status'])) {
            Permissions::assert($ctx, $auth, 'messaging.conversations.resolve');
            $result = ConversationService::setStatus(
                $ctx,
                $auth,
                $conversationUuid,
                (string) $body['status'],
                self::expectedVersion(),
            );
            if (!$result['ok']) {
                self::fail($result['code'], $result['detail']);
            }
            $changed['status'] = (string) $body['status'];
        }

        if (array_key_exists('priority', $body) || array_key_exists('intent', $body) || array_key_exists('language', $body)) {
            Permissions::assert($ctx, $auth, 'messaging.conversations.reply');

            $values = [];
            if (isset($body['priority']) && in_array((string) $body['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
                $values['priority'] = (string) $body['priority'];
            }
            if (array_key_exists('intent', $body)) {
                $values['intent'] = mb_substr(trim((string) $body['intent']), 0, 48);
                // An agent setting an intent is recorded as an agent's opinion,
                // never as the AI's.
                $values['intent_source'] = 'agent';
            }
            if (isset($body['language'])) {
                $values['language'] = mb_substr(trim((string) $body['language']), 0, 12);
            }

            if ($values !== []) {
                \Aicountly\Api\Db::update(
                    'messaging_conversations',
                    $values + ['updated_at' => \Aicountly\Api\Support\Clock::nowSql()],
                    ['cmp_id' => $ctx->cmpId, 'conversation_uuid' => $conversationUuid],
                );
                $changed += $values;
            }
        }

        if ($changed === []) {
            Http::validationFailed('Nothing to change.');
        }

        Audit::record($ctx, $auth, 'conversation.updated', 'conversation', $conversationUuid, null, $changed);

        Http::data(['conversation' => ConversationService::find($ctx, $conversationUuid)]);
    }

    public static function assign(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.assign');

        $body = Http::body();
        // `null` unassigns. An explicit "me" saves the client having to know
        // its own uuid.
        $assignee = array_key_exists('assigned_to_uuid', $body)
            ? (($body['assigned_to_uuid'] === null || $body['assigned_to_uuid'] === '')
                ? null
                : (string) $body['assigned_to_uuid'])
            : $auth->uuid;

        if ($assignee === 'me') {
            $assignee = $auth->uuid;
        }

        $result = ConversationService::assign(
            $ctx,
            $auth,
            $conversationUuid,
            $assignee,
            self::expectedVersion(),
            (string) ($body['reason'] ?? ''),
        );

        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'conversation.assigned', 'conversation', $conversationUuid, null, [
            'assigned_to_uuid' => $assignee,
        ]);

        Http::data(['conversation' => ConversationService::find($ctx, $conversationUuid)]);
    }

    public static function addNote(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.note');

        $conversation = ConversationService::find($ctx, $conversationUuid);
        if ($conversation === null) {
            Http::notFound('That conversation could not be found.');
        }

        $bodyText = trim((string) (Http::param('body') ?? ''));
        if ($bodyText === '') {
            Http::validationFailed('A note needs some text.');
        }

        $noteUuid = ConversationService::addNote(
            $ctx,
            $auth,
            $conversationUuid,
            mb_substr($bodyText, 0, 4000),
            Http::boolParam('is_handoff'),
        );

        Audit::record($ctx, $auth, 'conversation.note_added', 'conversation', $conversationUuid);

        Http::data([
            'note_uuid' => $noteUuid,
            'notes'     => ConversationService::notes($ctx, $conversationUuid),
            // Said explicitly in the response, because the one mistake that
            // matters here is a note reaching a customer.
            'note'      => 'Internal note saved. It is not visible to the customer and is never sent.',
        ], 201);
    }

    /** The live business-context panel on its own, for a refresh button. */
    public static function context(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $conversation = ConversationService::find($ctx, $conversationUuid);
        if ($conversation === null) {
            Http::notFound('That conversation could not be found.');
        }
        if (!ConversationService::mayView($ctx, $auth, $conversation)) {
            Http::forbidden('That conversation is assigned to somebody else.');
        }

        Http::data(['context' => BusinessContextService::for($ctx, $auth, $conversation)]);
    }

    /**
     * Match a conversation to a contact in Aicountly Contacts.
     *
     * Stores the reference and nothing else.
     */
    public static function matchContact(string $conversationUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.reply');

        $contactUuid = trim((string) (Http::param('contact_uuid') ?? ''));
        if ($contactUuid === '') {
            Http::validationFailed('A contact reference is required.');
        }

        // Confirmed to exist in Contacts before it is stored, under the user's
        // own session — so a caller cannot attach an arbitrary identifier, and
        // cannot attach one they are not entitled to see.
        $client = new ContactsClient();
        if ($client->configured()) {
            $result = $client->withSession($auth->sesKey())->contact($contactUuid);
            if (!$result['ok']) {
                self::fail(
                    $result['state'] === 'forbidden' ? 'forbidden' : 'not_found',
                    $result['state'] === 'forbidden'
                        ? 'You do not have access to that contact in Aicountly Contacts.'
                        : 'That contact could not be found in Aicountly Contacts.',
                );
            }
        }

        \Aicountly\Api\Db::update(
            'messaging_conversations',
            ['contact_uuid' => $contactUuid, 'updated_at' => \Aicountly\Api\Support\Clock::nowSql()],
            ['cmp_id' => $ctx->cmpId, 'conversation_uuid' => $conversationUuid],
        );

        ConversationService::linkExternal($ctx, $conversationUuid, 'contacts', $contactUuid, 'subject');
        Audit::record($ctx, $auth, 'conversation.contact_matched', 'conversation', $conversationUuid, null, [
            'contact_uuid' => $contactUuid,
        ]);

        $conversation = ConversationService::find($ctx, $conversationUuid);

        Http::data([
            'conversation' => $conversation,
            'context'      => BusinessContextService::for($ctx, $auth, $conversation ?? []),
        ]);
    }

    /** Who a conversation can be assigned to. Read live from Manage. */
    public static function assignees(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.assign');

        $result = (new ManageClient())->withSession($auth->sesKey())->members($ctx->cmpId);

        if (!$result['ok']) {
            Http::data([
                'assignees' => [],
                'state'     => $result['state'],
                'message'   => 'The list of people who can be assigned comes from Aicountly Manage, which could not '
                    . 'be reached. ' . (string) $result['message'],
            ]);
        }

        $members = (array) ($result['body']['data'] ?? $result['body'] ?? []);
        $assignees = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $uuid = (string) ($member['uuid_aictly'] ?? $member['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $assignees[] = [
                'user_uuid' => $uuid,
                'name'      => (string) ($member['name'] ?? $member['email'] ?? $uuid),
                'email'     => (string) ($member['email'] ?? ''),
            ];
        }

        Http::data([
            'assignees'  => $assignees,
            'state'      => 'ready',
            'source'     => 'manage',
            'fetched_at' => $result['fetched_at'],
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * Why the composer is or is not usable.
     *
     * @param array<string, mixed>  $conversation
     * @param array<string, bool>   $capabilities
     * @return array<string, mixed>
     */
    private static function composerState(
        \Aicountly\Api\Context $ctx,
        array $conversation,
        ?ChannelConnection $connection,
        array $capabilities,
        ?\Aicountly\Api\Channels\ChannelAdapter $adapter,
        bool $canReply,
    ): array {
        if (!$canReply) {
            return [
                'enabled' => false,
                'reason'  => 'permission',
                'detail'  => 'You do not have permission to reply to conversations.',
            ];
        }
        if ($connection === null) {
            return [
                'enabled' => false,
                'reason'  => 'channel_missing',
                'detail'  => 'The channel this conversation arrived on is no longer configured.',
            ];
        }
        if ($adapter === null) {
            return [
                'enabled' => false,
                'reason'  => 'no_adapter',
                'detail'  => 'No adapter is installed for provider "' . $connection->provider . '".',
            ];
        }

        $gap = $adapter->configurationGap($connection);
        if ($gap !== null) {
            return ['enabled' => false, 'reason' => 'channel_not_configured', 'detail' => $gap];
        }

        // THE SMS CASE. An alphanumeric sender cannot receive, and a reply box
        // over one is a box that eats what an agent types.
        if (($capabilities[Capability::INBOUND] ?? false) !== true) {
            return [
                'enabled' => false,
                'reason'  => 'outbound_only',
                'detail'  => 'This sender (' . $connection->senderAddress . ') cannot receive replies, so this '
                    . 'channel is outbound only here. You can still send from a journey or a template, but there '
                    . 'is no two-way conversation to have.',
                'outbound_only' => true,
            ];
        }

        $freeform = (bool) ($capabilities[Capability::FREEFORM_TEXT] ?? false);
        $templateRequired = (bool) ($capabilities[Capability::TEMPLATE_REQUIRED_FOR_INITIATION] ?? false);

        // The provider requires a template to OPEN a conversation. Whether this
        // conversation is already open is the provider's rule and changes; what
        // we can say honestly is that the customer has written, and let the
        // provider refuse a free-form reply if its own window has closed.
        $customerHasWritten = $conversation['last_inbound_at'] !== null;

        return [
            'enabled'            => true,
            'reason'             => 'ok',
            'detail'             => '',
            'freeform_allowed'   => $freeform,
            'template_required'  => $templateRequired && !$customerHasWritten,
            'template_note'      => $templateRequired
                ? 'This channel needs a provider-approved template to start a conversation. Replies within an open '
                    . 'conversation may be free-form, and the provider decides whether the conversation is still open.'
                : null,
            'attachments_allowed' => (bool) ($capabilities[Capability::OUTBOUND_MEDIA] ?? false),
            'links_allowed'      => (bool) ($capabilities[Capability::LINKS] ?? true),
        ];
    }

    /**
     * Consent for this conversation's address, shown before anybody replies.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    private static function consentState(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, array $conversation): array
    {
        if (!Permissions::allows($ctx, $auth, 'messaging.consent.view')) {
            return ['visible' => false];
        }

        $address = (string) $conversation['customer_address'];
        $channel = (string) $conversation['channel'];

        $out = ['visible' => true, 'address' => $address, 'by_purpose' => []];
        foreach (['service', 'transactional', 'promotional'] as $purpose) {
            $verdict = ConsentService::evaluate($ctx, $channel, $address, $purpose);
            $out['by_purpose'][$purpose] = [
                'allowed' => $verdict['allowed'],
                'reason'  => $verdict['reason'],
                'detail'  => $verdict['detail'],
            ];
        }

        return $out;
    }

    /** @param array<string, bool> $capabilities @return list<array<string, mixed>> */
    private static function describeCapabilities(array $capabilities): array
    {
        $out = [];
        foreach ($capabilities as $capability => $supported) {
            $out[] = [
                'capability' => $capability,
                'label'      => Capability::describe($capability),
                'supported'  => $supported,
            ];
        }

        return $out;
    }

    /**
     * Batched live name resolution.
     *
     * @param list<string|null> $contactUuids
     * @return array{names:array<string,string>, state:string, note:?string}
     */
    private static function resolveContactNames(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, array $contactUuids): array
    {
        $ids = array_values(array_unique(array_filter($contactUuids, static fn ($id) => is_string($id) && $id !== '')));
        if ($ids === []) {
            return ['names' => [], 'state' => 'ready', 'note' => null];
        }

        $client = new ContactsClient();
        if (!$client->configured()) {
            return [
                'names' => [],
                'state' => 'pending',
                'note'  => 'Aicountly Contacts is not connected, so customer names cannot be shown. '
                    . 'The number each conversation arrived from is shown instead.',
            ];
        }

        $result = $client->withSession($auth->sesKey())->resolveMany($ids);
        if (!$result['ok']) {
            return [
                'names' => [],
                'state' => (string) $result['state'],
                'note'  => 'Customer names could not be read from Aicountly Contacts. ' . (string) $result['message'],
            ];
        }

        $names = [];
        foreach ((array) ($result['body']['data'] ?? []) as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $uuid = (string) ($contact['contact_uuid'] ?? '');
            if ($uuid !== '') {
                $names[$uuid] = (string) ($contact['name'] ?? '');
            }
        }

        return [
            'names' => $names,
            'state' => 'ready',
            'note'  => count($names) < count($ids)
                ? 'Some contacts could not be resolved and show their number instead.'
                : null,
        ];
    }

    /** Tab counts for the inbox. @return array<string, int> */
    private static function counts(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): array
    {
        [$scope, $params] = $ctx->scopeClause('c');
        $visibility = '';
        if (!Permissions::allows($ctx, $auth, 'messaging.conversations.view_all')) {
            $visibility = ' AND (c.assigned_to_uuid = :self OR c.assigned_to_uuid IS NULL)';
            $params['self'] = $auth->uuid;
        }

        $row = \Aicountly\Api\Db::first(
            'SELECT COUNT(*) AS all_open,
                    COUNT(*) FILTER (WHERE c.assigned_to_uuid = :me) AS mine,
                    COUNT(*) FILTER (WHERE c.assigned_to_uuid IS NULL) AS unassigned,
                    COUNT(*) FILTER (WHERE c.last_inbound_at IS NOT NULL
                        AND (c.last_outbound_at IS NULL OR c.last_outbound_at < c.last_inbound_at)) AS awaiting
             FROM messaging_conversations c
             WHERE ' . $scope . $visibility . ' AND c.status <> \'resolved\'',
            $params + ['me' => $auth->uuid],
        ) ?? [];

        return [
            'open'       => (int) ($row['all_open'] ?? 0),
            'mine'       => (int) ($row['mine'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'awaiting'   => (int) ($row['awaiting'] ?? 0),
        ];
    }
}
