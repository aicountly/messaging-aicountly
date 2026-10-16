-- 009: audit trail to the AICOUNTLY standard (extends messaging_audit_events from 001; no second table).
--
-- Adds what a reader of the trail needs without opening the record: a readable entity label and
-- one-line summary, a cached actor label, a `changes` list ([{field,label,old,new}]) in meta_json,
-- the request id and user agent, and a severity. `before_state` / `after_state` stay the snapshots.
-- Message bodies, credentials and full recipient addresses are never written (see src/Audit.php).
ALTER TABLE messaging_audit_events
    ADD COLUMN IF NOT EXISTS actor_label  TEXT         NULL,
    ADD COLUMN IF NOT EXISTS entity_label TEXT         NULL,
    ADD COLUMN IF NOT EXISTS summary      TEXT         NULL,
    ADD COLUMN IF NOT EXISTS meta_json    JSONB        NULL,
    ADD COLUMN IF NOT EXISTS request_id   VARCHAR(64)  NULL,
    ADD COLUMN IF NOT EXISTS user_agent   VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS severity     VARCHAR(16)  NOT NULL DEFAULT 'info';

ALTER TABLE messaging_audit_events
    DROP CONSTRAINT IF EXISTS messaging_audit_events_severity_check;
ALTER TABLE messaging_audit_events
    ADD CONSTRAINT messaging_audit_events_severity_check
    CHECK (severity IN ('info', 'material', 'security'));

-- ix_messaging_audit_scope (cmp_id, created_at DESC) and ix_messaging_audit_entity (cmp_id, entity_type,
-- entity_id) exist from 001; the entity one is replaced by the ordered form.
DROP INDEX IF EXISTS ix_messaging_audit_entity;
CREATE INDEX IF NOT EXISTS ix_messaging_audit_entity_time
    ON messaging_audit_events (cmp_id, entity_type, entity_id, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_messaging_audit_action_time
    ON messaging_audit_events (cmp_id, action, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_messaging_audit_actor_time
    ON messaging_audit_events (cmp_id, actor_uuid, created_at DESC);

-- Append-only. UPDATE and DELETE raise unless the session has said, explicitly, that it is a purge:
-- `SET messaging.audit_purge = 'on'`. The ONLY users of that flag are the test-suite reset and a
-- documented company purge / retention job; application code never sets it. (TRUNCATE is not a row
-- operation and is not covered by these triggers.)
CREATE OR REPLACE FUNCTION messaging_audit_events_immutable() RETURNS trigger AS $fn$
BEGIN
    IF COALESCE(current_setting('messaging.audit_purge', true), '') = 'on' THEN
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'messaging_audit_events is append-only (% refused)', TG_OP
        USING ERRCODE = 'restrict_violation';
END;
$fn$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_messaging_audit_events_no_update ON messaging_audit_events;
CREATE TRIGGER trg_messaging_audit_events_no_update
    BEFORE UPDATE ON messaging_audit_events
    FOR EACH ROW EXECUTE FUNCTION messaging_audit_events_immutable();

DROP TRIGGER IF EXISTS trg_messaging_audit_events_no_delete ON messaging_audit_events;
CREATE TRIGGER trg_messaging_audit_events_no_delete
    BEFORE DELETE ON messaging_audit_events
    FOR EACH ROW EXECUTE FUNCTION messaging_audit_events_immutable();
