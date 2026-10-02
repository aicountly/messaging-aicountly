<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Support\Clock;

/**
 * Consent that a calling product captured, recorded here — and only here
 * enforced.
 *
 * ## The split
 *
 * The calling product owns the moment of consent (its booking form, the
 * receptionist who asked, the relationship it already has). Messaging owns the
 * ENFORCEMENT: whatever the caller says, the send is allowed only when
 * ConsentService::evaluate() says so at the moment of dispatch. This class is
 * the narrow, audited door between the two.
 *
 * ## What the door lets through
 *
 * A request may carry a `consent` object:
 *
 *   { "basis":            "public_form_checkbox" | "staff_attestation" | "existing_relationship",
 *     "source":           "public_booking_page:<slug>" | "staff:<uuid>" | "contact:<uuid>",
 *     "captured_at":      "2026-10-02T09:14:00+05:30",
 *     "contact_verified": true | false,
 *     "evidence_ref":     "<the calling product's record, e.g. the booking uuid>" }
 *
 * and it is honoured only when ALL of these hold:
 *
 *   1. The caller's key is on the allow-list (CONSENT_SERVICE_APPS, default
 *      `appointments`) — a product we did not agree this with cannot write
 *      consent by sending a body.
 *   2. The purpose is transactional. The purpose is decided by the credential
 *      (ServiceController::purposeFor), never by the body, so a caller cannot
 *      satisfy a promotional send with a booking checkbox.
 *   3. Nothing already says no: a withdrawn record or an active suppression is
 *      NEVER overridden by a caller. Only a person, in Messaging, lifts those.
 *   4. The basis is one we know, `captured_at` is a real moment that is not in
 *      the future, and `source` and `evidence_ref` say where it came from.
 *
 * ## "Verified" is not the caller's word alone for a typed-in contact
 *
 * A guest on a public booking page types a phone number. A checkbox beside it
 * proves that SOMEONE ticked it, not that the number is theirs. So:
 *
 *   - `staff_attestation` and `existing_relationship` are a person (or an
 *     established relationship) vouching: recorded as granted when
 *     contact_verified is true.
 *   - `public_form_checkbox` is recorded as granted only when contact_verified
 *     is true, or when this deployment has explicitly decided to accept an
 *     unverified checkbox (CONSENT_ACCEPT_UNVERIFIED=1, off by default). Left
 *     at the default it is recorded as PENDING — visible to an agent, an audit
 *     trail exists, and nothing is sent to a number nobody has verified.
 *
 * Every recording is an event in the append-only consent history with
 * actor_kind 'service', so "who said this person agreed, and when" always has
 * an answer.
 */
final class ServiceConsent
{
    public const BASES = ['public_form_checkbox', 'staff_attestation', 'existing_relationship'];

    /** How far into the future a caller's captured_at may be before it is refused, in seconds (clock skew). */
    private const SKEW_SECONDS = 300;

    /**
     * Validate a consent object from a request.
     *
     * @param mixed $raw
     * @return array{ok:bool, consent:?array<string,mixed>, error:?string, field:?string}
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null) {
            return ['ok' => true, 'consent' => null, 'error' => null, 'field' => null];
        }
        if (!is_array($raw)) {
            return self::bad('consent must be an object.', 'consent');
        }

        $basis = strtolower(trim((string) ($raw['basis'] ?? '')));
        if (!in_array($basis, self::BASES, true)) {
            return self::bad('consent.basis must be one of: ' . implode(', ', self::BASES) . '.', 'consent.basis');
        }

        $capturedRaw = trim((string) ($raw['captured_at'] ?? ''));
        $captured = Clock::parse($capturedRaw);
        if ($capturedRaw === '' || $captured === null || !preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $capturedRaw)) {
            return self::bad('consent.captured_at must be an ISO 8601 instant with an offset.', 'consent.captured_at');
        }
        if ($captured->getTimestamp() > Clock::now()->getTimestamp() + self::SKEW_SECONDS) {
            return self::bad('consent.captured_at is in the future.', 'consent.captured_at');
        }

        $source = trim((string) ($raw['source'] ?? ''));
        $evidenceRef = trim((string) ($raw['evidence_ref'] ?? ''));
        if ($source === '' || strlen($source) > 120) {
            return self::bad('consent.source is required (at most 120 characters).', 'consent.source');
        }
        if ($evidenceRef === '' || strlen($evidenceRef) > 120) {
            return self::bad('consent.evidence_ref is required (at most 120 characters).', 'consent.evidence_ref');
        }

        return [
            'ok' => true,
            'consent' => [
                'basis'            => $basis,
                'source'           => $source,
                'captured_at'      => $captured->format('Y-m-d H:i:sP'),
                'captured_at_iso'  => $captured->format('c'),
                'contact_verified' => filter_var($raw['contact_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'evidence_ref'     => $evidenceRef,
            ],
            'error' => null,
            'field' => null,
        ];
    }

    /**
     * May this caller record consent by sending it?
     */
    public static function callerMayRecord(string $sourceApp): bool
    {
        $raw = Env::get('CONSENT_SERVICE_APPS');
        $apps = $raw === '' ? ['appointments'] : array_map('trim', explode(',', strtolower($raw)));

        return in_array(strtolower($sourceApp), $apps, true);
    }

