<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Channels\Capability;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Features;

/**
 * The journey node graph, and what makes one publishable.
 *
 * ## The node types
 *
 *   fetch_source     read the authoritative record from its owning product
 *   condition        branch on a value that fetch_source returned
 *   check_eligibility consent, channel capability, suppression
 *   draft            build the message from a template
 *   approval         wait for a human
 *   delay            wait
 *   revalidate       re-read the source and cancel if it has changed
 *   send             dispatch
 *   pause_notify     stop and tell somebody
 *   stop             end the run
 *
 * ## Validation refuses to publish what cannot work
 *
 * A journey whose send step names a template the provider has not approved
 * cannot be published. Neither can one whose source product is not configured,
 * nor one whose channel cannot do what the step asks. The alternative is a
 * journey that publishes cleanly and then pauses on every single run, which
 * tells the person who built it nothing about why.
 *
 * ## Two rules the shape itself enforces
 *
 * A `send` node must be preceded by `check_eligibility`, and a live journey's
 * `send` must be preceded by `revalidate` when its content depends on a fetched
 * fact. Both are checked here rather than in the engine, so the failure lands
 * on the person publishing rather than on a customer.
 */
final class JourneyDefinition
{
    public const NODE_TYPES = [
        'fetch_source', 'condition', 'check_eligibility', 'draft', 'approval',
        'delay', 'revalidate', 'send', 'pause_notify', 'stop',
    ];

    /** Which product each source kind reads from. */
    public const SOURCES = [
        'books_invoice'          => 'books',
        'books_overdue_invoices' => 'books',
        'sales_order'            => 'sales',
        'appointments_booking'   => 'appointments',
        'pay_payment_link'       => 'pay',
        'contacts_contact'       => 'contacts',
        'messaging_conversation' => 'messaging',
    ];

    /**
     * Check a definition and say exactly what is wrong with it.
     *
     * @param array<string, mixed> $definition
     * @return array{valid:bool, errors:list<array<string,string>>, warnings:list<array<string,string>>, summary:array<string,mixed>}
     */
    public static function validate(Context $ctx, array $definition): array
    {
        $errors = [];
        $warnings = [];

        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        if ($nodes === []) {
            return [
                'valid'    => false,
                'errors'   => [['node' => '', 'message' => 'A journey needs at least one step.']],
                'warnings' => [],
                'summary'  => [],
            ];
        }

        $ids = [];
        $byId = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            $type = (string) ($node['type'] ?? '');

            if ($id === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
                $errors[] = ['node' => $id, 'message' => 'Every step needs an id of letters, numbers, dashes or underscores.'];
                continue;
            }
            if (isset($ids[$id])) {
                $errors[] = ['node' => $id, 'message' => 'Two steps share the id "' . $id . '".'];
                continue;
            }
            if (!in_array($type, self::NODE_TYPES, true)) {
                $errors[] = ['node' => $id, 'message' => 'Unknown step type "' . $type . '".'];
                continue;
            }

            $ids[$id] = true;
            $byId[$id] = $node;
        }

        // Every `next` must point at a step that exists. A dangling edge is a
        // run that stops for no stated reason.
        foreach ($byId as $id => $node) {
            foreach (self::edgesOf($node) as $label => $target) {
                if ($target !== null && $target !== '' && !isset($ids[$target])) {
                    $errors[] = [
                        'node'    => (string) $id,
                        'message' => 'Step "' . $id . '" branches to "' . $target . '", which does not exist.',
                    ];
                }
            }
        }

        $entry = (string) ($definition['entry'] ?? array_key_first($byId) ?? '');
        if ($entry === '' || !isset($ids[$entry])) {
            $errors[] = ['node' => '', 'message' => 'The journey has no valid first step.'];
        }

        // -------------------------------------------------------------------
        // Per-node checks against THIS deployment's configuration.
        // -------------------------------------------------------------------
        $sourcesUsed = [];
        $sendNodes = [];

