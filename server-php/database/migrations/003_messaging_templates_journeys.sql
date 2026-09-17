-- ---------------------------------------------------------------------------
-- Templates and journeys.
--
-- Both are versioned, and both are versioned for the same reason: a run must
-- reference an IMMUTABLE definition. A journey that sent four hundred reminders
-- last Tuesday has to be inspectable as it was last Tuesday, not as somebody
-- edited it on Thursday. So journeys have draft versions that can be edited and
-- published versions that cannot, and a run points at a published version.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_templates (
    template_uuid     UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,

    name              TEXT         NOT NULL,
    channel           VARCHAR(24)  NOT NULL,
    category          VARCHAR(32)  NOT NULL DEFAULT 'utility',

    -- The version currently in use, where one has been approved.
    active_version    INTEGER      NULL,

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by        TEXT         NOT NULL DEFAULT '',
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_by        TEXT         NOT NULL DEFAULT '',
    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,

    UNIQUE (cmp_id, channel, name)
);

-- ---------------------------------------------------------------------------
-- Template versions, one row per language per revision.
--
-- `provider_status` is THE PROVIDER'S ANSWER, not ours. Nothing in this product
-- decides that a template is approved: it is submitted, the provider answers,
-- and that answer is recorded with the time it was read. A template whose
-- status is anything but 'approved' cannot be dispatched — see DispatchGuard.
--
-- Provider policy is NOT hard-coded anywhere in this schema. There is no CHECK
-- constraint enumerating WhatsApp's categories or a column for a 24-hour
-- window, because those are the provider's current rules and they change. What
-- is recorded is what the provider said and when.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_template_versions (
    version_id        BIGSERIAL PRIMARY KEY,
    template_uuid     UUID         NOT NULL
                      REFERENCES messaging_templates (template_uuid) ON DELETE CASCADE,
    cmp_id            INTEGER      NOT NULL,

    version           INTEGER      NOT NULL,
    language          VARCHAR(12)  NOT NULL DEFAULT 'en',

    body              TEXT         NOT NULL DEFAULT '',
    header            TEXT         NOT NULL DEFAULT '',
    footer            TEXT         NOT NULL DEFAULT '',
    -- Buttons and other interactive elements, in the adapter's normalised shape.
    components        JSONB        NOT NULL DEFAULT '[]'::jsonb,

    -- Declared variables: name, type, example, required. Validated before a
    -- send, so a template with an unbound {{amount}} is refused rather than
    -- dispatched with a literal "{{amount}}" in it.
    variable_schema   JSONB        NOT NULL DEFAULT '[]'::jsonb,

    -- The provider's own identifier for this template, once submitted.
    provider_template_id   TEXT    NULL,
    provider_status        VARCHAR(24) NOT NULL DEFAULT 'not_submitted'
                           CHECK (provider_status IN (
                               'not_submitted', 'submitted', 'in_review',
                               'approved', 'rejected', 'paused', 'disabled', 'unknown'
                           )),
    provider_rejection_reason TEXT NULL,
    provider_status_read_at   TIMESTAMPTZ NULL,
    submitted_at           TIMESTAMPTZ NULL,
    submitted_by           TEXT     NULL,

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by        TEXT         NOT NULL DEFAULT '',

    UNIQUE (template_uuid, version, language)
);

CREATE INDEX IF NOT EXISTS ix_messaging_tplver_status
    ON messaging_template_versions (cmp_id, provider_status);

-- ---------------------------------------------------------------------------
-- Journeys.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_journeys (
    journey_uuid      UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,

    name              TEXT         NOT NULL,
    description       TEXT         NOT NULL DEFAULT '',

    -- What kind of operational journey this is. Used to pick the right
    -- authoritative source at run time and to group the run history.
    kind              VARCHAR(32)  NOT NULL DEFAULT 'custom'
                      CHECK (kind IN ('overdue_invoice_reminder', 'appointment_reminder',
                                      'order_update', 'unanswered_enquiry_followup', 'custom')),

    status            VARCHAR(16)  NOT NULL DEFAULT 'draft'
                      CHECK (status IN ('draft', 'published', 'paused', 'archived')),

    -- The version that executes. NULL while the journey has never been
    -- published, which is exactly the state in which it must not run.
    published_version INTEGER      NULL,
    draft_version     INTEGER      NOT NULL DEFAULT 1,

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by        TEXT         NOT NULL DEFAULT '',
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_by        TEXT         NOT NULL DEFAULT '',

    UNIQUE (cmp_id, name)
);

