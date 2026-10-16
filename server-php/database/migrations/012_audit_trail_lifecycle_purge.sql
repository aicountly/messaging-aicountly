-- 012: the append-only audit trail (009_messaging_audit_trail, messaging_audit_events) meets the
-- company-lifecycle purge (009_aic_company_lifecycle, 010_lifecycle_object_deletions,
-- 011_aic_company_lifecycle_1_1).
--
-- 009_messaging_audit_trail refuses every UPDATE and DELETE on messaging_audit_events unless the
-- session set `messaging.audit_purge = on` (the test-suite reset; unchanged here). The lifecycle
-- manifest (config/lifecycle-manifest.php) retains a purged company's trail for CERT-In's 180 days
-- and must still be able to do the two things it promises with it, without that all-powerful flag:
--
--   * scrub: at purge time the retained rows' before_state / after_state (copies of the purged
--     Messaging rows — a conversation's customer address, a template's text, a suppression's number)
--     become "[redacted]". The kit's engine cannot set anything in its purge transaction, so
--     src/Lifecycle/CompanyLifecycle.php (auditScrubHook, a company_before hook) does it: the rows are
--     archived first ($ctx->archiveRows) and redacted in the hook's own transaction with
--     `SET LOCAL aicountly.lifecycle_purge = on`; the engine's own scrub then finds nothing left to
--     change. A --restore puts the archived copies back under the same setting
--     (expiry_session_settings).
--   * expiry: the daily `--expire-retained` deletes those rows once 180 days old, with the manifest's
--     expiry_session_settings (`aicountly.lifecycle_purge = on`) SET LOCAL in its own transaction.
--
-- So, under aicountly.lifecycle_purge only: DELETE is allowed, and an UPDATE that changes nothing but
-- before_state / after_state. Everything else stays refused; nothing in the application sets the
-- setting (it is SET LOCAL by the lifecycle worker only). TRUNCATE is still not covered by these
-- triggers (009).
--
-- bin/migrate.php has no down migrations. To undo, re-create the function exactly as
-- 009_messaging_audit_trail.sql does. Idempotent: safe to apply twice.

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
           AND (to_jsonb(NEW) - ARRAY['before_state', 'after_state'])
             = (to_jsonb(OLD) - ARRAY['before_state', 'after_state']) THEN
            RETURN NEW;
        END IF;
    END IF;
    RAISE EXCEPTION 'messaging_audit_events is append-only (% refused)', TG_OP
        USING ERRCODE = 'restrict_violation';
END;
$fn$ LANGUAGE plpgsql;
