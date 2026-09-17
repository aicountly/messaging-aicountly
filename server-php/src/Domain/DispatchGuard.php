<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Channels\Capability;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * The ONE place that answers "may we send this?".
 *
 * Every gate in section 11 of the brief is here, in order, and nothing sends
 * without passing all of them. It is one class rather than checks spread
 * through three adapters and two controllers because the failure mode of the
 * spread version is predictable: one path forgets one check, and the check it
 * forgets is consent.
 *
 * ## The gates
 *
 *  1. The connection exists, is active and is configured.
 *  2. The channel can actually do what the message needs.
 *  3. Consent and suppression, read NOW — not at approval.
 *  4. Template approval, as the PROVIDER reported it.
 *  5. Variables are all bound.
 *  6. The approval matches the content that is about to go out.
 *  7. Every link and attachment the message promises actually exists.
 *  8. Quiet hours.
 *
 * ## Gate 7 is the one worth reading twice
 *
 * A draft that says "the payment link is below" with no link is worse than a
 * draft that says the link is unavailable. `assertPromisedResourcesExist()`
 * reads the message's own text for a promise and refuses to dispatch when the
 * promise is unmet. An approval cannot override it: approving content is
 * permission to send THAT CONTENT, not permission to conjure a resource that
 * does not exist. A human who wants to send it anyway must edit the promise
 * out, which invalidates the approval and sends it back for review — exactly
 * the right amount of friction.
 */
final class DispatchGuard
{
    /** Phrases that constitute a promise of an attached resource. */
    private const LINK_PROMISES = [
        'payment link', 'pay now', 'link below', 'link attached', 'attached link',
        'भुगतान लिंक', 'लिंक नीचे',
    ];
    private const ATTACHMENT_PROMISES = [
        'attached', 'attachment', 'please find', 'enclosed', 'pdf', 'invoice copy',
        'संलग्न', 'अटैच',
    ];

