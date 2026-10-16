-- 013: the company-lifecycle scrub of the retained audit trail also covers entity_label, summary and
-- meta_json.changes (owner's decision, 2026-10-16: "add changes, entity_label and summary to the scrub").
--
-- 012_audit_trail_lifecycle_purge.sql let the lifecycle worker (SET LOCAL aicountly.lifecycle_purge = on)
-- DELETE a purged company's messaging_audit_events rows (the CERT-In expiry) and UPDATE nothing but
-- before_state / after_state (the scrub). But the trail's readable columns from 009_messaging_audit_trail
-- carry the same purged content: entity_label (a conversation's customer address, a template's name),
-- summary (the one-line sentence built from that label) and meta_json.changes ([{field, label, old, new}]:
-- the old and new values of every field an update touched). config/lifecycle-manifest.php now scrubs those
-- too — entity_label / summary `redact` ('[redacted]', TEXT), meta_json `json_paths:[changes]` (the key's
-- value becomes "[redacted]"; every other meta_json key stays) — and CompanyLifecycle::auditScrubHook writes
-- the same values (archived first, in its own transaction under the setting).
--
-- So, under aicountly.lifecycle_purge only, an UPDATE may now change exactly: before_state, after_state,
-- entity_label, summary, and meta_json in its `changes` key (every other key of meta_json, and meta_json
-- being NULL or not, must stay as it was). Everything else is unchanged from 012: DELETE is allowed under
-- the setting; any other UPDATE or DELETE raises; the messaging.audit_purge branch (the test-suite reset,
-- 009) is kept verbatim; no trigger is dropped or re-created (both triggers of 009 call this function);
-- TRUNCATE is still not covered by these triggers (009). Nothing in the application sets the setting.
--
-- bin/migrate.php has no down migrations and is forward-only. To go back to scrubbing only before_state /
-- after_state, add a new migration that re-creates the function exactly as 012_audit_trail_lifecycle_purge.sql
-- does (and take entity_label / summary / meta_json out of the manifest's scrub and AUDIT_SCRUBBED in the same
-- change, or the hook's UPDATE will be refused). Idempotent: safe to apply twice.

CREATE OR REPLACE FUNCTION messaging_audit_events_immutable() RETURNS trigger AS $fn$
BEGIN
    IF COALESCE(current_setting('messaging.audit_purge', true), '') = 'on' THEN
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        RETURN NEW;
    END IF;
    IF current_setting('aicountly.lifecycle_purge', true) = 'on' THEN
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        IF TG_OP = 'UPDATE'
           AND (to_jsonb(NEW) - ARRAY['before_state', 'after_state', 'entity_label', 'summary', 'meta_json'])
             = (to_jsonb(OLD) - ARRAY['before_state', 'after_state', 'entity_label', 'summary', 'meta_json'])
           AND (CASE WHEN jsonb_typeof(NEW.meta_json) = 'object' THEN NEW.meta_json - 'changes' ELSE NEW.meta_json END)
               IS NOT DISTINCT FROM
               (CASE WHEN jsonb_typeof(OLD.meta_json) = 'object' THEN OLD.meta_json - 'changes' ELSE OLD.meta_json END) THEN
            RETURN NEW;
        END IF;
    END IF;
    RAISE EXCEPTION 'messaging_audit_events is append-only (% refused)', TG_OP
        USING ERRCODE = 'restrict_violation';
END;
$fn$ LANGUAGE plpgsql;
