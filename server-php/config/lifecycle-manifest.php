<?php

declare(strict_types=1);

/**
 * Messaging's reviewed purge manifest (lib/company-lifecycle, README § Manifest review).
 *
 * Drafted with bin/discover-manifest.php from a freshly migrated database (migrations 001–010) and
 * reviewed line by line on 2026-10-14. Messaging holds a company's customer conversations, messages,
 * attachments, templates, journeys and consent records — communications, kept by no statute on
 * AICOUNTLY's side — so a company purge deletes all of it. The one exception is the audit trail (see
 * company_retain). Attachment files and Drive documents the purged rows point at are removed by the
 * company hook (src/Lifecycle/CompanyLifecycle.php → ObjectSweep), channel credentials are never stored
 * here (credential_ref names a server/Console secret; the ack names them for ops).
 *
 * Strict mode: a migration that adds a table with cmp_id or user_uuid must add it here in the same
 * commit — tests/lifecycle.php fails until it does.
 */
return [
    'app_key' => 'messaging',
    'schema'  => 'public',
    'strict'  => true,

    // Deleted on company_purge_requested, children before parents (foreign keys).
    'company_tables' => [
        ['table' => 'messaging_ai_runs', 'key' => 'cmp_id'],
        ['table' => 'messaging_approvals', 'key' => 'cmp_id'],
        ['table' => 'messaging_channel_capabilities', 'key' => 'cmp_id'],
        ['table' => 'messaging_conversation_assignments', 'key' => 'cmp_id'],
        ['table' => 'messaging_conversation_labels', 'key' => 'cmp_id'],
        ['table' => 'messaging_internal_notes', 'key' => 'cmp_id'],
        ['table' => 'messaging_dispatch_jobs', 'key' => 'cmp_id'],
        ['table' => 'messaging_journey_step_runs', 'key' => 'cmp_id'],
        ['table' => 'messaging_outcome_links', 'key' => 'cmp_id'],
        ['table' => 'messaging_journey_runs', 'key' => 'cmp_id'],
        ['table' => 'messaging_consent_events', 'key' => 'cmp_id'],
        ['table' => 'messaging_delivery_events', 'key' => 'cmp_id'],
        // storage_ref → attachment file / Drive document: removed by the company hook after the commit
        ['table' => 'messaging_message_attachments', 'key' => 'cmp_id'],
        ['table' => 'messaging_messages', 'key' => 'cmp_id'],
        ['table' => 'messaging_conversations', 'key' => 'cmp_id'],
        ['table' => 'messaging_webhook_receipts', 'key' => 'cmp_id'],
        // WhatsApp / SMS / RCS senders: no secret here (credential_ref is a NAME), see the company hook
        ['table' => 'messaging_channel_connections', 'key' => 'cmp_id'],
        ['table' => 'messaging_consent_records', 'key' => 'cmp_id'],
        ['table' => 'messaging_daily_metrics', 'key' => 'cmp_id'],
        // per-person UI state inside the company (also in user_tables)
        ['table' => 'messaging_dismissed_suggestions', 'key' => 'cmp_id'],
        ['table' => 'messaging_external_references', 'key' => 'cmp_id'],
        ['table' => 'messaging_idempotency_keys', 'key' => 'cmp_id'],
        ['table' => 'messaging_journey_versions', 'key' => 'cmp_id'],
        ['table' => 'messaging_journeys', 'key' => 'cmp_id'],
        ['table' => 'messaging_labels', 'key' => 'cmp_id'],
        ['table' => 'messaging_permission_assignments', 'key' => 'cmp_id'],
        ['table' => 'messaging_permission_profiles', 'key' => 'cmp_id'],
        ['table' => 'messaging_settings', 'key' => 'cmp_id'],
        ['table' => 'messaging_suppressions', 'key' => 'cmp_id'],
        ['table' => 'messaging_template_versions', 'key' => 'cmp_id'],
        ['table' => 'messaging_templates', 'key' => 'cmp_id'],
    ],

    // Kept on a company purge, with the legal reason (reported to Manage in the ack).
    'company_retain' => [
        // Who did what in Messaging (sign-ins acted on, sends, approvals, exports, access changes). It
        // holds actors, actions and references, not message content (src/Audit.php). EXPIRY: it must be
        // deleted once 180 days old — there is no sweep in Messaging yet (follow-up, see
        // docs/DEPLOYMENT.md § Company lifecycle).
        ['table' => 'messaging_audit_events', 'key' => 'cmp_id',
            'reason' => 'CERT-In Directions under s.70B(6) IT Act 2000 (28.04.2022), direction (iv): ICT system and security logs kept 180 days; deleted after 180 days'],
    ],

    // Deleted on account_purge_requested: the person's own data that is not company data. Messaging has
    // no personal inbox, chat, file or device token outside companies; conversations, notes and
    // approvals a person authored inside a company stay with the company.
    'user_tables' => [
        // the person's own hidden Command Centre suggestions (UI state), in every company
        ['table' => 'messaging_dismissed_suggestions', 'key' => 'user_uuid'],
    ],

    'user_retain' => [],

    'ignore' => [
        ['table' => 'messaging_permission_assignments', 'reason' => 'user_uuid is the person a company administrator granted a profile to: company access administration, company data (purged with the company); the grant is inert once the account is closed'],
    ],
];
