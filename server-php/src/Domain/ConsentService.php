<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Consent and suppression.
 *
 * ## Two rules, and everything here serves them
 *
 * 1. CONSENT IS CHECKED AT DISPATCH. Not at approval, not when a journey
 *    starts, not when a campaign is planned. An approval granted yesterday
 *    does not override an opt-out received today, and the only way to be sure
 *    of that is to read consent in the moment before the provider call. See
 *    DispatchGuard, which calls `evaluate()` on every send with no exception
 *    and no cache.
 *
 * 2. CONSENT IS PER CHANNEL AND PER PURPOSE. "Yes, text me about my order" is
 *    not "yes, WhatsApp me about your sale". A single boolean per customer is
 *    the most common way a messaging product becomes a regulatory problem, and
 *    the schema will not let this one hold that shape.
 *
 * ## What this class does not claim
 *
 * It does not say a deployment is compliant. It records what was decided, by
 * whom, on what evidence, and it refuses sends accordingly. Whether that
 * satisfies a particular jurisdiction is a question for the business and its
 * advisers, and a product that printed "fully compliant" on a dashboard would
 * be making a promise it is in no position to make.
 */
final class ConsentService
{
    /**
     * The purposes a send can have, and which consent satisfies each.
     *
     * 'all' is a blanket grant and satisfies anything. A 'transactional' grant
     * does NOT satisfy a promotional send — the whole point of recording the
     * purpose is that it narrows.
     */
    private const SATISFIED_BY = [
        'transactional' => ['transactional', 'service', 'all'],
        'service'       => ['service', 'all'],
        'promotional'   => ['promotional', 'all'],
    ];

    /**
     * May we send to this address, on this channel, for this purpose?
     *
     * @return array{allowed:bool, reason:string, detail:string, consent_uuid:?string, checked_at:string}
     */
    public static function evaluate(Context $ctx, string $channel, string $address, string $purpose): array
    {
        $address = self::normaliseAddress($address);
        $now = Clock::nowSql();

        if ($address === '') {
            return self::verdict(false, 'no_address', 'There is no address to send to.');
        }

        // SUPPRESSION FIRST. A hard bounce or a provider block stops a send
        // regardless of what consent says, because the message physically will
        // not arrive — and a spam complaint stops it regardless of what a form
        // once recorded.
        $suppression = Db::first(
            'SELECT suppression_uuid, reason, detail, expires_at
             FROM messaging_suppressions
             WHERE cmp_id = :cmp AND channel = :ch AND address = :addr
               AND released_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1',
            ['cmp' => $ctx->cmpId, 'ch' => $channel, 'addr' => $address],
        );

        if ($suppression !== null) {
            return self::verdict(
                false,
                'suppressed',
                self::describeSuppression((string) $suppression['reason'], (string) ($suppression['detail'] ?? '')),
            );
        }

        $acceptable = self::SATISFIED_BY[$purpose] ?? ['all'];

        $record = Db::first(
            'SELECT consent_uuid, state, purpose, recorded_at, evidence_source
             FROM messaging_consent_records
             WHERE cmp_id = :cmp AND channel = :ch AND address = :addr
               AND purpose = ANY(:purposes)
             ORDER BY CASE state WHEN :withdrawn THEN 0 ELSE 1 END, recorded_at DESC
             LIMIT 1',
            [
                'cmp'       => $ctx->cmpId,
                'ch'        => $channel,
                'addr'      => $address,
                // A withdrawal outranks a later grant of a narrower purpose:
                // ordering withdrawals first means an explicit opt-out is what
                // we find, not a stale 'granted' row beside it.
                'purposes'  => '{' . implode(',', $acceptable) . '}',
                'withdrawn' => 'withdrawn',
            ],
        );

        if ($record === null) {
            return self::verdict(
                false,
                'no_consent',
                'No ' . $purpose . ' consent is recorded for this ' . $channel . ' address.',
            );
        }

        if ((string) $record['state'] === 'withdrawn') {
            return self::verdict(
                false,
                'withdrawn',
                'Consent for this address was withdrawn.',
                (string) $record['consent_uuid'],
            );
        }

        if ((string) $record['state'] !== 'granted') {
            return self::verdict(
                false,
                'consent_pending',
                'Consent for this address has not been confirmed yet.',
                (string) $record['consent_uuid'],
            );
        }

        return [
            'allowed'      => true,
            'reason'       => 'granted',
            'detail'       => 'Consent recorded ' . (string) $record['recorded_at']
                . ' (' . ((string) $record['evidence_source'] ?: 'source not recorded') . ').',
            'consent_uuid' => (string) $record['consent_uuid'],
            'checked_at'   => $now,
        ];
    }