CREATE TABLE IF NOT EXISTS messaging_journey_versions (
    journey_version_id BIGSERIAL PRIMARY KEY,
    journey_uuid       UUID        NOT NULL
                       REFERENCES messaging_journeys (journey_uuid) ON DELETE CASCADE,
    cmp_id             INTEGER     NOT NULL,
    version            INTEGER     NOT NULL,

    -- The node graph. A document rather than a table of nodes because a version
    -- is edited and published as one thing, and a half-published graph is not a
    -- state this product should be able to represent.
    definition         JSONB       NOT NULL DEFAULT '{}'::jsonb,

    -- Set when published. A published row is never edited again: the publish
    -- endpoint refuses to touch a version that already has this set, which is
    -- what makes a run's reference to it meaningful.
    published_at       TIMESTAMPTZ NULL,
    published_by       TEXT        NULL,
    -- Sha-256 of the definition as published, so a run can prove which bytes it
    -- executed even if somebody later manages to alter the row.
    definition_hash    TEXT        NOT NULL DEFAULT '',

    -- Validation outcome at publish time: unresolved templates, missing
    -- provider approval, a source that is not configured. Stored so the
    -- publish decision is auditable rather than re-derived later against
    -- different configuration.
    validation         JSONB       NOT NULL DEFAULT '{}'::jsonb,

    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by         TEXT        NOT NULL DEFAULT '',

    UNIQUE (journey_uuid, version)
);

