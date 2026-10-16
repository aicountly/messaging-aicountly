-- Company lifecycle (lib/company-lifecycle): the stored objects a purge removes OUTSIDE the database.
--
-- When Manage's company_purge_requested / account_purge_requested is carried out, the kit archives
-- and deletes the rows; the product hook (src/Lifecycle/ObjectSweep.php) then reads the purged rows
-- back from the purge's archive schema, records every stored object they reference here (an
-- attachment file, a Drive document, a connected account's token — a pointer to its archived row,
-- never the token), and deletes it, retrying a failure. Each row is the audit record of one removal:
-- what, for which company / person, how many attempts, the last error, when it was done.
--
-- target_kind / target_ref name the company or person on purpose — NOT cmp_id / uuid — so the purge
-- manifest's strict scan never mistakes the record of a purge for company data, and the record
-- survives the purge it records (the same rule as the kit's aic_lifecycle_* tables).
CREATE TABLE IF NOT EXISTS lifecycle_object_deletions (
    id            BIGSERIAL    PRIMARY KEY,
    -- company (company_purge_requested) | account (account_purge_requested)
    target_kind   VARCHAR(10)  NOT NULL CHECK (target_kind IN ('company', 'account')),
    -- the cmp_id or the portal uuid, as text
    target_ref    VARCHAR(64)  NOT NULL,
    -- aic_lifecycle_archive_… schema the object was found in
    archive_name  VARCHAR(63)  NULL,
    source_table  VARCHAR(63)  NOT NULL,
    -- which store holds it (local, drive, s3, oauth_google, …) and its key there
    store         VARCHAR(32)  NOT NULL,
    object_key    TEXT         NOT NULL,
    -- pending (to remove / retrying) | deleted | failed (given up by an operator)
    status        VARCHAR(10)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'deleted', 'failed')),
    attempts      INTEGER      NOT NULL DEFAULT 0,
    last_error    TEXT         NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    finished_at   TIMESTAMPTZ  NULL,
    UNIQUE (target_kind, target_ref, store, object_key)
);
CREATE INDEX IF NOT EXISTS lifecycle_object_deletions_pending_idx
    ON lifecycle_object_deletions (target_kind, target_ref) WHERE status = 'pending';
