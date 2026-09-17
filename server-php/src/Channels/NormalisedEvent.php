<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

/**
 * One provider webhook event, in this product's own vocabulary.
 *
 * `stateRank` is what makes out-of-order delivery safe. Providers deliver
 * receipts out of order and more than once — a `read` can arrive before the
 * `delivered` that logically precedes it. Taking the most recent event would
 * walk a message backwards from read to delivered, and a delivery report built
 * on that is wrong in a way that looks like a provider bug.
 *
 * So each event carries the rank of the state it implies, and the message's
 * status only ever moves FORWARD to the furthest rank seen. A late `delivered`
 * after a `read` is recorded in the event log and changes nothing.
 */
final class NormalisedEvent
{
    public const RANK = [
        'queued'            => 10,
        'provider_accepted' => 20,
        'delivered'         => 30,
        'read'              => 40,
        // Terminal and outside the ladder: a failure after acceptance is a real
        // outcome, and it must not be overtaken by a stale 'accepted'.
        'failed'            => 50,
    ];

    /**
     * @param array<string, mixed> $raw the event as it arrived, already redacted
     */
    public function __construct(
        public readonly string $providerEventId,
        /** inbound_message | status | optout | error */
        public readonly string $kind,
        public readonly ?string $providerMessageId = null,
        /** For a status event: the lifecycle state it implies. */
        public readonly ?string $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorDetail = null,
        /** For an inbound message. */
        public readonly string $fromAddress = '',
        public readonly string $toAddress = '',
        public readonly string $body = '',
        public readonly string $profileName = '',
        public readonly array $media = [],
        public readonly ?string $occurredAt = null,
        public readonly ?int $costMinor = null,
        public readonly ?string $costCurrency = null,
        public readonly array $raw = [],
    ) {
    }

    public function stateRank(): int
    {
        return self::RANK[$this->status ?? ''] ?? 0;
    }
}
