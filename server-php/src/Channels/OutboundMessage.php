<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

/**
 * The content an adapter is asked to send.
 *
 * Assembled by DispatchService AFTER every gate has passed — consent, capability,
 * template approval, variable binding, link and attachment validation, and a
 * re-read of any external fact the content depends on. An adapter receives this
 * and calls its provider; it does not decide whether the message should exist.
 *
 * That split is deliberate. There is exactly one place in this product where
 * "may we send this?" is answered (Domain/DispatchGuard) and it is not inside
 * three separate provider adapters where one of them would eventually get it
 * wrong.
 */
final class OutboundMessage
{
    /**
     * @param list<array{url:string, media_type:string, filename:string}> $attachments
     * @param array<string, string>                                       $variables
     */
    public function __construct(
        public readonly string $messageUuid,
        public readonly string $toAddress,
        public readonly string $body,
        public readonly string $contentType = 'text',
        public readonly string $language = '',
        public readonly ?string $providerTemplateId = null,
        public readonly array $variables = [],
        public readonly array $attachments = [],
        /** Buttons and quick replies, in the adapter's normalised shape. */
        public readonly array $components = [],
    ) {
    }

    public function isTemplate(): bool
    {
        return $this->contentType === 'template' && $this->providerTemplateId !== null;
    }
}