        foreach ($byId as $id => $node) {
            $type = (string) $node['type'];

            if ($type === 'fetch_source') {
                $source = (string) ($node['source'] ?? '');
                if (!isset(self::SOURCES[$source])) {
                    $errors[] = ['node' => (string) $id, 'message' => 'Unknown data source "' . $source . '".'];
                    continue;
                }
                $product = self::SOURCES[$source];
                $sourcesUsed[$product] = true;

                if ($product !== 'messaging' && !Features::enabled($product)) {
                    // A journey that reads from a product this deployment has
                    // not connected cannot run. Refusing to publish it is
                    // kinder than letting it pause on every execution.
                    $errors[] = [
                        'node'    => (string) $id,
                        'message' => 'This step reads from Aicountly ' . ucfirst($product)
                            . ', which is not connected for this deployment. '
                            . (Features::explain($product) ?? ''),
                    ];
                }
            }

            if ($type === 'draft') {
                $templateUuid = (string) ($node['template_uuid'] ?? '');
                if ($templateUuid === '') {
                    $errors[] = ['node' => (string) $id, 'message' => 'A draft step needs a template.'];
                    continue;
                }
                $language = (string) ($node['language'] ?? 'en') ?: 'en';
                $version = TemplateService::sendableVersion($ctx, $templateUuid, $language);

                if ($version === null) {
                    $template = Db::first(
                        'SELECT name FROM messaging_templates WHERE cmp_id = :cmp AND template_uuid = :uuid',
                        ['cmp' => $ctx->cmpId, 'uuid' => $templateUuid],
                    );
                    // THE CHECK THE BRIEF ASKS FOR: missing provider approval
                    // blocks the send, and it blocks it at publish time.
                    $errors[] = [
                        'node'    => (string) $id,
                        'message' => 'The template "' . (string) ($template['name'] ?? $templateUuid)
                            . '" has no provider-approved version in ' . $language
                            . '. Submit it for approval before publishing this journey.',
                    ];
                }
            }

            if ($type === 'send') {
                $sendNodes[(string) $id] = $node;

                $channel = (string) ($node['channel'] ?? '');
                if ($channel === '') {
                    $errors[] = ['node' => (string) $id, 'message' => 'A send step needs a channel.'];
                    continue;
                }

                $connection = Db::first(
                    'SELECT * FROM messaging_channel_connections
                     WHERE cmp_id = :cmp AND channel = :channel AND is_active = TRUE
                     ORDER BY CASE WHEN status = \'connected\' THEN 0 ELSE 1 END LIMIT 1',
                    ['cmp' => $ctx->cmpId, 'channel' => $channel],
                );

                if ($connection === null) {
                    $errors[] = [
                        'node'    => (string) $id,
                        'message' => 'No ' . $channel . ' channel is connected, so this step cannot send. '
                            . 'Connect it in Channels & Trust.',
                    ];
                    continue;
                }

                $conn = \Aicountly\Api\Channels\ChannelConnection::fromRow($connection);
                $adapter = ChannelRegistry::adapterFor($conn);

                if ($adapter === null) {
                    $errors[] = ['node' => (string) $id, 'message' => 'No adapter is installed for provider "' . $conn->provider . '".'];
                    continue;
                }
                $gap = $adapter->configurationGap($conn);
                if ($gap !== null) {
                    $errors[] = ['node' => (string) $id, 'message' => $gap];
                    continue;
                }

                $capabilities = ChannelRegistry::capabilities($conn);
                if (($capabilities[Capability::TEMPLATE_REQUIRED_FOR_INITIATION] ?? false) === true) {
                    // The provider requires an approved template to open a
                    // conversation, so a business-initiated free-text send on
                    // this channel cannot work.
                    $draftsFromTemplate = self::precedingNodeOfType($byId, (string) $id, 'draft');
                    if ($draftsFromTemplate === null) {
                        $errors[] = [
                            'node'    => (string) $id,
                            'message' => 'This channel needs a provider-approved template to start a conversation, '
                                . 'so this send step must follow a draft step that uses one.',
                        ];
                    }
                }
            }
        }