    /**
     * Record what the caller captured, unless something already says no.
     *
     * Returns what was done so the response and the audit say it in words. It
     * never decides whether the SEND is allowed — ConsentService::evaluate()
     * does, immediately after, on exactly the rows this wrote.
     *
     * @param array<string, mixed> $consent from parse()
     * @return array{action:string, state:?string, consent_uuid:?string}
     */
    public static function apply(Context $ctx, Auth $auth, string $channel, string $address, array $consent): array
    {
        $normalised = ConsentService::normaliseAddress($address);

        // Rule 3: never override a no. Suppression first, then a withdrawn
        // record for ANY purpose that would satisfy a transactional send.
        $suppressed = Db::first(
            'SELECT 1 FROM messaging_suppressions
             WHERE cmp_id = :cmp AND channel = :ch AND address = :addr
               AND released_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())',
            ['cmp' => $ctx->cmpId, 'ch' => $channel, 'addr' => $normalised],
        );
        if ($suppressed !== null) {
            return ['action' => 'refused_suppressed', 'state' => null, 'consent_uuid' => null];
        }

        $existing = Db::first(
            "SELECT consent_uuid, state FROM messaging_consent_records
             WHERE cmp_id = :cmp AND channel = :ch AND address = :addr
               AND purpose IN ('transactional', 'service', 'all')
             ORDER BY CASE state WHEN 'withdrawn' THEN 0 WHEN 'granted' THEN 1 ELSE 2 END
             LIMIT 1",
            ['cmp' => $ctx->cmpId, 'ch' => $channel, 'addr' => $normalised],
        );
        if ($existing !== null && (string) $existing['state'] === 'withdrawn') {
            return ['action' => 'refused_withdrawn', 'state' => 'withdrawn', 'consent_uuid' => (string) $existing['consent_uuid']];
        }
        if ($existing !== null && (string) $existing['state'] === 'granted') {
            // Already consented (by anyone, any evidence). Nothing to add, and
            // re-recording would overwrite the original evidence.
            return ['action' => 'already_granted', 'state' => 'granted', 'consent_uuid' => (string) $existing['consent_uuid']];
        }

        $verified = (bool) $consent['contact_verified'];
        $grant = match ((string) $consent['basis']) {
            'public_form_checkbox' => $verified || Env::get('CONSENT_ACCEPT_UNVERIFIED') === '1',
            default                => $verified,
        };

        $detail = sprintf(
            '%s recorded at booking: basis=%s; source=%s; evidence_ref=%s; contact_verified=%s%s',
            (string) $auth->sourceApp,
            (string) $consent['basis'],
            (string) $consent['source'],
            (string) $consent['evidence_ref'],
            $verified ? 'true' : 'false',
            $grant && !$verified ? '; accepted unverified by deployment policy' : '',
        );

        $state = $grant ? 'granted' : 'pending';
        $uuid = ConsentService::record(
            $ctx,
            $auth,
            $channel,
            $address,
            'transactional',
            $state,
            'service_booking',
            $detail,
            null,
            (string) $consent['captured_at'],
            null,
            'service',
        );

        return ['action' => $grant ? 'granted' : 'pending', 'state' => $state, 'consent_uuid' => $uuid];
    }

    /** @return array{ok:bool, consent:?array<string,mixed>, error:?string, field:?string} */
    private static function bad(string $error, string $field): array
    {
        return ['ok' => false, 'consent' => null, 'error' => $error, 'field' => $field];
    }
}