    /**
     * Record a grant or a withdrawal.
     *
     * The current record is upserted and the EVENT is always appended. The
     * event log is what answers "when did they opt out, and did anything go out
     * after that?", and it is append-only for that reason.
     */
    public static function record(
        Context $ctx,
        Auth $auth,
        string $channel,
        string $address,
        string $purpose,
        string $state,
        string $evidenceSource = '',
        string $evidenceDetail = '',
        ?string $contactUuid = null,
        ?string $occurredAt = null,
        ?string $messageUuid = null,
        string $actorKind = 'user',
    ): string {
        $address = self::normaliseAddress($address);
        $now = Clock::nowSql();

        return Db::transaction(static function () use (
            $ctx, $auth, $channel, $address, $purpose, $state,
            $evidenceSource, $evidenceDetail, $contactUuid, $occurredAt, $messageUuid, $actorKind, $now
        ): string {
            $existing = Db::first(
                'SELECT consent_uuid FROM messaging_consent_records
                 WHERE cmp_id = :cmp AND channel = :ch AND address = :addr AND purpose = :purpose
                 FOR UPDATE',
                ['cmp' => $ctx->cmpId, 'ch' => $channel, 'addr' => $address, 'purpose' => $purpose],
            );

            if ($existing !== null) {
                $consentUuid = (string) $existing['consent_uuid'];
                Db::run(
                    'UPDATE messaging_consent_records
                     SET state = :state, evidence_source = :src, evidence_detail = :detail,
                         occurred_at = :occurred, recorded_at = :now, recorded_by_uuid = :actor,
                         contact_uuid = COALESCE(:contact, contact_uuid),
                         row_version = row_version + 1
                     WHERE consent_uuid = :uuid',
                    [
                        'state'    => $state,
                        'src'      => $evidenceSource,
                        'detail'   => $evidenceDetail,
                        'occurred' => $occurredAt,
                        'now'      => $now,
                        'actor'    => $auth->uuid,
                        'contact'  => $contactUuid,
                        'uuid'     => $consentUuid,
                    ],
                );
            } else {
                $consentUuid = Uuid::v4();
                Db::insert('messaging_consent_records', [
                    'consent_uuid'     => $consentUuid,
                    'cmp_id'           => $ctx->cmpId,
                    'bo_id'            => $ctx->boId,
                    'contact_uuid'     => $contactUuid,
                    'channel'          => $channel,
                    'address'          => $address,
                    'purpose'          => $purpose,
                    'state'            => $state,
                    'evidence_source'  => $evidenceSource,
                    'evidence_detail'  => $evidenceDetail,
                    'occurred_at'      => $occurredAt,
                    'recorded_at'      => $now,
                    'recorded_by_uuid' => $auth->uuid,
                ], 'consent_uuid');
            }

            Db::insert('messaging_consent_events', [
                'cmp_id'          => $ctx->cmpId,
                'consent_uuid'    => $consentUuid,
                'channel'         => $channel,
                'address'         => $address,
                'purpose'         => $purpose,
                'event'           => $state === 'granted' ? 'granted' : 'withdrawn',
                'evidence_source' => $evidenceSource,
                'evidence_detail' => $evidenceDetail,
                'message_uuid'    => $messageUuid,
                'actor_uuid'      => $auth->uuid,
                'actor_kind'      => $actorKind,
                'occurred_at'     => $occurredAt,
                'created_at'      => $now,
            ], 'consent_event_id');

            return $consentUuid;
        });
    }

    /**
     * Suppress an address.
     *
     * Called by an agent, and called by the webhook pipeline when a provider
     * reports a hard bounce or an opt-out. The reason is kept because
     * "suppressed" without it is unactionable: a provider block that clears
     * tomorrow and a customer who never wants to hear from you again need
     * completely different handling.
     */
    public static function suppress(
        Context $ctx,
        Auth $auth,
        string $channel,
        string $address,
        string $reason,
        string $detail = '',
        ?string $expiresAt = null,
    ): string {
        $address = self::normaliseAddress($address);

        $existing = Db::first(
            'SELECT suppression_uuid FROM messaging_suppressions
             WHERE cmp_id = :cmp AND channel = :ch AND address = :addr AND released_at IS NULL',
            ['cmp' => $ctx->cmpId, 'ch' => $channel, 'addr' => $address],
        );
        if ($existing !== null) {
            return (string) $existing['suppression_uuid'];
        }

        $uuid = Uuid::v4();
        Db::insert('messaging_suppressions', [
            'suppression_uuid' => $uuid,
            'cmp_id'           => $ctx->cmpId,
            'channel'          => $channel,
            'address'          => $address,
            'reason'           => $reason,
            'detail'           => $detail,
            'expires_at'       => $expiresAt,
            'created_at'       => Clock::nowSql(),
            'created_by_uuid'  => $auth->uuid,
        ], 'suppression_uuid');

        return $uuid;
    }

