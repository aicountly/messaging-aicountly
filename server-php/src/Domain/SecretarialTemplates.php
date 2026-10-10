<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * The one template Secretarial sends its SMS / WhatsApp notices with.
 *
 * Secretarial (NOTIFY, LR-40) names it `secretarial_notice` (overridable per
 * deployment) and always sends these variables, from its notification
 * ledger: the recipient's name as the Secretarial register holds it, the
 * notice's subject, a one-line summary and the ledger reference. Like the
 * appointment templates it is created as a DRAFT: nothing is sent until an
 * administrator submits it and the provider approves it, and until then a
 * send is refused `template_not_approved`, which Secretarial records as a
 * configuration gap — never as sent.
 */
final class SecretarialTemplates
{
    public const NAME = 'secretarial_notice';

    /** @var list<string> */
    public const VARIABLES = ['recipient_name', 'subject', 'summary', 'reference', 'sender_name'];

    /**
     * @param list<string> $channels
     * @return list<array{channel:string, name:string, action:string}>
     */
    public static function seed(Context $ctx, Auth $auth, array $channels, bool $apply): array
    {
        $report = [];
        foreach ($channels as $channel) {
            $exists = Db::first(
                'SELECT template_uuid FROM messaging_templates WHERE cmp_id = :cmp AND channel = :ch AND name = :name',
                ['cmp' => $ctx->cmpId, 'ch' => $channel, 'name' => self::NAME],
            );
            if ($exists !== null) {
                $report[] = ['channel' => $channel, 'name' => self::NAME, 'action' => 'exists'];
                continue;
            }
            if (!$apply) {
                $report[] = ['channel' => $channel, 'name' => self::NAME, 'action' => 'would_create'];
                continue;
            }
            $schema = [];
            foreach (self::VARIABLES as $name) {
                $schema[] = [
                    'name'     => $name,
                    'type'     => 'text',
                    'example'  => self::example($name),
                    'required' => true,
                    'source'   => in_array($name, AppointmentTemplates::BUILT_IN, true) ? 'messaging' : 'secretarial',
                ];
            }
            $saved = TemplateService::save($ctx, $auth, [
                'name'            => self::NAME,
                'channel'         => $channel,
                'language'        => 'en',
                'category'        => 'utility',
                'body'            => 'Dear {{recipient_name}}, {{subject}}. {{summary}} Ref: {{reference}}. {{sender_name}}',
                'variable_schema' => $schema,
            ]);
            $report[] = ['channel' => $channel, 'name' => self::NAME, 'action' => $saved['ok'] ? 'created_draft' : 'failed: ' . $saved['detail']];
        }

        return $report;
    }

    private static function example(string $name): string
    {
        return match ($name) {
            'recipient_name' => 'Asha Rao',
            'subject'        => 'Reminder: MGT-7 annual return due 29 Nov 2026',
            'summary'        => 'Compliance reminder from AICOUNTLY Secretarial.',
            'reference'      => 'ntf-3f2a9c0d1e4b5a69',
            'sender_name'    => 'Sharma & Co',
            default          => '',
        };
    }
}
