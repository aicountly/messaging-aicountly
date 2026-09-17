<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * The contacts browser.
 *
 * ## A window onto Aicountly Contacts, not a copy of it
 *
 * Every row here is fetched from Contacts on the request that draws it. There
 * is no contact table in this product, no nightly import, and no search index
 * built from copied records. When Contacts is unavailable, this screen says so
 * and shows nothing — it does not fall back to a stale local list, because a
 * stale address book is how a business messages the wrong number.
 *
 * ## What Messaging adds
 *
 * Alongside each contact: the messaging-shaped part of knowing somebody, which
 * IS ours. How many conversations, when we last spoke, what their consent says,
 * whether they are suppressed. None of that is Contacts' business and none of
 * it is stored there.
 */
final class ContactsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $client = new ContactsClient();
        if (!$client->configured()) {
            Http::data([
                'contacts' => [],
                'state'    => 'pending',
                'message'  => $client->unavailableMessage()
                    . ' This screen is a live view of Aicountly Contacts; there is no local copy to show instead.',
                'source'   => 'contacts',
            ]);
        }

        $params = Http::listParams(['name'], 'name');
        $result = $client->withSession($auth->sesKey())->search($params['q'], [
            'limit'  => $params['limit'],
            'offset' => $params['offset'],
        ]);

        if (!$result['ok']) {
            Http::data([
                'contacts' => [],
                'state'    => $result['state'],
                'message'  => $result['message'],
                'source'   => 'contacts',
                'retryable' => (bool) $result['retryable'],
            ]);
        }

        $contacts = [];
        foreach ((array) ($result['body']['data'] ?? []) as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $contactUuid = (string) ($contact['contact_uuid'] ?? '');

            $contacts[] = [
                // From Contacts, live.
                'contact_uuid' => $contactUuid,
                'name'         => (string) ($contact['name'] ?? ''),
                'mobile'       => (string) ($contact['mobile'] ?? ''),
                'email'        => (string) ($contact['email'] ?? ''),
                'language'     => (string) ($contact['preferred_language'] ?? ''),
                // Ours.
                'messaging'    => $contactUuid !== '' ? self::messagingProfile($ctx, $contactUuid, (string) ($contact['mobile'] ?? '')) : null,
            ];
        }

        Http::data([
            'contacts'   => $contacts,
            'state'      => 'ready',
            'source'     => 'contacts',
            'fetched_at' => $result['fetched_at'],
            'meta'       => [
                'limit'  => $params['limit'],
                'offset' => $params['offset'],
                'total'  => (int) ($result['body']['meta']['total'] ?? count($contacts)),
            ],
            'note' => 'Names and numbers come from Aicountly Contacts on this request. The conversation counts and '
                . 'consent state beside each one are Messaging\'s own records.',
        ]);
    }

    public static function show(string $contactUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $client = new ContactsClient();
        $contact = null;
        $state = 'pending';
        $message = $client->unavailableMessage();
        $fetchedAt = gmdate('c');

        if ($client->configured()) {
            $result = $client->withSession($auth->sesKey())->contact($contactUuid);
            $state = (string) $result['state'];
            $message = (string) $result['message'];
            $fetchedAt = (string) $result['fetched_at'];

            if ($result['ok']) {
                $body = $result['body']['data'] ?? $result['body'] ?? [];
                $contact = [
                    'contact_uuid' => (string) ($body['contact_uuid'] ?? $contactUuid),
                    'name'         => (string) ($body['name'] ?? ''),
                    'mobile'       => (string) ($body['mobile'] ?? ''),
                    'email'        => (string) ($body['email'] ?? ''),
                    'language'     => (string) ($body['preferred_language'] ?? ''),
                ];
            }
        }

        // Messaging's own view of this person survives Contacts being down,
        // because it is ours. The identity does not, and is reported as
        // unavailable rather than guessed.
        $conversations = Db::all(
            'SELECT conversation_uuid, channel, customer_address, status, priority,
                    last_inbound_at, last_outbound_at, created_at, resolved_at
             FROM messaging_conversations
             WHERE cmp_id = :cmp AND contact_uuid = :contact
             ORDER BY COALESCE(last_inbound_at, created_at) DESC LIMIT 50',
            ['cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        );

        Http::data([
            'contact'       => $contact,
            'contact_state' => $contact !== null ? 'ready' : $state,
            'contact_message' => $contact !== null ? null : $message,
            'contact_source' => 'contacts',
            'contact_fetched_at' => $fetchedAt,
            'conversations' => $conversations,
            'messaging'     => self::messagingProfile($ctx, $contactUuid, (string) ($contact['mobile'] ?? '')),
            'consent'       => Permissions::allows($ctx, $auth, 'messaging.consent.view')
                ? self::consentRecords($ctx, $contactUuid)
                : null,
        ]);
    }

    /**
     * Messaging's own facts about a contact.
     *
     * @return array<string, mixed>
     */
    private static function messagingProfile(\Aicountly\Api\Context $ctx, string $contactUuid, string $mobile): array
    {
        $row = Db::first(
            'SELECT COUNT(*) AS conversations,
                    COUNT(*) FILTER (WHERE status <> \'resolved\') AS open_conversations,
                    MAX(last_inbound_at) AS last_heard_from,
                    MAX(last_outbound_at) AS last_contacted
             FROM messaging_conversations
             WHERE cmp_id = :cmp AND contact_uuid = :contact',
            ['cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        ) ?? [];

        $address = ConsentService::normaliseAddress($mobile);
        $suppressed = null;

        if ($address !== '') {
            $suppression = Db::first(
                'SELECT channel, reason FROM messaging_suppressions
                 WHERE cmp_id = :cmp AND address = :addr AND released_at IS NULL
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1',
                ['cmp' => $ctx->cmpId, 'addr' => $address],
            );
            $suppressed = $suppression === null ? null : [
                'channel' => (string) $suppression['channel'],
                'reason'  => (string) $suppression['reason'],
            ];
        }

        return [
            'conversations'      => (int) ($row['conversations'] ?? 0),
            'open_conversations' => (int) ($row['open_conversations'] ?? 0),
            'last_heard_from'    => $row['last_heard_from'] ?? null,
            'last_contacted'     => $row['last_contacted'] ?? null,
            'suppressed'         => $suppressed,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function consentRecords(\Aicountly\Api\Context $ctx, string $contactUuid): array
    {
        return Db::all(
            'SELECT consent_uuid, channel, address, purpose, state, evidence_source, evidence_detail,
                    occurred_at, recorded_at
             FROM messaging_consent_records
             WHERE cmp_id = :cmp AND contact_uuid = :contact
             ORDER BY recorded_at DESC',
            ['cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        );
    }
}