        // -------------------------------------------------------------------
        // Shape rules.
        // -------------------------------------------------------------------
        foreach ($sendNodes as $id => $node) {
            if (self::precedingNodeOfType($byId, (string) $id, 'check_eligibility') === null) {
                $errors[] = [
                    'node'    => (string) $id,
                    'message' => 'Every send step must be preceded by an eligibility check, so consent and channel '
                        . 'capability are confirmed before anything reaches a customer.',
                ];
            }

            // Revalidation is required when the content depends on a fetched
            // fact. Without it, a reminder can send from a balance that was
            // read before an approval that a human took a day over.
            if ($sourcesUsed !== [] && self::precedingNodeOfType($byId, (string) $id, 'revalidate') === null) {
                $hasApprovalOrDelay = self::precedingNodeOfType($byId, (string) $id, 'approval') !== null
                    || self::precedingNodeOfType($byId, (string) $id, 'delay') !== null;

                if ($hasApprovalOrDelay) {
                    $errors[] = [
                        'node'    => (string) $id,
                        'message' => 'This journey waits (for approval or a delay) before sending, and its content '
                            . 'depends on data from another product. Add a revalidate step before the send so the '
                            . 'facts are re-read — otherwise a reminder can go out against a balance that has changed.',
                    ];
                } else {
                    $warnings[] = [
                        'node'    => (string) $id,
                        'message' => 'Consider a revalidate step before sending, so the source is re-read immediately '
                            . 'before dispatch.',
                    ];
                }
            }
        }

        if ($sendNodes === []) {
            $warnings[] = ['node' => '', 'message' => 'This journey never sends a message.'];
        }

        // A failure path. Without one, a run that cannot read its source
        // simply stops and nobody is told.
        $hasFailurePath = false;
        foreach ($byId as $node) {
            if (in_array((string) $node['type'], ['pause_notify'], true)) {
                $hasFailurePath = true;
                break;
            }
        }
        if (!$hasFailurePath && $sourcesUsed !== []) {
            $warnings[] = [
                'node'    => '',
                'message' => 'This journey has no pause-and-notify step. Add one so a source outage is visible '
                    . 'rather than silent.',
            ];
        }

