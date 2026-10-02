-- 008: a customer who writes in may be answered (G19#11).
--
-- Inbound messages record a `service` grant with evidence `customer_wrote_in`.
-- It is its own evidence value so that ConsentService::evaluate can let it
-- satisfy a SERVICE reply only — never a transactional send (invoice,
-- reminder) or marketing, which need consent somebody recorded. It is not
-- offered to people recording consent by hand.
ALTER TABLE messaging_consent_records
    DROP CONSTRAINT IF EXISTS messaging_consent_records_evidence_source_check;
ALTER TABLE messaging_consent_records
    ADD CONSTRAINT messaging_consent_records_evidence_source_check
    CHECK (evidence_source IN ('', 'customer_message', 'web_form', 'checkout',
                               'agent_recorded', 'import', 'provider_optin',
                               'double_optin', 'contract', 'customer_wrote_in'));