    /**
     * Lift a suppression.
     *
     * Requires `messaging.consent.override` at the controller, is audited, and
     * is never a delete — the row stays with the name of whoever released it
     * and why. A suppression list you can quietly erase is not evidence of
     * anything.
     */
    public static function release(Context $ctx, Auth $auth, string $suppressionUuid, string $reason): bool
    {
        $affected = Db::run(
            'UPDATE messaging_suppressions
             SET released_at = :now, released_by_uuid = :actor, release_reason = :reason
             WHERE cmp_id = :cmp AND suppression_uuid = :uuid AND released_at IS NULL',
            [
                'now'    => Clock::nowSql(),
                'actor'  => $auth->uuid,
                'reason' => $reason,
                'cmp'    => $ctx->cmpId,
                'uuid'   => $suppressionUuid,
            ],
        )->rowCount();

        return $affected > 0;
    }

    /**
     * Detect an opt-out in an inbound message.
     *
     * Deliberately conservative. A false positive silences a customer who
     * wanted to talk; the words here are the unambiguous ones. Anything more
     * creative belongs to a human reading the thread, not to a regex.
     */
    public static function detectOptOut(string $body): bool
    {
        // \p{M} matters: 'बंद' is a letter, a combining anusvara and a letter, so
        // a class of letters alone would strip the mark and leave 'बद' —
        // silently making every Devanagari keyword below unmatchable, and a
        // Hindi-speaking customer's opt-out invisible.
        $normalised = mb_strtoupper(trim(preg_replace('/[^\p{L}\p{M}\s]/u', '', $body) ?? ''));

        return in_array($normalised, [
            'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'OPT OUT',
            // Hindi, since the product ships with Hindi support.
            'बंद', 'बंद करो', 'रोको',
        ], true);
    }

    /** Counts for the Channels & Trust screen. @return array<string, int> */
    public static function summary(Context $ctx): array
    {
        $optedIn = (int) (Db::scalar(
            'SELECT COUNT(DISTINCT address) FROM messaging_consent_records
             WHERE cmp_id = :cmp AND state = :state',
            ['cmp' => $ctx->cmpId, 'state' => 'granted'],
        ) ?? 0);

        $suppressed = (int) (Db::scalar(
            'SELECT COUNT(*) FROM messaging_suppressions
             WHERE cmp_id = :cmp AND released_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())',
            ['cmp' => $ctx->cmpId],
        ) ?? 0);

        $withdrawnThisMonth = (int) (Db::scalar(
            "SELECT COUNT(*) FROM messaging_consent_events
             WHERE cmp_id = :cmp AND event = 'withdrawn' AND created_at >= NOW() - INTERVAL '30 days'",
            ['cmp' => $ctx->cmpId],
        ) ?? 0);

        return [
            'opted_in_addresses'    => $optedIn,
            'suppressed_addresses'  => $suppressed,
            'withdrawn_30d'         => $withdrawnThisMonth,
        ];
    }

    /**
     * One canonical form per address, so consent cannot be bypassed by
     * formatting.
     *
     * `+91 98765 43210` and `+919876543210` are the same person. Storing them
     * as two rows would mean an opt-out on one and a send on the other, which
     * is the bug this method exists to prevent.
     */
    public static function normaliseAddress(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }

        // A phone-shaped address collapses to E.164 digits.
        if (preg_match('/^\+?[0-9 ()\-.]+$/', $address) === 1) {
            $digits = preg_replace('/[^0-9]/', '', $address) ?? '';

            return $digits === '' ? '' : '+' . $digits;
        }

        return strtolower($address);
    }

    private static function describeSuppression(string $reason, string $detail): string
    {
        $base = match ($reason) {
            'customer_optout' => 'This customer has opted out of messages on this channel.',
            'hard_bounce'     => 'This address permanently rejected a previous message.',
            'provider_block'  => 'The provider is blocking messages to this address.',
            'spam_complaint'  => 'This address reported a previous message as spam.',
            'invalid_address' => 'This address is not a valid destination for this channel.',
            'regulatory'      => 'This address is suppressed for regulatory reasons.',
            'manual'          => 'This address was suppressed manually.',
            default           => 'This address is suppressed.',
        };

        return $detail !== '' ? $base . ' ' . $detail : $base;
    }

    /** @return array{allowed:bool, reason:string, detail:string, consent_uuid:?string, checked_at:string} */
    private static function verdict(bool $allowed, string $reason, string $detail, ?string $consentUuid = null): array
    {
        return [
            'allowed'      => $allowed,
            'reason'       => $reason,
            'detail'       => $detail,
            'consent_uuid' => $consentUuid,
            'checked_at'   => Clock::nowSql(),
        ];
    }
}