-- ---------------------------------------------------------------------------
-- Journey runs.
--
-- One run per subject per trigger. `subject_ref` is the owning product's
-- identifier for what the run is about — an invoice id, an appointment uuid —
-- and it is a REFERENCE. The run does not store the invoice; every step that
-- needs it fetches it, which is what makes "cancel if already paid" possible.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_journey_runs (
    run_uuid          UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,

    journey_uuid      UUID         NOT NULL
                      REFERENCES messaging_journeys (journey_uuid) ON DELETE CASCADE,
    -- The IMMUTABLE published version this run executed. Not the journey's
    -- current version: those diverge the moment somebody edits the draft.
    journey_version   INTEGER      NOT NULL,

    -- simulation runs never dispatch. A separate column rather than a separate
    -- table so the run history reads as one list, and a CHECK in
    -- messaging_dispatch_jobs makes the guarantee structural.
    mode              VARCHAR(16)  NOT NULL DEFAULT 'live'
                      CHECK (mode IN ('live', 'simulation')),

    trigger_source    VARCHAR(32)  NOT NULL DEFAULT 'manual'
                      CHECK (trigger_source IN ('manual', 'webhook', 'scheduled_check', 'service_api')),

    -- What the run is about, as a reference to its owning product.
    subject_product   VARCHAR(32)  NOT NULL DEFAULT '',
    subject_ref       TEXT         NOT NULL DEFAULT '',
    contact_uuid      TEXT         NULL,
    conversation_uuid UUID         NULL
                      REFERENCES messaging_conversations (conversation_uuid) ON DELETE SET NULL,

    status            VARCHAR(24)  NOT NULL DEFAULT 'running'
                      CHECK (status IN ('running', 'awaiting_approval', 'paused',
                                        'completed', 'cancelled', 'failed')),
    -- Why it stopped, in a form the run list can group by: 'invoice_paid',
    -- 'no_consent', 'source_unavailable', 'channel_unavailable', 'sent'.
    outcome           VARCHAR(48)  NOT NULL DEFAULT '',
    outcome_detail    TEXT         NULL,

    started_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    finished_at       TIMESTAMPTZ  NULL,
    -- When a delay or quiet-hours step is waiting.
    resume_after      TIMESTAMPTZ  NULL,

    -- Idempotency for the trigger. The same invoice arriving twice from the
    -- same event must not start a second run and send a second reminder.
    trigger_key       TEXT         NOT NULL DEFAULT ''
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_messaging_run_trigger
    ON messaging_journey_runs (cmp_id, journey_uuid, trigger_key)
    WHERE trigger_key <> '';

CREATE INDEX IF NOT EXISTS ix_messaging_runs_journey
    ON messaging_journey_runs (journey_uuid, started_at DESC);
CREATE INDEX IF NOT EXISTS ix_messaging_runs_scope
    ON messaging_journey_runs (cmp_id, status, started_at DESC);
CREATE INDEX IF NOT EXISTS ix_messaging_runs_resume
    ON messaging_journey_runs (resume_after)
    WHERE status IN ('running', 'paused') AND resume_after IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Step runs — the execution log a journey's Activity tab reads.
--
-- Every step records WHY it went the way it did. That is not diagnostics, it is
-- the product: "why did this customer get a reminder?" and "why didn't this one?"
-- are the two questions anybody asks about an automation, and a journey that
-- cannot answer them is one nobody will trust with their customers.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_journey_step_runs (
    step_run_id       BIGSERIAL PRIMARY KEY,
    run_uuid          UUID         NOT NULL
                      REFERENCES messaging_journey_runs (run_uuid) ON DELETE CASCADE,
    cmp_id            INTEGER      NOT NULL,

    node_id           VARCHAR(64)  NOT NULL,
    node_type         VARCHAR(32)  NOT NULL,
    sequence          INTEGER      NOT NULL DEFAULT 0,

    status            VARCHAR(24)  NOT NULL
                      CHECK (status IN ('ok', 'skipped', 'blocked', 'paused', 'failed', 'cancelled')),
    -- The branch taken, for a condition node.
    branch            VARCHAR(48)  NOT NULL DEFAULT '',
    -- One sentence, ours, explaining the decision. Never a foreign payload:
    -- "Books reported the invoice is settled" and not the invoice document.
    explanation       TEXT         NOT NULL DEFAULT '',
    -- Which product was consulted and when, so a run shows its own freshness.
    source_product    VARCHAR(32)  NOT NULL DEFAULT '',
    source_fetched_at TIMESTAMPTZ  NULL,

    message_uuid      UUID         NULL
                      REFERENCES messaging_messages (message_uuid) ON DELETE SET NULL,

    started_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    finished_at       TIMESTAMPTZ  NULL
);

CREATE INDEX IF NOT EXISTS ix_messaging_step_runs_run
    ON messaging_journey_step_runs (run_uuid, sequence);

-- ---------------------------------------------------------------------------
-- Approvals.
--
-- An approval is bound to a CONTENT HASH, not to a message id. That is the
-- whole mechanism behind "editing approved content invalidates its approval":
-- the dispatcher compares the hash it is about to send against the hash that
-- was approved, and a mismatch is a refusal rather than a warning.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_approvals (
    approval_uuid     UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,

    subject_type      VARCHAR(24)  NOT NULL
                      CHECK (subject_type IN ('message', 'journey_version', 'template_version')),
    subject_uuid      UUID         NULL,
    subject_id        BIGINT       NULL,

    -- What was approved, byte for byte.
    content_hash      TEXT         NOT NULL DEFAULT '',

    decision          VARCHAR(16)  NOT NULL
                      CHECK (decision IN ('approved', 'rejected', 'withdrawn')),
    decided_by_uuid   TEXT         NOT NULL,
    decided_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    note              TEXT         NULL
);

CREATE INDEX IF NOT EXISTS ix_messaging_approvals_subject
    ON messaging_approvals (cmp_id, subject_type, subject_uuid);

-- ---------------------------------------------------------------------------
-- AI runs — minimal execution metadata.
--
-- WHAT IS NOT HERE. The prompt is not stored, and neither is the grounding
-- payload. A draft assistant's grounding contains a customer's outstanding
-- balance read live from Books; writing it here would make this table the
-- persistent foreign-data cache the architecture forbids, by the back door and
-- under the name "AI logs".
--
-- What IS stored: which task ran, which model, how long, how many tokens, and
-- whether the output was accepted. The DRAFT the model produced lives in
-- messaging_messages, because a draft an agent can edit and send is a Messaging
-- record.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_ai_runs (
    ai_run_uuid       UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,

    task              VARCHAR(48)  NOT NULL,
    conversation_uuid UUID         NULL
                      REFERENCES messaging_conversations (conversation_uuid) ON DELETE SET NULL,

    provider          VARCHAR(32)  NOT NULL DEFAULT '',
    model             VARCHAR(64)  NOT NULL DEFAULT '',

    status            VARCHAR(16)  NOT NULL DEFAULT 'ok'
                      CHECK (status IN ('ok', 'refused', 'failed', 'unavailable')),
    -- Which named sources the grounding drew on, so a suggestion can cite them.
    -- Product names and fetch times. Not their contents.
    grounding_sources JSONB        NOT NULL DEFAULT '[]'::jsonb,

    duration_ms       INTEGER      NOT NULL DEFAULT 0,
    input_tokens      INTEGER      NULL,
    output_tokens     INTEGER      NULL,

    -- Whether a human used what came back. The only honest measure of whether
    -- the assistant is helping.
    accepted          BOOLEAN      NULL,

    actor_uuid        TEXT         NOT NULL DEFAULT '',
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_messaging_ai_runs_scope
    ON messaging_ai_runs (cmp_id, created_at DESC);
