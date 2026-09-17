<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

/**
 * SMS, through Twilio's Programmable Messaging API.
 *
 * ## Why this provider and not another
 *
 * Because its contract is public and stable enough to implement against, not
 * because it is preferred. `provider` on a channel connection is a
 * configuration value; a deployment that uses a different SMS company adds an
 * adapter next to this one and sets that value. Nothing else in the product
 * changes, and nothing in the product assumes SMS means Twilio.
 *
 * ## The capability that matters
 *
 * `Capability::INBOUND` is FALSE for an alphanumeric sender id, because an
 * alphanumeric sender cannot receive anything. That is not a detail: it is the
 * difference between an inbox that shows a reply box and an inbox that shows
 * "this sender cannot receive replies — SMS is outbound only here". The
 * capability is resolved per connection from the shape of the sender address,
 * and the composer is disabled off the back of it.
 *
 * ## What is not encoded here
 *
 * No DLT template rules, no sender-registration policy, no per-route link
 * restrictions. Those are jurisdiction and carrier rules that change; where a
 * route forbids links the provider rejects the message and the rejection is
 * reported faithfully. A hard-coded rule written from memory would be wrong
 * somewhere and silently block legitimate sends.
 */
final class TwilioSmsAdapter implements ChannelAdapter
{
    private const API_BASE = 'https://api.twilio.com/2010-04-01';

    public function provider(): string
    {
        return 'twilio_sms';
    }

    public function channel(): string
    {
        return 'sms';
    }

    public function displayName(): string
    {
        return 'SMS (Twilio Programmable Messaging)';
    }

    public function declaredCapabilities(): array
    {
        return [
            // Refined per connection by capabilitiesFor(): an alphanumeric
            // sender overrides this to false.
            Capability::INBOUND            => true,
            Capability::FREEFORM_TEXT      => true,
            Capability::TEMPLATES          => false,
            Capability::TEMPLATE_REQUIRED_FOR_INITIATION => false,
            Capability::OUTBOUND_MEDIA     => true,
            Capability::INBOUND_MEDIA      => true,
            Capability::INTERACTIVE        => false,
            Capability::DELIVERY_RECEIPTS  => true,
            Capability::READ_RECEIPTS      => false,
            Capability::COST_REPORTING     => true,
            Capability::LINKS              => true,
            Capability::STATUS_LOOKUP      => true,
            Capability::PROVIDER_OPTOUT    => true,
        ];
    }

    /**
     * Capabilities for one connection, which is where the sender shape is known.
     *
     * @return array<string, bool>
     */
    public function capabilitiesFor(ChannelConnection $connection): array
    {
        $capabilities = $this->declaredCapabilities();

        if ($this->isAlphanumericSender($connection->senderAddress)) {
            // It physically cannot receive. Everything that follows from that
            // is set here, once, rather than remembered at each call site.
            $capabilities[Capability::INBOUND] = false;
            $capabilities[Capability::INBOUND_MEDIA] = false;
            $capabilities[Capability::PROVIDER_OPTOUT] = false;
        }

        return $capabilities;
    }

    public function isConfigured(ChannelConnection $connection): bool
    {
        return $connection->hasCredential()
            && $connection->providerAccountRef !== ''
            && $connection->senderAddress !== '';
    }

    public function configurationGap(ChannelConnection $connection): ?string
    {
        if ($connection->providerAccountRef === '') {
            return 'The Twilio Account SID is not set on this connection.';
        }
        if (!$connection->hasCredential()) {
            return $connection->credentialRef === ''
                ? 'No credential reference is set on this connection. Point credential_ref at the server environment variable holding the Twilio auth token.'
                : 'The server environment variable ' . $connection->credentialRef . ' is empty.';
        }
        if ($connection->senderAddress === '') {
            return 'The sender number or alphanumeric sender id is not set on this connection.';
        }

        return null;
    }