    /**
     * @param array<string, mixed> $message the message row
     * @return array{allowed:bool, code:string, detail:string, retryable:bool, checks:list<array<string,mixed>>}
     */
    public static function evaluate(Context $ctx, array $message, ChannelConnection $connection): array
    {
        $checks = [];

        // ------------------------------------------------------------------
        // 1. Connection and sender.
        // ------------------------------------------------------------------
        $adapter = ChannelRegistry::adapterFor($connection);
        if ($adapter === null) {
            return self::refuse($checks, 'channel_unavailable',
                'No adapter is installed for provider "' . $connection->provider . '".', false);
        }
        if (!$connection->isActive) {
            return self::refuse($checks, 'channel_unavailable', 'This channel connection is not active.', false);
        }
        $gap = $adapter->configurationGap($connection);
        if ($gap !== null) {
            return self::refuse($checks, 'channel_not_configured', $gap, false);
        }
        $checks[] = self::pass('channel', 'Channel is connected and configured.');

        // ------------------------------------------------------------------
        // 2. Capability.
        // ------------------------------------------------------------------
        $capabilities = ChannelRegistry::capabilities($connection);
        $isTemplate = ($message['content_type'] ?? 'text') === 'template';

        if (!$isTemplate && ($capabilities[Capability::FREEFORM_TEXT] ?? false) !== true) {
            return self::refuse($checks, 'capability_missing',
                'This channel does not accept free-form messages. Use an approved template.', false);
        }
        if ($isTemplate && ($capabilities[Capability::TEMPLATES] ?? false) !== true) {
            return self::refuse($checks, 'capability_missing',
                'This channel does not support template messages.', false);
        }

        $attachments = self::attachmentsFor((string) $message['message_uuid']);
        if ($attachments !== [] && ($capabilities[Capability::OUTBOUND_MEDIA] ?? false) !== true) {
            return self::refuse($checks, 'capability_missing',
                'This channel cannot send attachments.', false);
        }

        // A route that forbids links is a real thing on some SMS routes. If the
        // capability says no and the body has one, the provider would reject it
        // — better to say so here than to burn a send and a customer's trust.
        if (self::containsUrl((string) ($message['body'] ?? '')) && ($capabilities[Capability::LINKS] ?? true) !== true) {
            return self::refuse($checks, 'capability_missing',
                'This channel does not permit links in message content.', false);
        }
        $checks[] = self::pass('capability', 'The channel supports this message type.');

        // ------------------------------------------------------------------
        // 3. Consent and suppression — READ NOW.
        // ------------------------------------------------------------------
        $conversation = Db::first(
            'SELECT customer_address FROM messaging_conversations WHERE conversation_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => $message['conversation_uuid'], 'cmp' => $ctx->cmpId],
        );
        if ($conversation === null) {
            return self::refuse($checks, 'conversation_missing', 'The conversation could not be found.', false);
        }

        $purpose = self::purposeFor($message);
        $consent = ConsentService::evaluate($ctx, (string) $message['channel'], (string) $conversation['customer_address'], $purpose);

        if (!$consent['allowed']) {
            // 'suppressed' and 'withdrawn' are different words on the screen
            // because they are different situations for the business.
            return self::refuse(
                $checks,
                $consent['reason'] === 'suppressed' ? 'suppressed' : 'no_consent',
                $consent['detail'],
                false,
            );
        }
        $checks[] = self::pass('consent', $consent['detail'], ['checked_at' => $consent['checked_at']]);

        // ------------------------------------------------------------------
        // 4 & 5. Template approval and variable binding.
        // ------------------------------------------------------------------
        if ($isTemplate) {
            $template = self::templateVersion($ctx, $message);
            if ($template === null) {
                return self::refuse($checks, 'template_missing',
                    'The template version this message was built from no longer exists.', false);
            }
            if ((string) $template['provider_status'] !== 'approved') {
                return self::refuse($checks, 'template_not_approved',
                    'The provider has not approved this template (status: '
                    . (string) $template['provider_status'] . ').', false);
            }

            $missing = self::unboundVariables($template, $message);
            if ($missing !== []) {
                return self::refuse($checks, 'variables_missing',
                    'These template variables have no value: ' . implode(', ', $missing) . '.', false);
            }
            $checks[] = self::pass('template', 'Template approved by the provider and all variables bound.', [
                'provider_status_read_at' => $template['provider_status_read_at'] ?? null,
            ]);
        }

        // ------------------------------------------------------------------
        // 6. Approval matches the content.
        // ------------------------------------------------------------------
        $approvalCheck = self::checkApproval($message);
        if (!$approvalCheck['allowed']) {
            return self::refuse($checks, $approvalCheck['code'], $approvalCheck['detail'], false);
        }
        $checks[] = self::pass('approval', $approvalCheck['detail']);

        // ------------------------------------------------------------------
        // 7. Promised resources exist.
        // ------------------------------------------------------------------
        $promise = self::assertPromisedResourcesExist((string) ($message['body'] ?? ''), $attachments);
        if (!$promise['allowed']) {
            return self::refuse($checks, 'promised_resource_missing', $promise['detail'], false);
        }
        $checks[] = self::pass('content', $promise['detail']);

        // ------------------------------------------------------------------
        // 8. Quiet hours.
        // ------------------------------------------------------------------
        $quiet = self::quietHours($ctx, $purpose);
        if (!$quiet['allowed']) {
            // Retryable: the answer will be different later, which is exactly
            // what a journey's delay step is for.
            return self::refuse($checks, 'quiet_hours', $quiet['detail'], true);
        }
        $checks[] = self::pass('timing', $quiet['detail']);

        return ['allowed' => true, 'code' => 'ok', 'detail' => 'All pre-send checks passed.', 'retryable' => false, 'checks' => $checks];
    }

