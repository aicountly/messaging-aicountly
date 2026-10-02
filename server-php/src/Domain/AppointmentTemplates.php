<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * The templates Appointments sends by name, and the variables they take.
 *
 * ## One catalogue, shared in words with Appointments
 *
 * Appointments owns WHEN a client is told something and WHAT it is about;
 * Messaging owns the template, its provider approval and the sender. The two
 * meet at the template NAME and its VARIABLE NAMES, so they are written down
 * once here and in docs/APPOINTMENTS_MESSAGING_CONTRACT.md (the same file in
 * both repositories). tests/service.php asserts that this catalogue equals the
 * variable list in tests/fixtures/service-api/variables.json, which
 * Appointments' own suite asserts its payload against.
 *
 * ## What seeding does and does not do
 *
 * `seed()` creates each template as a DRAFT version (provider_status
 * `not_submitted`). Nothing here is, or claims to be, provider-approved: a
 * WhatsApp utility template must be submitted and approved by the provider, and
 * an SMS template on an Indian route needs its DLT registration first. Until
 * then a send is refused `template_not_approved` and Appointments records the
 * reminder as failed with that reason — it is never sent as free text.
 *
 * ## Variables
 *
 * `sender_name` is the only one Messaging fills in itself: it is the display
 * name of the company's connected sender, i.e. the sending company's identity.
 * Everything else is rendered by Appointments in the BOOKING's timezone,
 * because the booking — not the server, not the provider — knows where the
 * appointment is.
 */
final class AppointmentTemplates
{
    /** Variables Messaging resolves from the company's connection when the caller does not send them. */
    public const BUILT_IN = ['sender_name'];

    /**
     * kind => [template name, body, variables]
     *
     * @return array<string, array{name:string, body:string, variables:list<string>}>
     */
    public static function catalogue(): array
    {
        return [
            'confirmation' => [
                'name'      => 'appointment_confirmation',
                'body'      => 'Hello {{client_name}}, your {{service_name}} appointment is confirmed for {{when}}. '
                    . 'Reference: {{reference}}. {{sender_name}}',
                'variables' => ['client_name', 'service_name', 'when', 'reference', 'sender_name'],
            ],
            'reminder' => [
                'name'      => 'appointment_reminder',
                'body'      => 'Hello {{client_name}}, a reminder of your {{service_name}} appointment on {{when}}. '
                    . 'Reference: {{reference}}. {{sender_name}}',
                'variables' => ['client_name', 'service_name', 'when', 'reference', 'sender_name'],
            ],
            'cancellation' => [
                'name'      => 'appointment_cancellation',
                'body'      => 'Hello {{client_name}}, your {{service_name}} appointment on {{when}} '
                    . '(reference {{reference}}) has been cancelled. {{sender_name}}',
                'variables' => ['client_name', 'service_name', 'when', 'reference', 'sender_name'],
            ],
            'reschedule' => [
                'name'      => 'appointment_reschedule',
                'body'      => 'Hello {{client_name}}, your {{service_name}} appointment (reference {{reference}}) '
                    . 'has moved from {{old_when}} to {{when}}. {{sender_name}}',
                'variables' => ['client_name', 'service_name', 'old_when', 'when', 'reference', 'sender_name'],
            ],
        ];
    }

    /** @return list<string> template names, for the kinds that have one */
    public static function names(): array
    {
        return array_values(array_map(static fn (array $t) => $t['name'], self::catalogue()));
    }

    /**
     * Create the draft templates for a company.
     *
     * Idempotent: a template that already exists on a channel is left alone
     * (it may have been edited, submitted or approved since).
     *
     * @param list<string> $channels
     * @return list<array{channel:string, name:string, action:string}> what was (or would be) done
     */
    public static function seed(Context $ctx, Auth $auth, array $channels, bool $apply): array
    {
        $report = [];

        foreach ($channels as $channel) {
            foreach (self::catalogue() as $template) {
                $exists = Db::first(
                    'SELECT template_uuid FROM messaging_templates
                     WHERE cmp_id = :cmp AND channel = :ch AND name = :name',
                    ['cmp' => $ctx->cmpId, 'ch' => $channel, 'name' => $template['name']],
                );
                if ($exists !== null) {
                    $report[] = ['channel' => $channel, 'name' => $template['name'], 'action' => 'exists'];
                    continue;
                }
                if (!$apply) {
                    $report[] = ['channel' => $channel, 'name' => $template['name'], 'action' => 'would_create'];
                    continue;
                }

                $schema = [];
                foreach ($template['variables'] as $name) {
                    $schema[] = [
                        'name'     => $name,
                        'type'     => 'text',
                        'example'  => self::example($name),
                        'required' => true,
                        'source'   => in_array($name, self::BUILT_IN, true) ? 'messaging' : 'appointments',
                    ];
                }

                $saved = TemplateService::save($ctx, $auth, [
                    'name'            => $template['name'],
                    'channel'         => $channel,
                    'language'        => 'en',
                    'category'        => 'utility',
                    'body'            => $template['body'],
                    'variable_schema' => $schema,
                ]);
                $report[] = [
                    'channel' => $channel,
                    'name'    => $template['name'],
                    'action'  => $saved['ok'] ? 'created_draft' : 'failed: ' . $saved['detail'],
                ];
            }
        }

        return $report;
    }

    private static function example(string $name): string
    {
        return match ($name) {
            'client_name'  => 'Priya',
            'service_name' => 'Initial consultation',
            'when'         => 'Tue 14 Oct 2026, 10:00 IST',
            'old_when'     => 'Mon 13 Oct 2026, 15:30 IST',
            'reference'    => 'AP-1042',
            'sender_name'  => 'Sharma & Co',
            default        => '',
        };
    }
}
