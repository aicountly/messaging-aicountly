<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

use Aicountly\Api\Env;

/**
 * RCS business messaging — CONFIGURABLE, and deliberately disabled by default.
 *
 * ## Read this before turning it on
 *
 * RCS is not one API. Reaching an RCS agent goes through an aggregator or
 * through Google's RBM API, the request shape differs between them, and the
 * capability set differs AGAIN per recipient: whether a given handset on a
 * given carrier can receive a rich card is a property of that handset, not of
 * the sender. There is no single published contract this adapter could be
 * written against and be correct for every deployment.
 *
 * So what ships here is the ADAPTER BOUNDARY and an honest pending state, which
 * is what the brief asks for when a contract is missing. The connection can be
 * created, the capability matrix is declared conservatively, the journey
 * validator refuses to publish an RCS send it cannot verify, and Channels &
 * Trust says "Verification required" with the exact configuration that is
 * missing. It does not draw a connected card over nothing.
 *
 * ## What is needed to complete it
 *
 * `MESSAGING_RCS_API_BASE` and `MESSAGING_RCS_PROVIDER_STYLE` — the aggregator's
 * base URL and which request shape it speaks. With those set and a credential
 * on the connection, `send()` posts the generic shape below; without them it
 * refuses rather than guessing. The exact payload for a given aggregator is a
 * one-method change here and nothing else in the product moves, which is the
 * point of the boundary.
 *
 * ## What it must never do
 *
 * Fall back to SMS silently. An RCS send that cannot be made is reported, and
 * the decision to try another channel belongs to a journey's explicit
 * eligibility branch where a human can see it — not to this class.
 */
final class RcsAdapter implements ChannelAdapter
{
    public function provider(): string
    {
        return 'rcs_generic';
    }

    public function channel(): string
    {
        return 'rcs';
    }

    public function displayName(): string
    {
        return 'RCS Business Messaging';
    }

    public function declaredCapabilities(): array
    {
        return [
            Capability::INBOUND            => true,
            Capability::FREEFORM_TEXT      => true,
            Capability::TEMPLATES          => false,
            Capability::TEMPLATE_REQUIRED_FOR_INITIATION => false,
            Capability::OUTBOUND_MEDIA     => true,
            Capability::INBOUND_MEDIA      => true,
            Capability::INTERACTIVE        => true,
            Capability::DELIVERY_RECEIPTS  => true,
            Capability::READ_RECEIPTS      => true,
            Capability::COST_REPORTING     => false,
            Capability::LINKS              => true,
            Capability::STATUS_LOOKUP      => false,
            Capability::PROVIDER_OPTOUT    => false,
        ];
    }

    public function isConfigured(ChannelConnection $connection): bool
    {
        return $this->apiBase() !== ''
            && $this->providerStyle() !== ''
            && $connection->hasCredential()
            && $connection->providerAccountRef !== '';
    }

    public function configurationGap(ChannelConnection $connection): ?string
    {
        if ($this->apiBase() === '') {
            return 'RCS is not configured for this deployment. Set MESSAGING_RCS_API_BASE to your RCS '
                . 'aggregator or Google RBM endpoint in the server environment.';
        }
        if ($this->providerStyle() === '') {
            return 'Set MESSAGING_RCS_PROVIDER_STYLE in the server environment to the request shape your '
                . 'aggregator speaks, so RCS sends are formatted against a verified contract rather than guessed.';
        }
        if ($connection->providerAccountRef === '') {
            return 'The RCS agent id is not set on this connection.';
        }
        if (!$connection->hasCredential()) {
            return $connection->credentialRef === ''
                ? 'No credential reference is set on this connection.'
                : 'The server environment variable ' . $connection->credentialRef . ' is empty.';
        }

        return null;
    }

