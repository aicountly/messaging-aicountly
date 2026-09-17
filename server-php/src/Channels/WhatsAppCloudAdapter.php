<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

use Aicountly\Api\Env;

/**
 * WhatsApp Business, through the WhatsApp Cloud API.
 *
 * ## What is implemented from the provider's contract, and what is not
 *
 * Implemented: the graph message send, the template send shape, webhook
 * signature verification (`X-Hub-Signature-256`, HMAC-SHA256 over the raw
 * body), the `hub.challenge` subscription handshake, and normalisation of the
 * `messages` and `statuses` arrays a change payload carries.
 *
 * NOT implemented, on purpose: any encoding of WhatsApp's commercial policy.
 * This adapter does not hard-code the length of the customer-service window,
 * the list of template categories, per-category pricing, or the quality-rating
 * thresholds. Those are Meta's current rules, they change, and a copy of them
 * written from memory into a PHP constant is a copy that will be wrong and will
 * be trusted. What the adapter does instead is ask: a template is sendable when
 * the PROVIDER says its status is approved (recorded on the template version
 * with the time it was read), and a free-form message is attempted and its
 * refusal reported faithfully if the provider declines it.
 *
 * `MESSAGING_WHATSAPP_GRAPH_VERSION` is configuration for the same reason.
 *
 * ## Credentials
 *
 * The access token is resolved from the connection's `credential_ref` at the
 * moment of the call. It travels in an Authorization header, never in a query
 * string — a query string reaches access logs and every proxy in between.
 */
final class WhatsAppCloudAdapter implements ChannelAdapter
{
    private const DEFAULT_GRAPH_VERSION = 'v21.0';
    private const GRAPH_BASE = 'https://graph.facebook.com';

    public function provider(): string
    {
        return 'whatsapp_cloud';
    }

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function displayName(): string
    {
        return 'WhatsApp Business (Cloud API)';
    }

