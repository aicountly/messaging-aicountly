-- ---------------------------------------------------------------------------
-- Consent, suppression, dispatch and webhooks.
--
-- CONSENT IS CHECKED AT DISPATCH, NOT AT APPROVAL. An approval granted
-- yesterday does not override an opt-out received today, and the only way to
-- guarantee that is to read consent again in the moment before the provider
-- call. These tables are therefore on the hot path of every send, which is why
-- they are indexed the way they are.
--
-- Consent is per CHANNEL and per PURPOSE. "Yes, text me about my order" is not
-- "yes, text me about your sale". Collapsing those into one boolean is the most
-- common way a messaging product becomes a compliance problem.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_consent_records (
    consent_uuid      UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,

    -- Who. A Contacts reference where the person is known, and the transport
    -- address always — because consent attaches to the number that was opted
    -- in, and a contact's number can change.
    contact_uuid      TEXT         NULL,
    channel           VARCHAR(24)  NOT NULL,
    address           TEXT         NOT NULL,

    -- What for. 'transactional' covers order and appointment updates a customer
    -- is expecting; 'promotional' does not.
    purpose           VARCHAR(32)  NOT NULL
                      CHECK (purpose IN ('transactional', 'service', 'promotional', 'all')),

    state             VARCHAR(16)  NOT NULL
                      CHECK (state IN ('granted', 'withdrawn', 'pending')),

    -- The EVIDENCE. Where the consent came from, in words that mean something
    -- six months later in front of somebody asking.
    evidence_source   VARCHAR(48)  NOT NULL DEFAULT ''
                      CHECK (evidence_source IN ('', 'customer_message', 'web_form', 'checkout',
                                                 'agent_recorded', 'import', 'provider_optin',
                                                 'double_optin', 'contract')),
    evidence_detail   TEXT         NOT NULL DEFAULT '',
    -- When the customer did the thing, which is not when we recorded it.
    occurred_at       TIMESTAMPTZ  NULL,
    recorded_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    recorded_by_uuid  TEXT         NOT NULL DEFAULT '',

    row_version       INTEGER      NOT NULL DEFAULT 1
);

-- The dispatch-time lookup. One current record per address per channel per
-- purpose; history lives in messaging_consent_events.
CREATE UNIQUE INDEX IF NOT EXISTS uq_messaging_consent_current
    ON messaging_consent_records (cmp_id, channel, address, purpose);

CREATE INDEX IF NOT EXISTS ix_messaging_consent_contact
    ON messaging_consent_records (cmp_id, contact_uuid)
    WHERE contact_uuid IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Consent history. Append-only and immutable: this is the record that answers
