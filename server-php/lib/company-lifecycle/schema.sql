-- AICOUNTLY company-lifecycle receiver kit — tables (PostgreSQL 12+). Kit version: see VERSION.
--
-- Copy this file into the product's migration chain unchanged (e.g. NNN_aic_company_lifecycle.sql).
-- Idempotent: every statement is IF NOT EXISTS, so a re-run deploy is harmless. Additive: touches no
-- product table.
--
--   aic_lifecycle_events  every signed event Manage sent, once (event_id is the idempotency key)
--   aic_lifecycle_state   the company's lifecycle here; ReadOnlyGuard refuses writes (423
--                         COMPANY_RESTRICTED) while it is restricted / purge_pending / purged
--   aic_lifecycle_jobs    the purge queue (company_purge_requested, account_purge_requested): the
--                         worker runs PurgeEngine (archive first, statutory tables retained)
--   aic_lifecycle_acks    the signed answer owed to Manage for each finished job, retried with
--                         back-off until Manage takes it
--
-- The company / person is named in target_cmp_id / target_uuid on purpose — NOT cmp_id, company_id or
-- uuid — so ManifestDiscovery, the product's own orphan finders and any "every table with a cmp_id"
-- scan never mistake the record of a purge for company data, and the record survives the purge it
-- records. PurgeEngine also refuses any aic_lifecycle_* table outright.
--
-- No product uses MySQL for its API database (surveyed 2026-10: every product backend is PostgreSQL;
-- only my.aicountly, the account authority, is MySQL and it does not receive these events), so there is
-- no MySQL variant. Add one here, with PurgeEngine support, before a MySQL product integrates.

CREATE TABLE IF NOT EXISTS aic_lifecycle_events (
    event_id          UUID          PRIMARY KEY,
    -- company_restricted | company_unrestricted | company_purge_requested | account_purge_requested
    event             VARCHAR(40)   NOT NULL,
    target_cmp_id     BIGINT        NULL,
    target_uuid       VARCHAR(64)   NULL,
    app_key           VARCHAR(32)   NOT NULL,
    reason            VARCHAR(40)   NULL,
    occurred_at       TIMESTAMPTZ   NOT NULL,
    restricted_until  TIMESTAMPTZ   NULL,
    -- the exact signed bytes
    body              TEXT          NOT NULL,
    -- received | applied | stale (older than the state already recorded) | queued (purge job created)
    outcome           VARCHAR(20)   NOT NULL DEFAULT 'received',
    received_at       TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT aic_lifecycle_events_target_chk CHECK (
        (event = 'account_purge_requested' AND target_uuid IS NOT NULL AND target_cmp_id IS NULL)
        OR (event <> 'account_purge_requested' AND target_cmp_id IS NOT NULL AND target_uuid IS NULL)
    )
);
CREATE INDEX IF NOT EXISTS aic_lifecycle_events_cmp_idx ON aic_lifecycle_events (target_cmp_id, received_at);
CREATE INDEX IF NOT EXISTS aic_lifecycle_events_uuid_idx ON aic_lifecycle_events (target_uuid) WHERE target_uuid IS NOT NULL;

CREATE TABLE IF NOT EXISTS aic_lifecycle_state (
    target_cmp_id     BIGINT        PRIMARY KEY,
    -- active | restricted | purge_pending | purged
    state             VARCHAR(20)   NOT NULL DEFAULT 'active',
    restricted_until  TIMESTAMPTZ   NULL,
    reason            VARCHAR(40)   NULL,
    -- event (a signed event) | companyinfo (Manage's companyinfo, the source of truth) | worker
    source            VARCHAR(20)   NOT NULL DEFAULT 'event',
    last_event_id     UUID          NULL,
    -- occurred_at of the newest event applied: an older event delivered late cannot undo a newer one
    last_event_at     TIMESTAMPTZ   NULL,
    updated_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT aic_lifecycle_state_chk CHECK (state IN ('active', 'restricted', 'purge_pending', 'purged'))
);

CREATE TABLE IF NOT EXISTS aic_lifecycle_jobs (
    id                BIGSERIAL     PRIMARY KEY,
    event_id          UUID          NOT NULL UNIQUE REFERENCES aic_lifecycle_events (event_id),
    -- company (company_purge_requested) | account (account_purge_requested)
    kind              VARCHAR(10)   NOT NULL,
    target_cmp_id     BIGINT        NULL,
    target_uuid       VARCHAR(64)   NULL,
    -- pending | running | purged | nothing_to_purge | failed
    status            VARCHAR(20)   NOT NULL DEFAULT 'pending',
    attempts          INTEGER       NOT NULL DEFAULT 0,
    next_attempt_at   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    locked_at         TIMESTAMPTZ   NULL,
    started_at        TIMESTAMPTZ   NULL,
    finished_at       TIMESTAMPTZ   NULL,
    archive_name      VARCHAR(63)   NULL,
    rows_purged       BIGINT        NULL,
    retained          JSONB         NOT NULL DEFAULT '[]'::jsonb,
    detail            TEXT          NULL,
    created_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT aic_lifecycle_jobs_kind_chk CHECK (
        (kind = 'company' AND target_cmp_id IS NOT NULL AND target_uuid IS NULL)
        OR (kind = 'account' AND target_uuid IS NOT NULL AND target_cmp_id IS NULL)
    ),
    CONSTRAINT aic_lifecycle_jobs_status_chk CHECK (status IN ('pending', 'running', 'purged', 'nothing_to_purge', 'failed'))
);
CREATE INDEX IF NOT EXISTS aic_lifecycle_jobs_due_idx ON aic_lifecycle_jobs (status, next_attempt_at);

CREATE TABLE IF NOT EXISTS aic_lifecycle_acks (
    id                BIGSERIAL     PRIMARY KEY,
    job_id            BIGINT        NOT NULL REFERENCES aic_lifecycle_jobs (id),
    event_id          UUID          NOT NULL,
    -- the exact JSON sent (a retry sends the same body with a fresh timestamp)
    body              TEXT          NOT NULL,
    -- pending | sent | failed (gave up, or Manage refused it for good)
    status            VARCHAR(10)   NOT NULL DEFAULT 'pending',
    attempts          INTEGER       NOT NULL DEFAULT 0,
    next_attempt_at   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    last_http         INTEGER       NULL,
    last_error        TEXT          NULL,
    sent_at           TIMESTAMPTZ   NULL,
    created_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT aic_lifecycle_acks_status_chk CHECK (status IN ('pending', 'sent', 'failed'))
);
-- One open ack per job; a requeued job (bin/worker.php --requeue) supersedes the old row.
CREATE UNIQUE INDEX IF NOT EXISTS aic_lifecycle_acks_open_uq ON aic_lifecycle_acks (job_id) WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS aic_lifecycle_acks_due_idx ON aic_lifecycle_acks (status, next_attempt_at);
