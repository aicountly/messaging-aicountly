<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests;

use Aicountly\Api\Channels\Capability;
use Aicountly\Api\Channels\ChannelAdapter;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\NormalisedEvent;
use Aicountly\Api\Channels\OutboundMessage;
use Aicountly\Api\Channels\SendResult;

/**
 * A provider that is not a provider.
 *
 * ## Why this exists rather than a stubbed graph.facebook.com
 *
 * The real adapters build their URLs from constants — `https://graph.facebook.com`
 * and `https://api.twilio.com` are compiled in, deliberately, so that no
 * environment variable can point a send at a host somebody else controls.
 * Making those overridable to please a test would add exactly the
 * misconfiguration surface the product is designed not to have.
 *
 * So the dispatch pipeline is tested end to end against this, registered
 * through `ChannelRegistry::overrideForTesting()`, while the real adapters'
 * decision-making — signature verification, capability declaration,
 * configuration gaps, webhook normalisation — is tested directly as the pure
 * functions it is. What is NOT tested here is whether Meta's live endpoint
 * accepts a payload; that is not knowable from a test suite and is not claimed.
 *
 * ## What it records
 *
 * Every send, in order, with the idempotency key. That is what lets a test
 * assert the thing that matters most in this product: that a retry after an
 * ambiguous result did not put a second message in front of a customer.
 */
final class RecordingAdapter implements ChannelAdapter
{
    /** @var list<array{to:string, body:string, key:string, content_type:string}> */
    public array $sent = [];

    /** @var list<string> outcomes to return, consumed in order; 'accepted' when exhausted. */
    public array $script = [];

    /** Status this adapter reports when asked about an unknown submission. */
    public ?SendResult $lookupAnswer = null;

    public int $lookupCalls = 0;

    public function __construct(
        private readonly string $provider = 'test_provider',
        private readonly string $channel = 'whatsapp',
        /** @var array<string, bool> */
        private readonly array $capabilities = [],
        private readonly ?string $gap = null,
    ) {
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function displayName(): string
    {
        return 'Test provider';
    }

    public function declaredCapabilities(): array
    {
        return $this->capabilities !== [] ? $this->capabilities : [
            Capability::INBOUND            => true,
            Capability::FREEFORM_TEXT      => true,
            Capability::TEMPLATES          => true,
            Capability::OUTBOUND_MEDIA     => true,
            Capability::INBOUND_MEDIA      => true,
            Capability::DELIVERY_RECEIPTS  => true,
            Capability::READ_RECEIPTS      => true,
            Capability::LINKS              => true,
            Capability::STATUS_LOOKUP      => true,
        ];
    }

    public function isConfigured(ChannelConnection $connection): bool
    {
        return $this->gap === null;
    }

    public function configurationGap(ChannelConnection $connection): ?string
    {
        return $this->gap;
    }

    public function send(ChannelConnection $connection, OutboundMessage $message, string $idempotencyKey): SendResult
    {
        $this->sent[] = [
            'to'           => $message->toAddress,
            'body'         => $message->body,
            'key'          => $idempotencyKey,
            'content_type' => $message->contentType,
        ];

        $outcome = array_shift($this->script) ?? 'accepted';

        return match ($outcome) {
            'unknown'  => SendResult::unknown('The provider did not answer in time.'),
            'failed'   => SendResult::failed('provider_rejected', 'The provider rejected it.', false),
            'retry'    => SendResult::failed('provider_throttled', 'Rate limited.', true),
            default    => SendResult::accepted('prov-' . count($this->sent)),
        };
    }

    public function lookupStatus(ChannelConnection $connection, string $providerMessageId): ?SendResult
    {
        $this->lookupCalls++;

        return $this->lookupAnswer;
    }

    public function verifyWebhook(ChannelConnection $connection, string $rawBody, array $headers): bool
    {
        return ($headers['x-test-signature'] ?? '') === 'valid';
    }

    public function webhookChallenge(ChannelConnection $connection, array $query): ?string
    {
        return null;
    }

    public function normaliseWebhook(array $payload): array
    {
        $events = [];
        foreach ($payload['events'] ?? [] as $index => $event) {
            $events[] = new NormalisedEvent(
                providerEventId: (string) ($event['id'] ?? 'evt-' . $index),
                kind: (string) ($event['kind'] ?? 'status'),
                providerMessageId: isset($event['provider_message_id']) ? (string) $event['provider_message_id'] : null,
                status: isset($event['status']) ? (string) $event['status'] : null,
                errorCode: isset($event['error_code']) ? (string) $event['error_code'] : null,
                errorDetail: isset($event['error_detail']) ? (string) $event['error_detail'] : null,
                fromAddress: (string) ($event['from'] ?? ''),
                toAddress: (string) ($event['to'] ?? ''),
                body: (string) ($event['body'] ?? ''),
                profileName: (string) ($event['profile_name'] ?? ''),
                occurredAt: isset($event['occurred_at']) ? (string) $event['occurred_at'] : null,
                raw: $event,
            );
        }

        return $events;
    }
}
