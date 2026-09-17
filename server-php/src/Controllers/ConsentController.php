<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Consent and suppression management.
 */
final class ConsentController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter('messaging.consent.view');

        $params = Http::listParams(['recorded_at'], 'recorded_at');
        $where = ['cmp_id = :cmp'];
        $bind = ['cmp' => $ctx->cmpId];

        if (($channel = Http::param('channel', '')) !== '') {
            $where[] = 'channel = :channel';
            $bind['channel'] = $channel;
        }
        if (($state = Http::param('state', '')) !== '') {
            $where[] = 'state = :state';
            $bind['state'] = $state;
        }
        if ($params['q'] !== '') {
            $where[] = 'address ILIKE :q';
            $bind['q'] = '%' . $params['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) (Db::scalar('SELECT COUNT(*) FROM messaging_consent_records WHERE ' . $whereSql, $bind) ?? 0);

        $rows = Db::all(
            'SELECT consent_uuid, contact_uuid, channel, address, purpose, state,
                    evidence_source, evidence_detail, occurred_at, recorded_at, recorded_by_uuid, row_version
             FROM messaging_consent_records WHERE ' . $whereSql . '
             ORDER BY recorded_at DESC LIMIT :limit OFFSET :offset',
            $bind + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list($rows, $total, $params['limit'], $params['offset'], [
            'summary' => ConsentService::summary($ctx),
            'purposes' => [
                'transactional' => 'Order and invoice updates a customer is expecting.',
                'service'       => 'Replies and service messages within a conversation.',
                'promotional'   => 'Marketing. A transactional grant does not cover this.',
                'all'           => 'A blanket grant covering every purpose.',
            ],
            'note' => 'Consent is recorded per channel AND per purpose. "Yes, text me about my order" is not '
                . '"yes, WhatsApp me about your sale", and this product will not treat it as one.',
        ]);
    }

    public static function record(): void
    {
        [$auth, $ctx] = self::enter('messaging.consent.manage');

        $body = Http::body();
        $channel = (string) ($body['channel'] ?? '');
        $address = (string) ($body['address'] ?? '');
        $purpose = (string) ($body['purpose'] ?? '');
        $state = (string) ($body['state'] ?? 'granted');

        if ($channel === '' || $address === '' || $purpose === '') {
            Http::validationFailed('channel, address and purpose are required.');
        }
        if (!in_array($purpose, ['transactional', 'service', 'promotional', 'all'], true)) {
            Http::validationFailed('Unknown purpose "' . $purpose . '".');
        }
        if (!in_array($state, ['granted', 'withdrawn', 'pending'], true)) {
            Http::validationFailed('State must be granted, withdrawn or pending.');
        }

        $evidenceSource = (string) ($body['evidence_source'] ?? 'agent_recorded');
        $evidenceDetail = trim((string) ($body['evidence_detail'] ?? ''));

        // Evidence is REQUIRED to record a grant. A consent row with no
        // provenance is worth nothing six months later in front of somebody
        // asking where it came from.
        if ($state === 'granted' && $evidenceDetail === '') {
            Http::validationFailed(
                'Recording consent needs evidence: say where it came from (a form, a message, a signed contract). '
                . 'A grant with no provenance cannot be relied on later.',
                ['field' => 'evidence_detail'],
            );
        }

        $consentUuid = ConsentService::record(
            $ctx,
            $auth,
            $channel,
            $address,
            $purpose,
            $state,
            $evidenceSource,
            $evidenceDetail,
            isset($body['contact_uuid']) ? (string) $body['contact_uuid'] : null,
            isset($body['occurred_at']) ? (string) $body['occurred_at'] : null,
        );

        Audit::record($ctx, $auth, 'consent.' . $state, 'consent', $consentUuid, null, [
            'channel'         => $channel,
            'purpose'         => $purpose,
            'evidence_source' => $evidenceSource,
        ]);

        Http::data([
            'consent_uuid' => $consentUuid,
            'detail'       => 'Recorded. Dispatch re-reads consent immediately before every send, so this takes '
                . 'effect on the next message and on anything already queued.',
        ], 201);
    }

    public static function withdraw(string $consentUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.consent.manage');

        $record = Db::first(
            'SELECT * FROM messaging_consent_records WHERE cmp_id = :cmp AND consent_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $consentUuid],
        );
        if ($record === null) {
            Http::notFound('That consent record could not be found.');
        }

        ConsentService::record(
            $ctx,
            $auth,
            (string) $record['channel'],
            (string) $record['address'],
            (string) $record['purpose'],
            'withdrawn',
            (string) (Http::param('evidence_source') ?? 'agent_recorded'),
            (string) (Http::param('evidence_detail') ?? 'Withdrawn by an agent.'),
            $record['contact_uuid'] !== null ? (string) $record['contact_uuid'] : null,
        );

        // A withdrawal also suppresses, so a queued message stops. Withdrawing
        // consent without suppressing would leave an already-queued message to
        // send anyway, which is the exact thing the brief asks not to happen.
        ConsentService::suppress(
            $ctx,
            $auth,
            (string) $record['channel'],
            (string) $record['address'],
            'customer_optout',
            'Consent withdrawn.',
        );

        Audit::record($ctx, $auth, 'consent.withdrawn', 'consent', $consentUuid, null, [
            'channel' => (string) $record['channel'],
            'purpose' => (string) $record['purpose'],
        ]);

        Http::data([
            'detail' => 'Withdrawn and suppressed. Anything already queued for this address will be refused at '
                . 'dispatch rather than sent.',
        ]);
    }

    public static function history(): void
    {
        [$auth, $ctx] = self::enter('messaging.consent.view');

        $address = trim((string) (Http::param('address') ?? ''));
        $channel = trim((string) (Http::param('channel') ?? ''));

        if ($address === '') {
            Http::validationFailed('An address is required.');
        }

        $normalised = ConsentService::normaliseAddress($address);
        $bind = ['cmp' => $ctx->cmpId, 'addr' => $normalised];
        $channelClause = '';
        if ($channel !== '') {
            $channelClause = ' AND channel = :channel';
            $bind['channel'] = $channel;
        }

        $events = Db::all(
            'SELECT consent_event_id, channel, address, purpose, event, evidence_source, evidence_detail,
                    message_uuid, actor_uuid, actor_kind, occurred_at, created_at
             FROM messaging_consent_events
             WHERE cmp_id = :cmp AND address = :addr' . $channelClause . '
             ORDER BY created_at DESC LIMIT 200',
            $bind,
        );

        $suppressions = Db::all(
            'SELECT suppression_uuid, channel, address, reason, detail, expires_at,
                    created_at, created_by_uuid, released_at, released_by_uuid, release_reason
             FROM messaging_suppressions
             WHERE cmp_id = :cmp AND address = :addr' . $channelClause . '
             ORDER BY created_at DESC',
            $bind,
        );

        Http::data([
            'address'      => $normalised,
            'events'       => $events,
            'suppressions' => $suppressions,
            'note'         => 'This history is append-only. A suppression that was lifted keeps its row, with who '
                . 'lifted it and why — a suppression list you can quietly erase is not evidence of anything.',
        ]);
    }

    public static function suppressions(): void
    {
        [$auth, $ctx] = self::enter('messaging.consent.view');

        $params = Http::listParams(['created_at'], 'created_at');
        $where = ['cmp_id = :cmp'];
        $bind = ['cmp' => $ctx->cmpId];

        if (!Http::boolParam('include_released')) {
            $where[] = 'released_at IS NULL';
        }
        if (($channel = Http::param('channel', '')) !== '') {
            $where[] = 'channel = :channel';
            $bind['channel'] = $channel;
        }
        if (($reason = Http::param('reason', '')) !== '') {
            $where[] = 'reason = :reason';
            $bind['reason'] = $reason;
        }
        if ($params['q'] !== '') {
            $where[] = 'address ILIKE :q';
            $bind['q'] = '%' . $params['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM messaging_suppressions WHERE ' . $whereSql, $bind) ?? 0);

        $rows = Db::all(
            'SELECT * FROM messaging_suppressions WHERE ' . $whereSql . '
             ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            $bind + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list($rows, $total, $params['limit'], $params['offset'], [
            'reasons' => [
                'customer_optout' => 'The customer asked not to be messaged.',
                'hard_bounce'     => 'The address permanently rejected a message.',
                'provider_block'  => 'The provider is blocking this address.',
                'spam_complaint'  => 'The address reported a message as spam.',
                'invalid_address' => 'Not a valid destination for this channel.',
                'regulatory'      => 'Suppressed for regulatory reasons.',
                'manual'          => 'Suppressed by hand.',
            ],
            'can_override' => Permissions::allows($ctx, $auth, 'messaging.consent.override'),
        ]);
    }

    public static function suppress(): void
    {
        [$auth, $ctx] = self::enter('messaging.consent.manage');

        $body = Http::body();
        $channel = (string) ($body['channel'] ?? '');
        $address = (string) ($body['address'] ?? '');
        $reason = (string) ($body['reason'] ?? 'manual');

        if ($channel === '' || $address === '') {
            Http::validationFailed('channel and address are required.');
        }

        $uuid = ConsentService::suppress(
            $ctx,
            $auth,
            $channel,
            $address,
            in_array($reason, ['customer_optout', 'hard_bounce', 'provider_block', 'spam_complaint',
                'invalid_address', 'manual', 'regulatory'], true) ? $reason : 'manual',
            trim((string) ($body['detail'] ?? '')),
            isset($body['expires_at']) ? (string) $body['expires_at'] : null,
        );

        Audit::record($ctx, $auth, 'address.suppressed', 'suppression', $uuid, null, [
            'channel' => $channel,
            'reason'  => $reason,
        ]);

        Http::data(['suppression_uuid' => $uuid, 'detail' => 'Suppressed.'], 201);
    }

    /**
     * Lift a suppression.
     *
     * The most restricted action on this screen. It reaches past somebody's
     * opt-out, so it needs its own permission, a stated reason, and an
     * immutable audit row naming who did it.
     */
    public static function release(string $suppressionUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.consent.override');

        $reason = trim((string) (Http::param('reason') ?? ''));
        if ($reason === '') {
            Http::validationFailed(
                'Lifting a suppression needs a reason. It reaches past a decision the customer or the provider '
                . 'made, and the reason is recorded permanently against your name.',
                ['field' => 'reason'],
            );
        }

        $record = Db::first(
            'SELECT reason, address, channel FROM messaging_suppressions
             WHERE cmp_id = :cmp AND suppression_uuid = :uuid AND released_at IS NULL',
            ['cmp' => $ctx->cmpId, 'uuid' => $suppressionUuid],
        );
        if ($record === null) {
            Http::notFound('That suppression could not be found, or it has already been lifted.');
        }

        // A customer's own opt-out is the one case this refuses outright. No
        // permission in this product lets somebody override a person saying
        // "stop"; the customer has to opt back in, which is recorded as their
        // decision with its own evidence.
        if ((string) $record['reason'] === 'customer_optout') {
            Http::forbidden(
                'This address was suppressed because the CUSTOMER opted out. That cannot be overridden here. '
                . 'If they have asked to hear from you again, record a new consent grant with evidence of their '
                . 'request — which makes it their decision rather than yours.',
            );
        }

        if (!ConsentService::release($ctx, $auth, $suppressionUuid, $reason)) {
            self::fail('not_found', 'That suppression could not be lifted.');
        }

        Audit::record($ctx, $auth, 'suppression.released', 'suppression', $suppressionUuid, [
            'reason' => (string) $record['reason'],
        ], ['released' => true], $reason);

        Http::data([
            'detail' => 'Lifted. The row remains, with your name and the reason against it.',
        ]);
    }
}
