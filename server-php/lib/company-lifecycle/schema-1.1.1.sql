-- AICOUNTLY company-lifecycle receiver kit 1.1.1 — additive migration on top of schema-1.1.sql.
--
-- Copy this file into the product's migration chain unchanged, as the next number after the 1.1 schema
-- (e.g. NNN_aic_company_lifecycle_1_1_1.sql). Idempotent and additive: it touches no product table.
--
--   aic_lifecycle_expiries  retention groups (1.1.1): a retained row with a period expires together with
--                           the rows that reference it by foreign key. One row per root table and member
--                           table of an expiry run:
--                             reason       expired_with_group — a member without a period of its own
--                                          (retention_ended stays for tables with their own period)
--                             group_id     "<archive schema>:<root table>" — the run's groups of that root
--                             group_root   the root table ("schema.table")
--                             group_count  on the root table's own row: how many groups (root rows) expired
--
-- Optional: without it 1.1.1 still expires groups and records every table as retention_ended (no group
-- columns).

ALTER TABLE aic_lifecycle_expiries ADD COLUMN IF NOT EXISTS group_id VARCHAR(200) NULL;
ALTER TABLE aic_lifecycle_expiries ADD COLUMN IF NOT EXISTS group_root VARCHAR(130) NULL;
ALTER TABLE aic_lifecycle_expiries ADD COLUMN IF NOT EXISTS group_count INTEGER NULL;
ALTER TABLE aic_lifecycle_expiries DROP CONSTRAINT IF EXISTS aic_lifecycle_expiries_reason_chk;
ALTER TABLE aic_lifecycle_expiries ADD CONSTRAINT aic_lifecycle_expiries_reason_chk
    CHECK (reason IN ('retention_ended', 'no_longer_referenced', 'expired_with_group'));
CREATE INDEX IF NOT EXISTS aic_lifecycle_expiries_group_idx ON aic_lifecycle_expiries (group_id) WHERE group_id IS NOT NULL;