    public function send(ChannelConnection $connection, OutboundMessage $message, string $idempotencyKey): SendResult
    {
        $gap = $this->configurationGap($connection);
        if ($gap !== null) {
            return SendResult::failed('channel_not_configured', $gap, false);
        }

        $url = sprintf(
            '%s/Accounts/%s/Messages.json',
            self::API_BASE,
            rawurlencode($connection->providerAccountRef),
        );

        $form = [
            'To'   => $message->toAddress,
            'From' => $connection->senderAddress,
            'Body' => $message->body,
        ];
        foreach ($message->attachments as $index => $attachment) {
            if (isset($attachment['url']) && $attachment['url'] !== '') {
                $form['MediaUrl' . $index] = $attachment['url'];
            }
        }

        $result = HttpTransport::send('POST', $url, [
            // Basic auth is this provider's scheme. The token is read from the
            // environment at this moment and is not held anywhere.
            'Authorization'   => 'Basic ' . base64_encode($connection->providerAccountRef . ':' . $connection->credential()),
            'Content-Type'    => 'application/x-www-form-urlencoded',
            // The provider honours this, so a retry after a timeout replays
            // rather than sending a second SMS.
            'Idempotency-Key' => $idempotencyKey,
        ], http_build_query($form));

        if ($result['outcome'] === 'timeout') {
            return SendResult::unknown(
                'The SMS provider did not answer before the timeout. The message may or may not have been accepted.',
            );
        }
        if ($result['outcome'] !== 'ok' && $result['status'] === 0) {
            return SendResult::failed('provider_unreachable', 'The SMS provider could not be reached.', true);
        }

        $body = $result['body'] ?? [];

        if ($result['status'] >= 400) {
            $code = (string) ($body['code'] ?? $result['status']);
            $detail = (string) ($body['message'] ?? 'The SMS provider rejected the message.');
            $retryable = $result['status'] >= 500 || $result['status'] === 429;

            return SendResult::failed('sms_' . $code, $detail, $retryable);
        }

        $sid = (string) ($body['sid'] ?? '');
        if ($sid === '') {
            return SendResult::unknown('The SMS provider accepted the request but returned no message id.');
        }

        return SendResult::accepted($sid, $this->priceMinor($body), $this->currency($body));
    }

    public function lookupStatus(ChannelConnection $connection, string $providerMessageId): ?SendResult
    {
        if (!$this->isConfigured($connection)) {
            return null;
        }

        // THE RECONCILIATION PATH. A `submission_unknown` message is resolved
        // by asking, not by resending.
        $url = sprintf(
            '%s/Accounts/%s/Messages/%s.json',
            self::API_BASE,
            rawurlencode($connection->providerAccountRef),
            rawurlencode($providerMessageId),
        );

        $result = HttpTransport::send('GET', $url, [
            'Authorization' => 'Basic ' . base64_encode($connection->providerAccountRef . ':' . $connection->credential()),
        ]);

        if ($result['outcome'] !== 'ok') {
            return null;
        }

        $body = $result['body'] ?? [];
        $status = strtolower((string) ($body['status'] ?? ''));

        return match ($status) {
            'queued', 'sending', 'sent', 'accepted' => SendResult::accepted(
                $providerMessageId,
                $this->priceMinor($body),
                $this->currency($body),
            ),
            'delivered' => SendResult::accepted($providerMessageId, $this->priceMinor($body), $this->currency($body)),
            'failed', 'undelivered' => SendResult::failed(
                'sms_' . (string) ($body['error_code'] ?? 'failed'),
                (string) ($body['error_message'] ?? 'The provider reported this message as undelivered.'),
                false,
            ),
            default => null,
        };
    }