-- "when did they opt out, and did we send anything after that?".
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_consent_events (
    consent_event_id  BIGSERIAL PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    consent_uuid      UUID         NULL
                      REFERENCES messaging_consent_records (consent_uuid) ON DELETE SET NULL,

    channel           VARCHAR(24)  NOT NULL,
    address           TEXT         NOT NULL,
    purpose           VARCHAR(32)  NOT NULL,

    event             VARCHAR(16)  NOT NULL
                      CHECK (event IN ('granted', 'withdrawn', 'reconfirmed', 'expired')),
    evidence_source   VARCHAR(48)  NOT NULL DEFAULT '',
    evidence_detail   TEXT         NOT NULL DEFAULT '',

    -- When a withdrawal came from an inbound STOP message, this is it.
    message_uuid      UUID         NULL
                      REFERENCES messaging_messages (message_uuid) ON DELETE SET NULL,

    actor_uuid        TEXT         NOT NULL DEFAULT '',
    actor_kind        VARCHAR(16)  NOT NULL DEFAULT 'user',
    occurred_at       TIMESTAMPTZ  NULL,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_messaging_consent_events_address
    ON messaging_consent_events (cmp_id, channel, address, created_at DESC);

-- ---------------------------------------------------------------------------
-- Suppression.
--
-- Separate from consent because the reasons are different and so are the
-- consequences. A hard bounce, a provider block, a spam complaint and a
-- customer's own STOP all stop a send, but only one of them is a consent
-- decision. Recording them in one table would make "how many people opted out?"
-- unanswerable.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_suppressions (
    suppression_uuid  UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,

    channel           VARCHAR(24)  NOT NULL,
    address           TEXT         NOT NULL,

    reason            VARCHAR(32)  NOT NULL
                      CHECK (reason IN ('customer_optout', 'hard_bounce', 'provider_block',
                                        'spam_complaint', 'invalid_address', 'manual',
                                        'regulatory')),
    detail            TEXT         NOT NULL DEFAULT '',

    -- NULL means indefinite. A provider block that clears after 24 hours is not
    -- the same as a customer who never wants to hear from you again.
    expires_at        TIMESTAMPTZ  NULL,

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by_uuid   TEXT         NOT NULL DEFAULT '',

    -- Lifting a suppression is an act with a name attached to it. It is never a
    -- delete: the row stays, and who released it stays with it.
    released_at       TIMESTAMPTZ  NULL,
    released_by_uuid  TEXT         NULL,
    release_reason    TEXT         NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_messaging_suppression_active
    ON messaging_suppressions (cmp_id, channel, address)
    WHERE released_at IS NULL;

CREATE INDEX IF NOT EXISTS ix_messaging_suppressions_scope
    ON messaging_suppressions (cmp_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- Dispatch jobs — the transactional queue.
--
-- A row here is claimed with `FOR UPDATE SKIP LOCKED`, which is what makes two
-- workers safe without a broker. The claim is the lock; the `attempts` counter
-- and `available_at` give the backoff.
--
-- WHAT A JOB MAY CONTAIN: our message uuid, our run uuid, references, the
-- approved content hash. WHAT IT MAY NOT: a mirrored foreign record. There is
-- no `payload` column holding an invoice, because a queue is storage and a job
-- that carries the invoice is a job that sends yesterday's balance when it is
-- retried tomorrow. The worker re-fetches.
--
-- The CHECK on `mode` is the structural half of "simulation sends nothing": a
-- simulation run cannot produce a dispatch job at all, so the guarantee does
-- not depend on an if-statement somebody might move.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_dispatch_jobs (
    job_uuid          UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,

    message_uuid      UUID         NOT NULL
                      REFERENCES messaging_messages (message_uuid) ON DELETE CASCADE,
    run_uuid          UUID         NULL
                      REFERENCES messaging_journey_runs (run_uuid) ON DELETE SET NULL,
    connection_uuid   UUID         NOT NULL
                      REFERENCES messaging_channel_connections (connection_uuid),

    mode              VARCHAR(16)  NOT NULL DEFAULT 'live'
                      CHECK (mode = 'live'),

    -- The hash that was approved. Compared against the message's current
    -- content at claim time; a mismatch cancels the job rather than sending.
    approved_content_hash TEXT     NOT NULL DEFAULT '',

    status            VARCHAR(16)  NOT NULL DEFAULT 'queued'
                      CHECK (status IN ('queued', 'claimed', 'sent', 'failed', 'cancelled', 'unknown')),

    attempts          INTEGER      NOT NULL DEFAULT 0,
    max_attempts      INTEGER      NOT NULL DEFAULT 5,
    available_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    claimed_at        TIMESTAMPTZ  NULL,
    claimed_by        TEXT         NULL,
    finished_at       TIMESTAMPTZ  NULL,

    last_error_code   VARCHAR(64)  NULL,
    last_error_detail TEXT         NULL,

    -- The key sent to the provider, so a retry replays rather than duplicates.
    -- Generated once, when the job is created, and never regenerated.
    idempotency_key   TEXT         NOT NULL,

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- One live job per message, ever. This is the constraint that makes "duplicate
-- queue processing does not duplicate a send" true at the storage layer instead
-- of in a worker's memory.
CREATE UNIQUE INDEX IF NOT EXISTS uq_messaging_job_message
    ON messaging_dispatch_jobs (message_uuid);

CREATE INDEX IF NOT EXISTS ix_messaging_jobs_claimable
    ON messaging_dispatch_jobs (available_at)
    WHERE status = 'queued';
CREATE INDEX IF NOT EXISTS ix_messaging_jobs_scope
    ON messaging_dispatch_jobs (cmp_id, status, created_at DESC);

-- ---------------------------------------------------------------------------
-- Webhook receipts.
--
-- Durable first, processed second. The provider is acknowledged once the row is
-- committed, because a provider that does not get a 200 retries, and a provider
-- that retries while we are still thinking sends the same event five times.
--
-- The tenant is resolved from `connection_uuid`, which is resolved from
-- SERVER-SIDE configuration keyed by the verified signature — never from a
-- tenant id in the payload. A payload that names a company is a payload
-- claiming one.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_webhook_receipts (
    receipt_id        BIGSERIAL PRIMARY KEY,
    -- Nullable: an event whose signature verified but whose connection we
    -- cannot resolve is still recorded, because that is exactly the event an
    -- administrator needs to see.
    cmp_id            INTEGER      NULL,
    connection_uuid   UUID         NULL
                      REFERENCES messaging_channel_connections (connection_uuid) ON DELETE SET NULL,

    provider          VARCHAR(48)  NOT NULL,
    -- The provider's own event id, which is what deduplication turns on.
    provider_event_id TEXT         NOT NULL,

    signature_verified BOOLEAN     NOT NULL DEFAULT FALSE,

    status            VARCHAR(16)  NOT NULL DEFAULT 'received'
                      CHECK (status IN ('received', 'processed', 'ignored', 'failed', 'rejected')),
    -- Why it was ignored or rejected: 'unknown_connection', 'bad_signature',
    -- 'duplicate', 'unsupported_event'.
    disposition       VARCHAR(48)  NOT NULL DEFAULT '',

    -- The event, as it arrived. This is PROVIDER TELEMETRY ABOUT OUR OWN
    -- MESSAGES — a delivery receipt for something we sent, or the body of a
    -- message a customer sent us. Both are Messaging records. It is not another
    -- Aicountly product's business data, and nothing else may be written here.
    -- Secrets are stripped before it lands: see WebhookService::redact().
    raw_event         JSONB        NOT NULL DEFAULT '{}'::jsonb,

    received_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    processed_at      TIMESTAMPTZ  NULL,
    error_detail      TEXT         NULL,

    UNIQUE (provider, provider_event_id)
);

CREATE INDEX IF NOT EXISTS ix_messaging_webhooks_unprocessed
    ON messaging_webhook_receipts (received_at)
    WHERE status = 'received';
CREATE INDEX IF NOT EXISTS ix_messaging_webhooks_scope
    ON messaging_webhook_receipts (cmp_id, received_at DESC);
