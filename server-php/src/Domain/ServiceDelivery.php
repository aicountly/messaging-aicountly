<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

/**
 * What a calling product is told about one message, in ITS words.
 *
 * Messaging's own lifecycle (MessageState) has eleven states and two of them,
 * `provider_accepted` and `delivered`, are different facts on purpose. A
 * calling product does not need the eleven; it needs to know what to do next,
 * and the answer is one of these:
 *
 *   queued      Messaging has it and will hand it to the provider (or retry).
 *               Nothing has reached the provider yet, or it is being retried.
 *   sent        The provider took responsibility. NOT delivery.
 *   delivered   The provider reported that it arrived (a read receipt counts).
 *   failed      Terminal. Messaging retried what it could; the message did not
 *               go and will not.
 *   suppressed  Terminal. Consent was missing, withdrawn or never confirmed,
 *               or the address is suppressed. Never retry this.
 *   expired     Terminal. `not_after` passed before it could be delivered.
 *   cancelled   Terminal. The caller (or an agent) withdrew it before dispatch.
 *   unknown     The send timed out and the provider may have taken it. Not
 *               resent; ask again later.
 *
 * `suppressed` is a wire word, not a stored state: a refusal for consent leaves
 * no message row at all when it happens before drafting, and when it happens at
 * dispatch the message is stored as `failed` with a consent failure_code. Both
 * are reported as `suppressed` here so the caller never has to know which.
 *
 * This is the ONLY place the mapping lives. The status read, the send response
 * and a replayed response all go through it, so they cannot disagree.
 */
final class ServiceDelivery
{
    public const QUEUED     = 'queued';
    public const SENT       = 'sent';
    public const DELIVERED  = 'delivered';
    public const FAILED     = 'failed';
    public const SUPPRESSED = 'suppressed';
    public const EXPIRED    = 'expired';
    public const CANCELLED  = 'cancelled';
    public const UNKNOWN    = 'unknown';

    /** Failure codes that mean "consent / suppression said no", wherever they were recorded. */
    private const SUPPRESSED_CODES = ['no_consent', 'suppressed', 'withdrawn', 'consent_pending', 'no_address'];

    /** Terminal states: nothing further will happen to the message. */
    public const TERMINAL = [self::DELIVERED, self::FAILED, self::SUPPRESSED, self::EXPIRED, self::CANCELLED];

    /**
     * @param array<string, mixed> $message a messaging_messages row
     * @return array{delivery_state:string, reason_code:?string, reason:?string}
     */
    public static function describe(array $message): array
    {
        $status = (string) ($message['status'] ?? '');
        $code = ($message['failure_code'] ?? null) !== null ? (string) $message['failure_code'] : null;
        $detail = ($message['failure_detail'] ?? null) !== null ? (string) $message['failure_detail'] : null;

        switch ($status) {
            case MessageState::PROVIDER_ACCEPTED:
                return self::row(self::SENT, null, null);

            case MessageState::DELIVERED:
            case MessageState::READ:
                return self::row(self::DELIVERED, null, null);

            case MessageState::FAILED:
                if ($code !== null && in_array($code, self::SUPPRESSED_CODES, true)) {
                    return self::row(self::SUPPRESSED, $code, $detail);
                }

                return self::row(self::FAILED, $code, $detail);

            case MessageState::CANCELLED:
                // The code says WHY it was cancelled. Withdrawn by somebody is
                // `cancelled`; the clock ran out is `expired`; a consent or
                // suppression check said no is `suppressed`; any other gate
                // that stopped it (a template pulled after drafting, a missing
                // attachment) means it did not go and will not: `failed`.
                if ($code === null || $code === 'cancelled') {
                    return self::row(self::CANCELLED, 'cancelled', $detail);
                }
                if ($code === 'expired') {
                    return self::row(self::EXPIRED, 'expired', $detail);
                }
                if (in_array($code, self::SUPPRESSED_CODES, true)) {
                    return self::row(self::SUPPRESSED, $code, $detail);
                }

                return self::row(self::FAILED, $code, $detail);

            case MessageState::SUBMISSION_UNKNOWN:
                return self::row(self::UNKNOWN, $code, $detail);

            default:
                // draft / awaiting_approval / approved / queued / dispatching.
                return self::row(self::QUEUED, null, null);
        }
    }

    /** The wire state for a consent / suppression refusal that left no row. */
    public static function forConsentRefusal(): string
    {
        return self::SUPPRESSED;
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /** @return array{delivery_state:string, reason_code:?string, reason:?string} */
    private static function row(string $state, ?string $code, ?string $reason): array
    {
        return ['delivery_state' => $state, 'reason_code' => $code, 'reason' => $reason];
    }
}