    public function verifyWebhook(ChannelConnection $connection, string $rawBody, array $headers): bool
    {
        $secret = $connection->webhookSecret();
        if ($secret === '') {
            return false;
        }

        $presented = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'X-Twilio-Signature') === 0) {
                $presented = trim($value);
                break;
            }
        }
        if ($presented === '') {
            return false;
        }

        // This provider signs the full request URL followed by the sorted POST
        // parameters. The URL is reconstructed from OUR configured webhook base
        // rather than from the request's own Host header — a signature checked
        // against an attacker-supplied host is a signature checked against
        // whatever they want it to be.
        $url = rtrim(\Aicountly\Api\Env::get('MESSAGING_WEBHOOK_BASE_URL'), '/')
            . '/api/webhooks/' . $connection->provider . '/' . $connection->connectionUuid;

        parse_str($rawBody, $params);
        ksort($params);
        $concatenated = $url;
        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $concatenated .= $key . (string) $value;
            }
        }

        $expected = base64_encode(hash_hmac('sha1', $concatenated, $secret, true));

        return hash_equals($expected, $presented);
    }

    public function webhookChallenge(ChannelConnection $connection, array $query): ?string
    {
        // This provider has no subscription handshake.
        return null;
    }

    public function normaliseWebhook(array $payload): array
    {
        // Status callback and inbound message arrive on the same endpoint and
        // are told apart by which fields are present.
        $messageSid = (string) ($payload['MessageSid'] ?? $payload['SmsSid'] ?? '');
        $status = strtolower((string) ($payload['MessageStatus'] ?? $payload['SmsStatus'] ?? ''));
        $body = (string) ($payload['Body'] ?? '');
        $from = (string) ($payload['From'] ?? '');

        if ($status === '' && $from !== '') {
            return [new NormalisedEvent(
                providerEventId: 'in:' . $messageSid,
                kind: 'inbound_message',
                providerMessageId: $messageSid,
                fromAddress: $from,
                toAddress: (string) ($payload['To'] ?? ''),
                body: $body,
                media: $this->inboundMedia($payload),
            )];
        }

        $mapped = match ($status) {
            'queued', 'accepted'       => 'queued',
            'sending', 'sent'          => 'provider_accepted',
            'delivered'                => 'delivered',
            'failed', 'undelivered'    => 'failed',
            default                    => null,
        };
        if ($mapped === null || $messageSid === '') {
            return [];
        }

        // The provider handles STOP itself and reports it. Honouring that is
        // not optional: a customer who replied STOP has withdrawn consent
        // whether or not the message reached this product's own inbox.
        $optOut = strtoupper(trim($body));
        if (in_array($optOut, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
            return [new NormalisedEvent(
                providerEventId: 'optout:' . $messageSid,
                kind: 'optout',
                providerMessageId: $messageSid,
                fromAddress: $from,
                body: $optOut,
            )];
        }

        return [new NormalisedEvent(
            providerEventId: 'st:' . $messageSid . ':' . $status,
            kind: $mapped === 'failed' ? 'error' : 'status',
            providerMessageId: $messageSid,
            status: $mapped,
            errorCode: isset($payload['ErrorCode']) ? 'sms_' . $payload['ErrorCode'] : null,
            errorDetail: isset($payload['ErrorMessage']) ? (string) $payload['ErrorMessage'] : null,
        )];
    }

    // -----------------------------------------------------------------------

    /**
     * An alphanumeric sender id, which cannot receive replies.
     *
     * A numeric sender starts with + or is all digits; anything with a letter
     * in it is an alphanumeric id.
     */
    public function isAlphanumericSender(string $sender): bool
    {
        $sender = trim($sender);
        if ($sender === '') {
            return false;
        }

        return preg_match('/^\+?[0-9 ()-]+$/', $sender) !== 1;
    }

    /** @param array<string, mixed> $body */
    private function priceMinor(array $body): ?int
    {
        // The provider reports price as a negative decimal string, and only
        // once the message has been priced. Absent is absent, not zero: a zero
        // cost summed into a spend figure understates the bill.
        $price = $body['price'] ?? null;
        if ($price === null || $price === '') {
            return null;
        }

        return (int) round(abs((float) $price) * 100);
    }

    /** @param array<string, mixed> $body */
    private function currency(array $body): ?string
    {
        $currency = $body['price_unit'] ?? null;

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, string>>
     */
    private function inboundMedia(array $payload): array
    {
        $count = (int) ($payload['NumMedia'] ?? 0);
        $media = [];
        for ($i = 0; $i < $count; $i++) {
            $url = (string) ($payload['MediaUrl' . $i] ?? '');
            if ($url === '') {
                continue;
            }
            $media[] = [
                // The provider's own media URL. Recorded as a reference and
                // fetched only through the adapter's authenticated path — never
                // handed to a browser, and never fetched as an arbitrary URL.
                'provider_media_id' => $url,
                'declared_type'     => (string) ($payload['MediaContentType' . $i] ?? 'application/octet-stream'),
                'filename'          => '',
            ];
        }

        return $media;
    }
}