    /**
     * Does the content promise something that is not there?
     *
     * The check is textual and deliberately narrow: it looks for the specific
     * promises this product's assistant and templates can make, and it refuses
     * when the corresponding resource is absent. It is not a general-purpose
     * claim detector and does not pretend to be one.
     *
     * @param list<array<string, mixed>> $attachments
     * @return array{allowed:bool, detail:string}
     */
    public static function assertPromisedResourcesExist(string $body, array $attachments): array
    {
        $lower = mb_strtolower($body);
        $hasUrl = self::containsUrl($body);

        foreach (self::LINK_PROMISES as $phrase) {
            if (str_contains($lower, $phrase) && !$hasUrl) {
                return [
                    'allowed' => false,
                    'detail'  => 'This message refers to a link ("' . $phrase . '") but contains no link. '
                        . 'Nothing can be sent that tells a customer a link is present when it is not. '
                        . 'Edit the message to remove the reference, or add the link.',
                ];
            }
        }

        if ($attachments === []) {
            foreach (self::ATTACHMENT_PROMISES as $phrase) {
                // "please find attached" with nothing attached. The narrower
                // words ('pdf', 'invoice copy') only count when the message
                // also says attached, so "your invoice is overdue" is fine.
                if (str_contains($lower, $phrase) && str_contains($lower, 'attach')) {
                    return [
                        'allowed' => false,
                        'detail'  => 'This message refers to an attachment but none is attached. '
                            . 'Attach the document, or edit the message.',
                    ];
                }
            }
        }

        foreach ($attachments as $attachment) {
            $scan = (string) ($attachment['scan_status'] ?? 'pending');
            if ($scan === 'infected') {
                return ['allowed' => false, 'detail' => 'An attachment on this message failed a malware scan.'];
            }
            if ($scan === 'pending') {
                return ['allowed' => false, 'detail' => 'An attachment on this message has not finished being scanned.'];
            }
        }

        return ['allowed' => true, 'detail' => 'Content references match the resources present.'];
    }

    /**
     * The approval gate.
     *
     * A message must be approved, and the approval must be OF THIS CONTENT.
     * Comparing hashes rather than trusting `approved_at` is what makes an edit
     * after approval impossible to miss.
     *
     * @param array<string, mixed> $message
     * @return array{allowed:bool, code:string, detail:string}
     */
    public static function checkApproval(array $message): array
    {
        $status = (string) ($message['status'] ?? '');
        if (!in_array($status, [MessageState::APPROVED, MessageState::QUEUED, MessageState::DISPATCHING], true)) {
            return [
                'allowed' => false,
                'code'    => 'not_approved',
                'detail'  => 'This message is ' . MessageState::describe($status) . ' and has not been approved for sending.',
            ];
        }

        $current = (string) ($message['content_hash'] ?? '');
        $approved = (string) ($message['approved_content_hash'] ?? '');

        if ($approved === '') {
            return ['allowed' => false, 'code' => 'not_approved', 'detail' => 'No approval is recorded for this message.'];
        }
        if (!hash_equals($approved, $current)) {
            return [
                'allowed' => false,
                'code'    => 'approval_stale',
                'detail'  => 'This message was edited after it was approved, so the approval no longer applies. '
                    . 'It needs reviewing again.',
            ];
        }

        return ['allowed' => true, 'code' => 'ok', 'detail' => 'Approved, and the content matches what was approved.'];
    }

