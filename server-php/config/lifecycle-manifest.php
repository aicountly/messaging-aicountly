<?php

declare(strict_types=1);

/**
 * Messaging's reviewed purge manifest (lib/company-lifecycle, README § Manifest review).
 *
 * Drafted with bin/discover-manifest.php from a freshly migrated database (migrations 001–010) and
 * reviewed line by line on 2026-10-14; kit 1.1 retention period added 2026-10-16 (migration 011); the
 * append-only audit trail from main let through to the expiry and the scrub by migration 012 (2026-10-16); the
 * scrub widened to entity_label, summary and meta_json.changes by the owner's decision, migration 013
 * (2026-10-16). Messaging holds a company's customer conversations, messages,
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
        // holds actors, actions and references, not message content (src/Audit.php). EXPIRY (kit 1.1):
        // the daily `bin/worker.php --expire-retained` deletes a purged company's rows 180 days after
        // created_at (archived first, recorded in aic_lifecycle_expiries). Messaging has no sweep of its
        // own on this table, so there is nothing to race; a live company's audit trail is not expired
        // by anything (docs/DEPLOYMENT.md § Company lifecycle). The table is append-only
        // (009_messaging_audit_trail); migrations 012_audit_trail_lifecycle_purge.sql and
        // 013_audit_trail_lifecycle_scrub_labels.sql let exactly this through, under SET LOCAL
        // aicountly.lifecycle_purge = on: the expiry's DELETE (expiry_session_settings below) and the
        // scrub's UPDATE of before_state / after_state / entity_label / summary / meta_json.changes (no
        // other column, no other meta_json key), which CompanyLifecycle::auditScrubHook (company_before,
        // archived first) does because the engine's own scrub runs in a transaction nothing may mark as
        // the purge.
        ['table' => 'messaging_audit_events', 'key' => 'cmp_id',
            'reason' => 'CERT-In Directions under s.70B(6) IT Act 2000 (28.04.2022), direction (iv): ICT system and security logs kept 180 days; deleted after 180 days (append-only: expired and scrubbed only under aicountly.lifecycle_purge, migrations 012_audit_trail_lifecycle_purge.sql and 013_audit_trail_lifecycle_scrub_labels.sql)',
            'retain_days' => 180, 'retain_from' => 'created_at'],
    ],

    // Kit 1.1 scrub: the retained audit rows keep who did what, to which record (entity_type / entity_id),
    // when and from where — what CERT-In asks a log to answer — but not the purged rows' contents.
    // before_state / after_state are copies of Messaging rows (a conversation's customer address, a
    // template's text, a suppression's number); entity_label and summary name the record in words built
    // from the same data (a customer address, a template name); meta_json.changes ([{field, label, old,
    // new}]) holds the old and new value of every field an update changed. Keeping them for 180 days would
    // keep the customer data the purge deleted (entity_label, summary and changes added by the owner's
    // decision, 2026-10-16, migration 013). before_state / after_state (JSONB) become "[redacted]",
    // entity_label / summary (TEXT, nullable) '[redacted]', and meta_json (JSONB) keeps every key but
    // `changes`, whose value becomes "[redacted]" (json_paths; a row without the key is left alone).
    // Redacted in place when the company is purged — by CompanyLifecycle::auditScrubHook, which writes these
    // same values (archived first; AUDIT_SCRUBBED / AUDIT_SCRUBBED_JSON, tests/lifecycle.php keeps them in
    // step), so the engine's own scrub finds nothing left — and by the kit in every archive copy (the hook's,
    // and the one --expire-retained makes before it deletes the rows).
    'scrub' => [
        'messaging_audit_events.before_state' => 'redact',
        'messaging_audit_events.after_state'  => 'redact',
        'messaging_audit_events.entity_label' => 'redact',
        'messaging_audit_events.summary'      => 'redact',
        'messaging_audit_events.meta_json'    => 'json_paths:[changes]',
    ],

    // Kit 1.1.1: SET LOCAL inside the expiry transaction (and a restore's) only. messaging_audit_events is
    // append-only; migrations 012_audit_trail_lifecycle_purge.sql and 013_audit_trail_lifecycle_scrub_labels.sql
    // let a DELETE through under this setting (the expiry) and an UPDATE of the scrubbed fields only —
    // before_state / after_state / entity_label / summary / meta_json.changes — (the scrub, and a restore
    // putting the archived rows back). Nothing in the application sets it.
    'expiry_session_settings' => ['aicountly.lifecycle_purge' => 'on'],

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
