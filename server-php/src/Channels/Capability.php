<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

/**
 * The things a channel might or might not be able to do.
 *
 * This list exists because of one mistake that is very easy to make and very
 * visible when you make it: assuming every channel behaves like WhatsApp.
 *
 * An alphanumeric SMS sender id cannot receive a reply. A transactional SMS
 * route may not carry a link. RCS capability depends on the recipient's handset
 * and carrier, not just on the sender. Read receipts are a WhatsApp feature and
 * SMS has nothing like them. A UI that assumes otherwise shows a reply box that
 * silently discards what an agent types, or reports a 0% read rate as a
 * catastrophe when the number is simply unknowable.
 *
 * So capability is DATA, declared per connection, and the composer, the journey
 * validator and the dispatch guard all consult it. Nothing infers a capability
 * from the string 'whatsapp'.
 */
final class Capability
{
    /** The channel can receive messages from the customer. */
    public const INBOUND = 'inbound';
    /** Free-form (non-template) outbound text is allowed. */
    public const FREEFORM_TEXT = 'freeform_text';
    /** Template messages are supported, and possibly required. */
    public const TEMPLATES = 'templates';
    /** The provider requires pre-approved templates for business-initiated messages. */
    public const TEMPLATE_REQUIRED_FOR_INITIATION = 'template_required_for_initiation';
    /** Media attachments outbound. */
    public const OUTBOUND_MEDIA = 'outbound_media';
    /** Media attachments inbound. */
    public const INBOUND_MEDIA = 'inbound_media';
    /** Buttons, quick replies, carousels. */
    public const INTERACTIVE = 'interactive';
    /** The provider reports delivery receipts. */
    public const DELIVERY_RECEIPTS = 'delivery_receipts';
    /** The provider reports read receipts. Rare. */
    public const READ_RECEIPTS = 'read_receipts';
    /** The provider reports per-message cost. */
    public const COST_REPORTING = 'cost_reporting';
    /** Links are permitted by the route's own rules. */
    public const LINKS = 'links';
    /** The provider can be asked the status of a message we are unsure about. */
    public const STATUS_LOOKUP = 'status_lookup';
    /** The provider processes opt-outs itself and reports them. */
    public const PROVIDER_OPTOUT = 'provider_optout';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::INBOUND,
            self::FREEFORM_TEXT,
            self::TEMPLATES,
            self::TEMPLATE_REQUIRED_FOR_INITIATION,
            self::OUTBOUND_MEDIA,
            self::INBOUND_MEDIA,
            self::INTERACTIVE,
            self::DELIVERY_RECEIPTS,
            self::READ_RECEIPTS,
            self::COST_REPORTING,
            self::LINKS,
            self::STATUS_LOOKUP,
            self::PROVIDER_OPTOUT,
        ];
    }

    /** Words for a screen. Status is conveyed as text, never by colour alone. */
    public static function describe(string $capability): string
    {
        return match ($capability) {
            self::INBOUND          => 'Receives replies',
            self::FREEFORM_TEXT    => 'Free-form messages',
            self::TEMPLATES        => 'Approved templates',
            self::TEMPLATE_REQUIRED_FOR_INITIATION => 'Template required to start a conversation',
            self::OUTBOUND_MEDIA   => 'Send attachments',
            self::INBOUND_MEDIA    => 'Receive attachments',
            self::INTERACTIVE      => 'Buttons and quick replies',
            self::DELIVERY_RECEIPTS => 'Delivery confirmation',
            self::READ_RECEIPTS    => 'Read confirmation',
            self::COST_REPORTING   => 'Provider-reported cost',
            self::LINKS            => 'Links allowed',
            self::STATUS_LOOKUP    => 'Message status lookup',
            self::PROVIDER_OPTOUT  => 'Provider handles opt-outs',
            default                => $capability,
        };
    }
}