    /**
     * Quiet hours, evaluated in the company's configured timezone.
     *
     * Transactional messages are NOT held. Somebody waiting on a delivery
     * update at nine in the evening wants it then, and a quiet-hours rule that
     * blocks it is a rule that makes the product worse. It applies to
     * promotional sends, which is what it is for.
     *
     * @return array{allowed:bool, detail:string}
     */
    public static function quietHours(Context $ctx, string $purpose): array
    {
        if ($purpose !== 'promotional') {
            return ['allowed' => true, 'detail' => 'Quiet hours do not apply to ' . $purpose . ' messages.'];
        }

        $settings = Settings::for($ctx);
        $start = $settings['quiet_hours_start'] ?? null;
        $end = $settings['quiet_hours_end'] ?? null;

        if ($start === null || $end === null || $start === '' || $end === '') {
            return ['allowed' => true, 'detail' => 'No quiet hours are configured.'];
        }

        $tz = new \DateTimeZone((string) ($settings['timezone'] ?? 'Asia/Kolkata'));
        $localNow = Clock::now()->setTimezone($tz)->format('H:i:s');

        // A window that wraps midnight (21:00 → 08:00) is the normal case, and
        // the naive comparison gets it exactly backwards.
        $inWindow = $start <= $end
            ? ($localNow >= $start && $localNow < $end)
            : ($localNow >= $start || $localNow < $end);

        if ($inWindow) {
            return [
                'allowed' => false,
                'detail'  => 'It is quiet hours for this company (' . substr((string) $start, 0, 5)
                    . '–' . substr((string) $end, 0, 5) . ' ' . $tz->getName() . '). This will send afterwards.',
            ];
        }

        return ['allowed' => true, 'detail' => 'Outside quiet hours.'];
    }

    // -----------------------------------------------------------------------

    /**
     * What this message is for, which decides which consent satisfies it.
     *
     * Derived from the template's category or the message origin — never taken
     * from a request, because "it's transactional, honestly" is exactly what a
     * caller wanting to bypass promotional consent would say.
     *
     * @param array<string, mixed> $message
     */
    public static function purposeFor(array $message): string
    {
        $origin = (string) ($message['origin'] ?? 'AGENT');

        return match ($origin) {
            // A human answering a customer who wrote in is a service reply.
            'AGENT'    => 'service',
            'REACH'    => 'promotional',
            // Business events: an invoice, an order, an appointment.
            'APPOINTMENTS', 'BILLING', 'BOOKS', 'SALES', 'POS' => 'transactional',
            'JOURNEY'  => (string) ($message['journey_purpose'] ?? 'transactional'),
            default    => 'transactional',
        };
    }

    /** @return list<array<string, mixed>> */
    private static function attachmentsFor(string $messageUuid): array
    {
        return Db::all(
            'SELECT attachment_uuid, storage, storage_ref, media_type, filename, scan_status
             FROM messaging_message_attachments WHERE message_uuid = :uuid',
            ['uuid' => $messageUuid],
        );
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    private static function templateVersion(Context $ctx, array $message): ?array
    {
        return Db::first(
            'SELECT * FROM messaging_template_versions
             WHERE cmp_id = :cmp AND template_uuid = :tpl AND version = :ver AND language = :lang',
            [
                'cmp'  => $ctx->cmpId,
                'tpl'  => $message['template_uuid'],
                'ver'  => $message['template_version'],
                'lang' => (string) ($message['language'] ?? 'en') !== '' ? (string) $message['language'] : 'en',
            ],
        );
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $message
     * @return list<string>
     */
    private static function unboundVariables(array $template, array $message): array
    {
        $schema = Db::jsonColumn($template['variable_schema'] ?? null);
        $bound = Db::jsonColumn($message['template_variables'] ?? null);

        $missing = [];
        foreach ($schema as $variable) {
            $name = (string) ($variable['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $required = (bool) ($variable['required'] ?? true);
            $value = $bound[$name] ?? null;

            if ($required && ($value === null || trim((string) $value) === '')) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    private static function containsUrl(string $body): bool
    {
        return preg_match('#(https?://|www\.)[^\s]+#i', $body) === 1;
    }

    /**
     * @param list<array<string,mixed>> $checks
     * @return array{allowed:bool, code:string, detail:string, retryable:bool, checks:list<array<string,mixed>>}
     */
    private static function refuse(array $checks, string $code, string $detail, bool $retryable): array
    {
        $checks[] = ['gate' => $code, 'passed' => false, 'detail' => $detail];

        return ['allowed' => false, 'code' => $code, 'detail' => $detail, 'retryable' => $retryable, 'checks' => $checks];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private static function pass(string $gate, string $detail, array $extra = []): array
    {
        return ['gate' => $gate, 'passed' => true, 'detail' => $detail] + $extra;
    }
}
