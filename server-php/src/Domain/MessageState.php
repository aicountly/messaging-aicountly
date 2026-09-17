<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

/**
 * The message lifecycle, and the rule that keeps it honest.
 *
 * ## Provider acceptance is not delivery
 *
 * These are separate states because they are separate facts. `provider_accepted`
 * means a provider took responsibility for the message. `delivered` means the
 * provider told us it arrived. A product that treats the first as the second
 * reports 100% delivery during a carrier outage, and the business only finds
 * out when customers say they never heard anything.
 *
 * ## Missing confirmation is not success
 *
 * There is no state for "accepted a while ago and probably fine". A message
 * that was accepted and has no terminal receipt stays `provider_accepted`, and
 * the Command Centre counts it as unconfirmed rather than folding it into
 * either column. That is why the delivery-rate denominator on that screen is
 * explicit about what it does not know.
 *
 * ## Status only moves forward
 *
 * `rank()` exists so that out-of-order receipts cannot walk a message
 * backwards. Providers do deliver `read` before `delivered`; taking the latest
 * event would flip the message back and the delivery report would be wrong in a
 * way that looks like a provider bug. `advance()` is the only way a webhook
 * changes a status, and it refuses to go down.
 */
final class MessageState
{
    public const DRAFT              = 'draft';
    public const AWAITING_APPROVAL  = 'awaiting_approval';
    public const APPROVED           = 'approved';
    public const QUEUED             = 'queued';
    public const DISPATCHING        = 'dispatching';
    public const PROVIDER_ACCEPTED  = 'provider_accepted';
    public const DELIVERED          = 'delivered';
    public const READ               = 'read';
    public const FAILED             = 'failed';
    public const CANCELLED          = 'cancelled';
    public const SUBMISSION_UNKNOWN = 'submission_unknown';

    /**
     * Progress ordering for the states a PROVIDER can report.
     *
     * Pre-dispatch states are absent on purpose: an approval is not further
     * along than a delivery, they are different axes, and putting them on one
     * ladder invites a comparison that means nothing.
     */
    private const RANK = [
        self::QUEUED             => 10,
        self::DISPATCHING        => 15,
        self::PROVIDER_ACCEPTED  => 20,
        self::DELIVERED          => 30,
        self::READ               => 40,
    ];

    /** States from which nothing further will happen without human action. */
    private const TERMINAL = [self::DELIVERED, self::READ, self::FAILED, self::CANCELLED];

    public static function rank(string $state): int
    {
        return self::RANK[$state] ?? 0;
    }

    /**
     * The status a message should hold, given what it holds now and what a
     * provider event implies.
     *
     * Returns null when nothing should change, which the caller treats as
     * "record the event, leave the message alone".
     */
    public static function advance(string $current, string $incoming): ?string
    {
        if ($current === $incoming) {
            return null;
        }

        // A failure is a real outcome and is allowed to land on an accepted
        // message: a provider can accept and then fail to deliver. It is NOT
        // allowed to overwrite a delivery that already happened — a late
        // failure notice for a message the customer received would be worse
        // than no notice at all.
        if ($incoming === self::FAILED) {
            return in_array($current, [self::DELIVERED, self::READ, self::CANCELLED], true) ? null : self::FAILED;
        }

        // Nothing a provider says moves a cancelled or failed-and-settled
        // message except an explicit reconciliation, which goes through
        // DispatchService rather than here.
        if (in_array($current, [self::CANCELLED], true)) {
            return null;
        }

        // `submission_unknown` is resolved by a later receipt, which is the
        // best possible outcome for it: the provider did have the message.
        if ($current === self::SUBMISSION_UNKNOWN && self::rank($incoming) > 0) {
            return $incoming;
        }

        return self::rank($incoming) > self::rank($current) ? $incoming : null;
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /** Whether this state means the message is out of our hands. */
    public static function isDispatched(string $state): bool
    {
        return in_array($state, [
            self::PROVIDER_ACCEPTED, self::DELIVERED, self::READ, self::SUBMISSION_UNKNOWN,
        ], true);
    }

    /** Whether the content may still be edited. */
    public static function isEditable(string $state): bool
    {
        return in_array($state, [self::DRAFT, self::AWAITING_APPROVAL], true);
    }

    /**
     * Words for a screen, because status must not be conveyed by colour alone.
     */
    public static function describe(string $state): string
    {
        return match ($state) {
            self::DRAFT              => 'Draft',
            self::AWAITING_APPROVAL  => 'Awaiting approval',
            self::APPROVED           => 'Approved, not sent',
            self::QUEUED             => 'Queued',
            self::DISPATCHING        => 'Sending',
            self::PROVIDER_ACCEPTED  => 'Accepted by provider, delivery not confirmed',
            self::DELIVERED          => 'Delivered',
            self::READ               => 'Read',
            self::FAILED             => 'Failed',
            self::CANCELLED          => 'Cancelled',
            self::SUBMISSION_UNKNOWN => 'Submission unknown — needs investigation',
            default                  => $state,
        };
    }

    /**
     * The hash an approval binds to.
     *
     * Everything a recipient would see goes in. Change any of it and the
     * approval no longer matches — which is the mechanism behind "editing
     * approved content invalidates its approval", and the reason it cannot be
     * forgotten at a call site.
     *
     * @param array<string, mixed> $variables
     * @param list<string>         $attachmentRefs
     */
    public static function contentHash(
        string $body,
        string $contentType,
        string $language,
        ?string $templateUuid,
        ?int $templateVersion,
        array $variables,
        array $attachmentRefs = [],
    ): string {
        ksort($variables);
        sort($attachmentRefs);

        return hash('sha256', json_encode([
            'body'        => $body,
            'type'        => $contentType,
            'language'    => $language,
            'template'    => $templateUuid,
            'version'     => $templateVersion,
            'variables'   => $variables,
            'attachments' => $attachmentRefs,
        ], JSON_UNESCAPED_UNICODE) ?: '');
    }
}