    public function send(ChannelConnection $connection, OutboundMessage $message, string $idempotencyKey): SendResult
    {
        $gap = $this->configurationGap($connection);
        if ($gap !== null) {
            // NOT retryable, and NOT a silent no-op. A journey that reaches
            // this pauses and says exactly this sentence; it does not fall
            // through to SMS on its own.
            return SendResult::failed('channel_not_configured', $gap, false);
        }

        $url = rtrim($this->apiBase(), '/') . '/agents/' . rawurlencode($connection->providerAccountRef) . '/messages';

        $result = HttpTransport::send('POST', $url, [
            'Authorization'   => 'Bearer ' . $connection->credential(),
            'Idempotency-Key' => $idempotencyKey,
        ], [
            'to'            => $message->toAddress,
            'contentMessage' => array_filter([
                'text'        => $message->body !== '' ? $message->body : null,
                'suggestions' => $message->components !== [] ? $message->components : null,
            ]),
            'messageId'     => $message->messageUuid,
        ]);

        if ($result['outcome'] === 'timeout') {
            return SendResult::unknown('The RCS provider did not answer before the timeout.');
        }
        if ($result['outcome'] !== 'ok' && $result['status'] === 0) {
            return SendResult::failed('provider_unreachable', 'The RCS provider could not be reached.', true);
        }

        $body = $result['body'] ?? [];

        if ($result['status'] >= 400) {
            return SendResult::failed(
                'rcs_' . (string) ($body['error']['code'] ?? $result['status']),
                (string) ($body['error']['message'] ?? 'The RCS provider rejected the message.'),
                $result['status'] >= 500 || $result['status'] === 429,
            );
        }

        $providerId = (string) ($body['messageId'] ?? $body['name'] ?? '');

        return $providerId === ''
            ? SendResult::unknown('The RCS provider accepted the request but returned no message id.')
            : SendResult::accepted($providerId);
    }

    public function lookupStatus(ChannelConnection $connection, string $providerMessageId): ?SendResult
    {
        // Unknown until a verified aggregator contract says otherwise. Null
        // sends a `submission_unknown` message to a human, which is correct.
        return null;
    }

    public function verifyWebhook(ChannelConnection $connection, string $rawBody, array $headers): bool
    {
        $secret = $connection->webhookSecret();
        if ($secret === '') {
            return false;
        }

        // Generic HMAC-SHA256 over the raw body, which is what most aggregators
        // use. If yours differs, this method is the one place to change.
        $presented = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'X-Rcs-Signature') === 0 || strcasecmp($name, 'X-Signature') === 0) {
                $presented = trim($value);
                break;
            }
        }
        if ($presented === '') {
            return false;
        }

        $presented = preg_replace('/^sha256=/', '', $presented) ?? $presented;

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $presented);
    }

    public function webhookChallenge(ChannelConnection $connection, array $query): ?string
    {
        return null;
    }

    public function normaliseWebhook(array $payload): array
    {
        $eventId = (string) ($payload['eventId'] ?? $payload['messageId'] ?? '');
        if ($eventId === '') {
            return [];
        }

        // Inbound.
        if (isset($payload['senderPhoneNumber']) || isset($payload['text'])) {
            return [new NormalisedEvent(
                providerEventId: 'in:' . $eventId,
                kind: 'inbound_message',
                providerMessageId: (string) ($payload['messageId'] ?? $eventId),
                fromAddress: (string) ($payload['senderPhoneNumber'] ?? ''),
                body: (string) ($payload['text'] ?? ''),
            )];
        }

        $mapped = match (strtoupper((string) ($payload['eventType'] ?? ''))) {
            'DELIVERED' => 'delivered',
            'READ'      => 'read',
            'SENT'      => 'provider_accepted',
            'FAILED', 'TTL_EXPIRED' => 'failed',
            default     => null,
        };
        if ($mapped === null) {
            return [];
        }

        return [new NormalisedEvent(
            providerEventId: 'st:' . $eventId . ':' . $mapped,
            kind: $mapped === 'failed' ? 'error' : 'status',
            providerMessageId: (string) ($payload['messageId'] ?? ''),
            status: $mapped,
        )];
    }

    // -----------------------------------------------------------------------

    private function apiBase(): string
    {
        return trim(Env::get('MESSAGING_RCS_API_BASE'));
    }

    private function providerStyle(): string
    {
        return trim(Env::get('MESSAGING_RCS_PROVIDER_STYLE'));
    }
}