        return [
            'valid'    => $errors === [],
            'errors'   => $errors,
            'warnings' => $warnings,
            'summary'  => [
                'nodes'          => count($byId),
                'entry'          => $entry,
                'sends'          => count($sendNodes),
                'source_products' => array_keys($sourcesUsed),
                'requires_approval' => self::hasNodeOfType($byId, 'approval'),
            ],
        ];
    }

    /**
     * The edges leaving a node.
     *
     * @param array<string, mixed> $node
     * @return array<string, string|null>
     */
    public static function edgesOf(array $node): array
    {
        $edges = [];

        if (isset($node['next']) && is_string($node['next'])) {
            $edges['next'] = $node['next'];
        }
        foreach (['on_true', 'on_false', 'on_eligible', 'on_ineligible', 'on_unavailable', 'on_changed', 'on_failure', 'on_approved', 'on_rejected'] as $label) {
            if (isset($node[$label]) && is_string($node[$label])) {
                $edges[$label] = $node[$label];
            }
        }
        if (isset($node['branches']) && is_array($node['branches'])) {
            foreach ($node['branches'] as $label => $target) {
                if (is_string($target)) {
                    $edges['branch:' . $label] = $target;
                }
            }
        }

        return $edges;
    }

    /**
     * Whether a node of $type appears anywhere on a path leading to $targetId.
     *
     * A reverse walk of the graph. Cycles are possible in a badly-built
     * definition, so visited nodes are tracked.
     *
     * @param array<string, array<string, mixed>> $byId
     * @return string|null the node id, or null
     */
    private static function precedingNodeOfType(array $byId, string $targetId, string $type): ?string
    {
        $incoming = [];
        foreach ($byId as $id => $node) {
            foreach (self::edgesOf($node) as $target) {
                if ($target !== null) {
                    $incoming[$target][] = (string) $id;
                }
            }
        }

        $queue = $incoming[$targetId] ?? [];
        $seen = [$targetId => true];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            if ((string) ($byId[$id]['type'] ?? '') === $type) {
                return $id;
            }
            foreach ($incoming[$id] ?? [] as $parent) {
                $queue[] = $parent;
            }
        }

        return null;
    }

    /** @param array<string, array<string, mixed>> $byId */
    private static function hasNodeOfType(array $byId, string $type): bool
    {
        foreach ($byId as $node) {
            if ((string) ($node['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * The four operational journeys the product ships with, as definitions.
     *
     * These are STARTING POINTS a user reviews and publishes, not journeys that
     * run on install. Each one embodies the pattern the brief describes: read
     * the source live, check eligibility, draft, have a human approve,
     * RE-READ THE SOURCE, then send once.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function starters(): array
    {
        return [
            'overdue_invoice_reminder' => [
                'name'        => 'Overdue invoice reminder',
                'description' => 'Reads the invoice from Aicountly Books, asks for review, re-reads it, then sends once.',
                'entry'       => 'fetch_invoice',
                'nodes'       => [
                    [
                        'id' => 'fetch_invoice', 'type' => 'fetch_source', 'source' => 'books_invoice',
                        'label' => 'Read the invoice from Books',
                        'next' => 'still_unpaid', 'on_unavailable' => 'pause_source',
                    ],
                    [
                        'id' => 'still_unpaid', 'type' => 'condition',
                        'label' => 'Is it still outstanding?',
                        'expression' => 'source.outstanding_minor > 0',
                        'on_true' => 'eligibility', 'on_false' => 'stop_paid',
                    ],
                    [
                        'id' => 'eligibility', 'type' => 'check_eligibility',
                        'label' => 'Consent and channel', 'purpose' => 'transactional',
                        'on_eligible' => 'draft', 'on_ineligible' => 'stop_ineligible',
                    ],
                    [
                        'id' => 'draft', 'type' => 'draft',
                        'label' => 'Draft the reminder', 'template_uuid' => '', 'language' => 'en',
                        'next' => 'approval',
                    ],
                    ['id' => 'approval', 'type' => 'approval', 'label' => 'Human review', 'on_approved' => 'revalidate', 'on_rejected' => 'stop_rejected'],
                    [
                        'id' => 'revalidate', 'type' => 'revalidate', 'source' => 'books_invoice',
                        'label' => 'Re-read the invoice',
                        'expression' => 'source.outstanding_minor > 0',
                        'next' => 'send', 'on_changed' => 'stop_paid', 'on_unavailable' => 'pause_source',
                    ],
                    ['id' => 'send', 'type' => 'send', 'label' => 'Send once', 'channel' => 'whatsapp', 'next' => 'stop_sent'],
                    ['id' => 'pause_source', 'type' => 'pause_notify', 'label' => 'Books unavailable — pause and notify'],
                    ['id' => 'stop_paid', 'type' => 'stop', 'label' => 'Already paid — nothing sent', 'outcome' => 'invoice_paid'],
                    ['id' => 'stop_ineligible', 'type' => 'stop', 'label' => 'Not eligible', 'outcome' => 'no_consent'],
                    ['id' => 'stop_rejected', 'type' => 'stop', 'label' => 'Review declined', 'outcome' => 'rejected'],
                    ['id' => 'stop_sent', 'type' => 'stop', 'label' => 'Sent', 'outcome' => 'sent'],
                ],
            ],
            'appointment_reminder' => [
                'name'        => 'Appointment reminder',
                'description' => 'Reads the booking from Aicountly Appointments and reminds the customer.',
                'entry'       => 'fetch_booking',
                'nodes'       => [
                    [
                        'id' => 'fetch_booking', 'type' => 'fetch_source', 'source' => 'appointments_booking',
                        'label' => 'Read the booking from Appointments',
                        'next' => 'still_booked', 'on_unavailable' => 'pause_source',
                    ],
                    [
                        'id' => 'still_booked', 'type' => 'condition',
                        'label' => 'Is it still going ahead?',
                        'expression' => 'source.status in CONFIRMED,PENDING',
                        'on_true' => 'eligibility', 'on_false' => 'stop_cancelled',
                    ],
                    [
                        'id' => 'eligibility', 'type' => 'check_eligibility', 'purpose' => 'transactional',
                        'label' => 'Consent and channel',
                        'on_eligible' => 'draft', 'on_ineligible' => 'stop_ineligible',
                    ],
                    ['id' => 'draft', 'type' => 'draft', 'label' => 'Draft the reminder', 'template_uuid' => '', 'language' => 'en', 'next' => 'revalidate'],
                    [
                        'id' => 'revalidate', 'type' => 'revalidate', 'source' => 'appointments_booking',
                        'label' => 'Re-read the booking',
                        'expression' => 'source.status in CONFIRMED,PENDING',
                        'next' => 'send', 'on_changed' => 'stop_cancelled', 'on_unavailable' => 'pause_source',
                    ],
                    ['id' => 'send', 'type' => 'send', 'label' => 'Send', 'channel' => 'whatsapp', 'next' => 'stop_sent'],
                    ['id' => 'pause_source', 'type' => 'pause_notify', 'label' => 'Appointments unavailable — pause and notify'],
                    ['id' => 'stop_cancelled', 'type' => 'stop', 'label' => 'No longer going ahead', 'outcome' => 'appointment_cancelled'],
                    ['id' => 'stop_ineligible', 'type' => 'stop', 'label' => 'Not eligible', 'outcome' => 'no_consent'],
                    ['id' => 'stop_sent', 'type' => 'stop', 'label' => 'Sent', 'outcome' => 'sent'],
                ],
            ],
            'order_update' => [
                'name'        => 'Order update',
                'description' => 'Reads the order from Aicountly Sales and tells the customer where it is.',
                'entry'       => 'fetch_order',
                'nodes'       => [
                    [
                        'id' => 'fetch_order', 'type' => 'fetch_source', 'source' => 'sales_order',
                        'label' => 'Read the order from Sales',
                        'next' => 'eligibility', 'on_unavailable' => 'pause_source',
                    ],
                    [
                        'id' => 'eligibility', 'type' => 'check_eligibility', 'purpose' => 'transactional',
                        'label' => 'Consent and channel',
                        'on_eligible' => 'draft', 'on_ineligible' => 'stop_ineligible',
                    ],
                    ['id' => 'draft', 'type' => 'draft', 'label' => 'Draft the update', 'template_uuid' => '', 'language' => 'en', 'next' => 'revalidate'],
                    [
                        'id' => 'revalidate', 'type' => 'revalidate', 'source' => 'sales_order',
                        'label' => 'Re-read the order',
                        'expression' => 'source.status not in CANCELLED',
                        'next' => 'send', 'on_changed' => 'stop_cancelled', 'on_unavailable' => 'pause_source',
                    ],
                    ['id' => 'send', 'type' => 'send', 'label' => 'Send', 'channel' => 'whatsapp', 'next' => 'stop_sent'],
                    ['id' => 'pause_source', 'type' => 'pause_notify', 'label' => 'Sales unavailable — pause and notify'],
                    ['id' => 'stop_cancelled', 'type' => 'stop', 'label' => 'Order cancelled', 'outcome' => 'order_cancelled'],
                    ['id' => 'stop_ineligible', 'type' => 'stop', 'label' => 'Not eligible', 'outcome' => 'no_consent'],
                    ['id' => 'stop_sent', 'type' => 'stop', 'label' => 'Sent', 'outcome' => 'sent'],
                ],
            ],
            'unanswered_enquiry_followup' => [
                'name'        => 'Unanswered enquiry follow-up',
                'description' => 'Follows up a conversation nobody has replied to. Reads only Messaging\'s own data.',
                'entry'       => 'fetch_conversation',
                'nodes'       => [
                    [
                        'id' => 'fetch_conversation', 'type' => 'fetch_source', 'source' => 'messaging_conversation',
                        'label' => 'Read the conversation', 'next' => 'still_unanswered',
                    ],
                    [
                        'id' => 'still_unanswered', 'type' => 'condition',
                        'label' => 'Still unanswered?',
                        'expression' => 'source.awaiting_reply == true',
                        'on_true' => 'wait', 'on_false' => 'stop_answered',
                    ],
                    ['id' => 'wait', 'type' => 'delay', 'label' => 'Wait', 'delay_minutes' => 240, 'next' => 'eligibility'],
                    [
                        'id' => 'eligibility', 'type' => 'check_eligibility', 'purpose' => 'service',
                        'label' => 'Consent and channel',
                        'on_eligible' => 'draft', 'on_ineligible' => 'stop_ineligible',
                    ],
                    ['id' => 'draft', 'type' => 'draft', 'label' => 'Draft the follow-up', 'template_uuid' => '', 'language' => 'en', 'next' => 'revalidate'],
                    [
                        'id' => 'revalidate', 'type' => 'revalidate', 'source' => 'messaging_conversation',
                        'label' => 'Check nobody has replied',
                        'expression' => 'source.awaiting_reply == true',
                        'next' => 'send', 'on_changed' => 'stop_answered',
                    ],
                    ['id' => 'send', 'type' => 'send', 'label' => 'Send', 'channel' => 'whatsapp', 'next' => 'stop_sent'],
                    ['id' => 'stop_answered', 'type' => 'stop', 'label' => 'Somebody replied — nothing sent', 'outcome' => 'answered'],
                    ['id' => 'stop_ineligible', 'type' => 'stop', 'label' => 'Not eligible', 'outcome' => 'no_consent'],
                    ['id' => 'stop_sent', 'type' => 'stop', 'label' => 'Sent', 'outcome' => 'sent'],
                ],
            ],
        ];
    }
}
