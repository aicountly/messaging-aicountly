-- AICOUNTLY company-lifecycle receiver kit 1.1 — additive migration on top of schema.sql (1.0).
--
-- Copy this file into the product's migration chain unchanged, as the next number after the 1.0
-- schema (e.g. NNN_aic_company_lifecycle_1_1.sql). Idempotent and additive: it touches no product
-- table and no 1.0 row. A product integrating fresh applies schema.sql, then this file.
--
--   aic_lifecycle_jobs     status may now be `held` (a product legalHold() hook stopped the purge;
--                          the worker looks at the job again daily and acks Manage `held`, then
--                          `purged` once the hold lifts)
--   aic_lifecycle_expiries one row per table and expiry run of `bin/worker.php --expire-retained`:
--                          retained rows of a purged company / person deleted (archived first) when
--                          their retain_from + retain_years / retain_days passed (reason
--                          retention_ended), and rows of retain_referenced tables nothing references
--                          any more (no_longer_referenced). Manage is not told — the company or account
--                          is already gone there. Like the other kit tables it names the target in
--                          target_cmp_id / target_uuid so no "every table with a cmp_id" scan takes it
--                          for company data.
--
-- The 1.0 code paths never need this file; legal hold and --expire-retained do.

ALTER TABLE aic_lifecycle_jobs DROP CONSTRAINT IF EXISTS aic_lifecycle_jobs_status_chk;
ALTER TABLE aic_lifecycle_jobs ADD CONSTRAINT aic_lifecycle_jobs_status_chk
    CHECK (status IN ('pending', 'running', 'purged', 'nothing_to_purge', 'failed', 'held'));

CREATE TABLE IF NOT EXISTS aic_lifecycle_expiries (
    id                BIGSERIAL     PRIMARY KEY,
    -- company | account (as aic_lifecycle_jobs.kind)
    kind              VARCHAR(10)   NOT NULL,
    target_cmp_id     BIGINT        NULL,
    target_uuid       VARCHAR(64)   NULL,
    -- "schema.table"
    source_table      VARCHAR(130)  NOT NULL,
    rows_deleted      BIGINT        NOT NULL,
    -- retention_ended | no_longer_referenced
    reason            VARCHAR(24)   NOT NULL,
    retain_years      INTEGER       NULL,
    retain_days       INTEGER       NULL,
    retain_from       VARCHAR(63)   NULL,
    -- the archive schema the rows went to (dropped after the archive window like any other)
    archive_name      VARCHAR(63)   NULL,
    expired_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT aic_lifecycle_expiries_kind_chk CHECK (
        (kind = 'company' AND target_cmp_id IS NOT NULL AND target_uuid IS NULL)
        OR (kind = 'account' AND target_uuid IS NOT NULL AND target_cmp_id IS NULL)
    ),
    CONSTRAINT aic_lifecycle_expiries_reason_chk CHECK (reason IN ('retention_ended', 'no_longer_referenced'))
);
CREATE INDEX IF NOT EXISTS aic_lifecycle_expiries_cmp_idx ON aic_lifecycle_expiries (target_cmp_id, expired_at) WHERE target_cmp_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS aic_lifecycle_expiries_uuid_idx ON aic_lifecycle_expiries (target_uuid, expired_at) WHERE target_uuid IS NOT NULL;
