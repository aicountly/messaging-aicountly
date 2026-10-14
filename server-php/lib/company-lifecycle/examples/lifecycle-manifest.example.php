<?php
// Example purge manifest (a reviewed one: no 'draft'). Start from bin/discover-manifest.php's draft,
// review every line, keep it next to the product's code (e.g. server-php/config/lifecycle-manifest.php)
// and change it in the same commit as any migration that adds a company or personal table — strict mode
// refuses to purge while a table with one of these key columns is missing from the manifest.

return [
    'app_key' => 'example',
    'schema'  => 'public',
    'strict'  => true,

    // Deleted on company_purge_requested, in THIS order: children before parents.
    'company_tables' => [
        // A child with no company column: rows whose invoice_id points at this company's invoices.
        ['table' => 'invoice_lines', 'via' => ['column' => 'invoice_id', 'parent' => 'invoices', 'parent_column' => 'id']],
        ['table' => 'invoices', 'key' => 'cmp_id'],
        ['table' => 'customers', 'key' => 'cmp_id'],
        ['table' => 'company_settings', 'key' => 'cmp_id'],
    ],
    // Kept on a company purge — the law requires it. The reason goes to Manage and ops.
    'company_retain' => [
        ['table' => 'audit_log', 'key' => 'cmp_id', 'reason' => 'Companies Act 2013 s.128(5) and Rule 3(1) of the Companies (Accounts) Rules: audit trail kept 8 years'],
    ],

    // Deleted on account_purge_requested: the person's own data that is not company data.
    'user_tables' => [
        ['table' => 'user_device_tokens', 'key' => 'uuid_aictly'],
        ['table' => 'user_preferences', 'key' => 'uuid_aictly'],
        // Personal notes only: notes written inside a company stay with the company.
        ['table' => 'notes', 'key' => 'owner_uuid', 'only_if_null' => 'cmp_id'],
        ['table' => 'ai_conversations', 'key' => 'user_uuid'],
    ],
    'user_retain' => [
        ['table' => 'sign_in_events', 'key' => 'uuid_aictly', 'reason' => 'CERT-In Directions 70B (28.04.2022): security logs kept 180 days'],
    ],

    // Found by a key-column scan but neither company nor personal data.
    'ignore' => [
        ['table' => 'gst_rate_master', 'reason' => 'reference data shared by every company (company_id is the publisher, not an owner)'],
    ],
];
