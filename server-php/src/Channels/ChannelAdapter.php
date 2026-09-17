<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

/**
 * One provider, behind one interface.
 *
 * Every adapter is written against its provider's own published contract and
 * nothing else. There is no shared "messaging API" abstraction pretending that
 * WhatsApp, RCS and SMS are the same thing, because they are not: one requires
 * pre-approved templates to start a conversation, one depends on the recipient's
 * handset, and one cannot receive a reply at all. What they share is this
 * interface — send, verify a webhook, normalise an event, declare capabilities —
 * and the differences are declared rather than hidden.
 *
 * ## No provider is preferred
 *
 * Which provider a deployment uses is configuration. The adapters that ship
 * here are the ones whose contracts are verifiable, and a deployment that wants
 * a different SMS company adds an adapter and sets `provider` on the
 * connection. Nothing in this product favours one, and nothing was chosen
 * because it appeared in a design mockup.
 *
 * ## Credentials
 *
 * An adapter is handed a ChannelConnection and resolves its credential from the
 * server environment at the moment of the call. It never receives one from a
 * request, never returns one, never logs one, and never writes one down.
 */
interface ChannelAdapter
{
    /** The provider key stored on a connection: 'whatsapp_cloud', 'twilio_sms', … */
    public function provider(): string;

    /** Which channel this adapter serves: whatsapp | rcs | sms | ott. */
    public function channel(): string;

    /** A name for a screen. */
    public function displayName(): string;

    /**
     * What this provider can do, as the adapter's declared contract.
     *
     * Refined per connection where the provider reports more — a sender id that
     * cannot receive replies, a route that forbids links.
     *
     * @return array<string, bool> capability => supported
     */
    public function declaredCapabilities(): array;

    /**
     * Whether this connection has everything it needs to send.
     *
     * Configuration only. It does not call the provider: a `configured()` that
     * made an HTTP request would be called on every screen render.
     */
    public function isConfigured(ChannelConnection $connection): bool;

    /**
     * What is missing, named so an administrator can fix it.
     *
     * Names environment keys and provider steps. NEVER a value.
     */
    public function configurationGap(ChannelConnection $connection): ?string;

    /**
     * Hand one message to the provider.
     *
     * MUST be idempotent with respect to `$idempotencyKey` wherever the provider
     * supports it, and MUST return SendResult::unknown() rather than throwing
     * when the outcome is genuinely ambiguous.
     *
     * @param OutboundMessage $message the approved content, already validated
     */
    public function send(ChannelConnection $connection, OutboundMessage $message, string $idempotencyKey): SendResult;

    /**
     * Ask the provider what happened to a message we are unsure about.
     *
     * Returns null when the provider cannot say, which is the honest answer and
     * the one that sends a `submission_unknown` message to a human rather than
     * resending it.
     */
    public function lookupStatus(ChannelConnection $connection, string $providerMessageId): ?SendResult;

    /**
     * Verify that a webhook really came from this provider.
     *
     * The tenant is NEVER taken from the payload. This method proves the
     * request's origin; the caller resolves the connection — and therefore the
     * company — from server-side configuration. A payload that names a company
     * is a payload claiming one.
     *
     * @param array<string, string> $headers
     */
    public function verifyWebhook(ChannelConnection $connection, string $rawBody, array $headers): bool;

    /**
     * The provider's challenge response for webhook registration, if it has one.
     *
     * @param array<string, string> $query
     */
    public function webhookChallenge(ChannelConnection $connection, array $query): ?string;

    /**
     * Turn one provider payload into zero or more normalised events.
     *
     * A single WhatsApp webhook body can carry several statuses and several
     * inbound messages, so this returns a list. Each event carries the
     * provider's own event id, which is what makes replay safe.
     *
     * @param array<string, mixed> $payload
     * @return list<NormalisedEvent>
     */
    public function normaliseWebhook(array $payload): array;
}
