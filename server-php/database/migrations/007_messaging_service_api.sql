-- ---------------------------------------------------------------------------
-- The cross-product service API: what a calling product's request means, kept
-- with the message it produced.
--
-- Additive only. Every new column is NULL for the rows that already exist, and
-- the widened CHECK accepts every value the old one did.
--
-- Why each piece exists (docs/APPOINTMENTS_MESSAGING_CONTRACT.md):
--
--   * service_app / service_kind / service_reference / service_reference_label
--     say WHICH product asked, for WHAT (confirmation, reminder, ...) and
--     ABOUT WHICH of its records. service_app is proven by the service key and
--     is what a status read is scoped to, so one product's key cannot read
--     another product's messages even when both map to the same `origin`.
--   * scheduled_for is when the caller meant it to go; not_after is the last
--     moment it is still worth delivering. A reminder that would arrive after
--     the appointment started is cancelled as 'expired', never sent.
--   * The consent evidence source `service_booking` is a consent a calling
--     product recorded at booking time (with its own audit trail), as opposed
--     to one an agent typed into the inbox.
-- ---------------------------------------------------------------------------

ALTER TABLE messaging_messages
    ADD COLUMN IF NOT EXISTS service_app             VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS service_kind            VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS service_reference       TEXT        NULL,
    ADD COLUMN IF NOT EXISTS service_reference_label TEXT        NULL,
    ADD COLUMN IF NOT EXISTS scheduled_for           TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS not_after               TIMESTAMPTZ NULL;

-- Rate limiting and status reads are both "this product's messages in this
-- company, newest first".
CREATE INDEX IF NOT EXISTS ix_messaging_msg_service
    ON messaging_messages (cmp_id, service_app, created_at DESC)
    WHERE service_app IS NOT NULL;

-- "Everything this product sent about that record" — a booking's notices.
CREATE INDEX IF NOT EXISTS ix_messaging_msg_service_reference
    ON messaging_messages (cmp_id, service_app, service_reference)
    WHERE service_reference IS NOT NULL;

ALTER TABLE messaging_consent_records DROP CONSTRAINT IF EXISTS messaging_consent_records_evidence_source_check;
ALTER TABLE messaging_consent_records ADD CONSTRAINT messaging_consent_records_evidence_source_check
    CHECK (evidence_source IN ('', 'customer_message', 'web_form', 'checkout',
                               'agent_recorded', 'import', 'provider_optin',
                               'double_optin', 'contract', 'service_booking'));