    public function declaredCapabilities(): array
    {
        return [
            Capability::INBOUND            => true,
            Capability::FREEFORM_TEXT      => true,
            Capability::TEMPLATES          => true,
            // The provider requires an approved template to OPEN a conversation.
            // Recorded as a capability so the composer and the journey validator
            // can both respect it without either of them encoding the rule.
            Capability::TEMPLATE_REQUIRED_FOR_INITIATION => true,
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
        return $connection->hasCredential()
            && $connection->providerAccountRef !== ''
            && $connection->senderAddress !== '';
    }

    public function configurationGap(ChannelConnection $connection): ?string
    {
        if (!$connection->hasCredential()) {
            return $connection->credentialRef === ''
                ? 'No credential reference is set on this connection. Point credential_ref at the server environment variable holding the WhatsApp access token.'
                : 'The server environment variable ' . $connection->credentialRef . ' is empty.';
        }
        if ($connection->providerAccountRef === '') {
            return 'The WhatsApp phone number id is not set on this connection.';
        }
        if ($connection->senderAddress === '') {
            return 'The sender phone number is not set on this connection.';
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
            '%s/%s/%s/messages',
            self::GRAPH_BASE,
            rawurlencode($this->graphVersion()),
            rawurlencode($connection->providerAccountRef),
        );

        $payload = $message->isTemplate()
            ? $this->templatePayload($message)
            : $this->textPayload($message);

        $result = HttpTransport::send('POST', $url, [
            'Authorization' => 'Bearer ' . $connection->credential(),
            // The provider does not offer an idempotency header on this
            // endpoint. Duplicate suppression is therefore OURS, and it is
            // enforced before we get here by the unique index on
            // messaging_dispatch_jobs.message_uuid plus the single-claim
            // worker — not by hoping the provider will deduplicate.
            'X-Aicountly-Message' => $message->messageUuid,
        ], $payload);

        if ($result['outcome'] === 'timeout') {
            return SendResult::unknown(
                'The WhatsApp API did not answer before the timeout. The message may or may not have been accepted.',
            );
        }
        if ($result['outcome'] !== 'ok') {
            return SendResult::failed('provider_unreachable', 'The WhatsApp API could not be reached.', true);
        }

        $body = $result['body'] ?? [];
        $providerId = (string) ($body['messages'][0]['id'] ?? '');

        if ($result['status'] >= 400 || $providerId === '') {
            $error = $body['error'] ?? [];
            $code = (string) ($error['code'] ?? $result['status']);
            $detail = (string) ($error['message'] ?? 'The WhatsApp API rejected the message.');

            // 4xx is the provider's decision and retrying it will get the same
            // decision. 5xx and 429 are worth another attempt.
            $retryable = $result['status'] >= 500 || $result['status'] === 429;

            return SendResult::failed('whatsapp_' . $code, $detail, $retryable);
        }

        // Cost is not on the send response. It arrives later on a status
        // webhook for accounts where the provider reports it, and it is
        // recorded then rather than estimated now.
        return SendResult::accepted($providerId);
    }

    public function lookupStatus(ChannelConnection $connection, string $providerMessageId): ?SendResult
    {
        // The Cloud API has no message-status read endpoint: status arrives by
        // webhook only. Returning null is the honest answer, and it is what
        // routes a `submission_unknown` message to a human instead of a
        // silent resend.
        return null;
    }

    public function verifyWebhook(ChannelConnection $connection, string $rawBody, array $headers): bool
    {
        $secret = $connection->webhookSecret();
        if ($secret === '') {
            return false;
        }

        $presented = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'X-Hub-Signature-256') === 0) {
                $presented = trim($value);
                break;
            }
        }
        if ($presented === '' || !str_starts_with($presented, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        // Constant time. A plain === on a signature leaks, over enough
        // requests, the signature.
        return hash_equals($expected, $presented);
    }

    public function webhookChallenge(ChannelConnection $connection, array $query): ?string
    {
        // The subscription handshake: the provider asks with a verify token we
        // configured and expects the challenge echoed back.
        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        if ($mode !== 'subscribe' || $challenge === '') {
            return null;
        }

        $expected = $connection->webhookSecret();

        return ($expected !== '' && hash_equals($expected, $token)) ? $challenge : null;
    }

    public function normaliseWebhook(array $payload): array
    {
        $events = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $displayNumber = (string) ($value['metadata']['display_phone_number'] ?? '');

                // Profile names arrive separately from the messages and are
                // keyed by wa_id.
                $profiles = [];
                foreach ((array) ($value['contacts'] ?? []) as $contact) {
                    $waId = (string) ($contact['wa_id'] ?? '');
                    if ($waId !== '') {
                        $profiles[$waId] = (string) ($contact['profile']['name'] ?? '');
                    }
                }

                foreach ((array) ($value['messages'] ?? []) as $inbound) {
                    $from = (string) ($inbound['from'] ?? '');
                    $type = (string) ($inbound['type'] ?? 'text');

                    $events[] = new NormalisedEvent(
                        providerEventId: 'msg:' . (string) ($inbound['id'] ?? ''),
                        kind: 'inbound_message',
                        providerMessageId: (string) ($inbound['id'] ?? ''),
                        fromAddress: $this->e164($from),
                        toAddress: $this->e164($displayNumber),
                        body: $this->inboundText($inbound, $type),
                        profileName: $profiles[$from] ?? '',
                        media: $this->inboundMedia($inbound, $type),
                        occurredAt: isset($inbound['timestamp'])
                            ? gmdate('c', (int) $inbound['timestamp'])
                            : null,
                        raw: ['type' => $type],
                    );
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $providerStatus = strtolower((string) ($status['status'] ?? ''));
                    $mapped = match ($providerStatus) {
                        'sent'      => 'provider_accepted',
                        'delivered' => 'delivered',
                        'read'      => 'read',
                        'failed'    => 'failed',
                        default     => null,
                    };
                    if ($mapped === null) {
                        continue;
                    }

                    $error = (array) ($status['errors'][0] ?? []);

                    // Cost, where the account reports it. Minor units, from the
                    // provider — never estimated here and never mixed with an
                    // estimate.
                    $costMinor = null;
                    $currency = null;
                    if (isset($status['pricing']['billable']) && $status['pricing']['billable'] === true) {
                        $currency = isset($status['pricing']['currency'])
                            ? strtoupper((string) $status['pricing']['currency'])
                            : null;
                    }

                    $events[] = new NormalisedEvent(
                        // The provider re-sends the same status; the id must
                        // therefore include the state or `delivered` would
                        // deduplicate against `sent`.
                        providerEventId: 'st:' . (string) ($status['id'] ?? '') . ':' . $providerStatus,
                        kind: $mapped === 'failed' ? 'error' : 'status',
                        providerMessageId: (string) ($status['id'] ?? ''),
                        status: $mapped,
                        errorCode: isset($error['code']) ? 'whatsapp_' . $error['code'] : null,
                        errorDetail: isset($error['title']) ? (string) $error['title'] : null,
                        occurredAt: isset($status['timestamp']) ? gmdate('c', (int) $status['timestamp']) : null,
                        costMinor: $costMinor,
                        costCurrency: $currency,
                    );
                }
            }
        }

        return $events;
    }

    // -----------------------------------------------------------------------

    private function graphVersion(): string
    {
        $configured = trim(Env::get('MESSAGING_WHATSAPP_GRAPH_VERSION'));

        return preg_match('/^v\d+\.\d+$/', $configured) === 1 ? $configured : self::DEFAULT_GRAPH_VERSION;
    }

    /** @return array<string, mixed> */
    private function textPayload(OutboundMessage $message): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $message->toAddress,
            'type'              => 'text',
            'text'              => ['preview_url' => true, 'body' => $message->body],
        ];
    }

    /** @return array<string, mixed> */
    private function templatePayload(OutboundMessage $message): array
    {
        // Positional body parameters, in the order the variable schema declares.
        // The schema is validated before dispatch, so an unbound variable is a
        // refusal upstream rather than a literal "{{1}}" on a customer's phone.
        $parameters = [];
        foreach ($message->variables as $value) {
            $parameters[] = ['type' => 'text', 'text' => (string) $value];
        }

        $components = [];
        if ($parameters !== []) {
            $components[] = ['type' => 'body', 'parameters' => $parameters];
        }
        foreach ($message->components as $component) {
            $components[] = $component;
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $message->toAddress,
            'type'              => 'template',
            'template'          => [
                'name'       => $message->providerTemplateId,
                'language'   => ['code' => $message->language !== '' ? $message->language : 'en'],
                'components' => $components,
            ],
        ];
    }

    private function e164(string $raw): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';

        return $digits === '' ? '' : '+' . $digits;
    }

    /** @param array<string, mixed> $inbound */
    private function inboundText(array $inbound, string $type): string
    {
        return match ($type) {
            'text'        => (string) ($inbound['text']['body'] ?? ''),
            'button'      => (string) ($inbound['button']['text'] ?? ''),
            'interactive' => (string) (
                $inbound['interactive']['button_reply']['title']
                ?? $inbound['interactive']['list_reply']['title']
                ?? ''
            ),
            'image', 'document', 'video', 'audio' => (string) ($inbound[$type]['caption'] ?? ''),
            default => '',
        };
    }

    /**
     * Media as PROVIDER REFERENCES, not bytes.
     *
     * A media id can be fetched from the provider when somebody opens the
     * attachment. Downloading every inbound image on receipt would fill this
     * product's storage with other people's files for no one's benefit.
     *
     * @param array<string, mixed> $inbound
     * @return list<array<string, string>>
     */
    private function inboundMedia(array $inbound, string $type): array
    {
        if (!in_array($type, ['image', 'document', 'video', 'audio', 'sticker'], true)) {
            return [];
        }

        $media = (array) ($inbound[$type] ?? []);
        $id = (string) ($media['id'] ?? '');
        if ($id === '') {
            return [];
        }

        return [[
            'provider_media_id' => $id,
            // The provider's claim about the type. Verified on fetch, never
            // trusted as-is — see Domain/AttachmentService.
            'declared_type'     => (string) ($media['mime_type'] ?? 'application/octet-stream'),
            'filename'          => (string) ($media['filename'] ?? ''),
        ]];
    }
}
