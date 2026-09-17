-- ---------------------------------------------------------------------------
-- Business outcomes — Messaging's ATTRIBUTION DECISIONS, not other products'
-- ledgers.
--
-- READ THIS CAREFULLY, because it is the table most likely to be turned into
-- the thing the architecture forbids.
--
-- What this table stores is a JUDGEMENT THIS PRODUCT MADE: "conversation X was
-- followed, within the configured window, by payment Y in Books". That judgement
-- is Messaging's own work and it belongs here. It records WHICH outcome was
-- linked, WHEN, by WHAT METHOD, and under WHICH window — the provenance of the
-- link.
--
-- What it does NOT store is the outcome's VALUE or its STATUS. There is no
-- `amount_minor` column and there is no `payment_status`. Business Outcomes
-- fetches those live from Books, Sales, Pay and Appointments when it renders or
-- exports, because a payment can be reversed, an order can be cancelled, and an
-- appointment can be moved. A local `collections_total` table would report a
-- refunded payment as revenue forever, and it would be a financial summary
-- table built by copying another product's records — which is precisely the
-- "central analytics warehouse populated by copying other applications" the
-- brief rules out.
--
-- ON CURRENCY. Nothing here sums. The renderer groups by the currency the
-- owning product reports and shows each separately unless an explicit
-- conversion source is configured. Two currencies added together with no stated
-- rate is a number that is wrong in a way nobody can see.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_outcome_links (
    outcome_uuid      UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,

    -- Our side of the link.
    conversation_uuid UUID         NULL
                      REFERENCES messaging_conversations (conversation_uuid) ON DELETE CASCADE,
    message_uuid      UUID         NULL
                      REFERENCES messaging_messages (message_uuid) ON DELETE CASCADE,
    run_uuid          UUID         NULL
                      REFERENCES messaging_journey_runs (run_uuid) ON DELETE SET NULL,

    -- Their side. A reference and a kind. No value, no status — see the header.
    owner_product     VARCHAR(32)  NOT NULL
                      CHECK (owner_product IN ('books', 'sales', 'pay', 'appointments',
                                               'billing', 'purchases', 'pos')),
    outcome_kind      VARCHAR(32)  NOT NULL
                      CHECK (outcome_kind IN ('invoice_payment', 'order', 'appointment_confirmed',
                                              'payment_link_paid', 'subscription_renewal')),
    external_id       TEXT         NOT NULL,
    external_label    TEXT         NOT NULL DEFAULT '',

    -- HOW the link was made, so a reader can weigh it. 'reference_match' is a
    -- customer quoting INV-2048 back at us; 'contact_window' is the weakest and
    -- is labelled as such in the UI.
    match_method      VARCHAR(32)  NOT NULL
                      CHECK (match_method IN ('reference_match', 'payment_link_callback',
                                              'contact_window', 'manual', 'journey_subject')),
    -- The window this link was made under, in hours, as configured AT THE TIME.
    -- Stored rather than read from settings later, because changing the window
    -- must not silently rewrite history.
    window_hours      INTEGER      NOT NULL DEFAULT 24,
    -- The timezone the window was evaluated in. A "same day" attribution means
    -- nothing without it.
    window_timezone   VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',

    -- The two instants the window was measured between.
    message_at        TIMESTAMPTZ  NULL,
    outcome_at        TIMESTAMPTZ  NULL,

    -- Whether this link is the one that counts for this outcome. See the unique
    -- index below: one payment linked to five reminders is ONE outcome, and
    -- counting it five times is the most common way an attribution dashboard
    -- starts lying.
    is_primary        BOOLEAN      NOT NULL DEFAULT TRUE,
    superseded_by     UUID         NULL
                      REFERENCES messaging_outcome_links (outcome_uuid) ON DELETE SET NULL,

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by_uuid   TEXT         NOT NULL DEFAULT '',
    -- 'engine' or a user uuid. A manual link is a stated opinion and is shown
    -- as one.
    created_by_kind   VARCHAR(16)  NOT NULL DEFAULT 'engine'
                      CHECK (created_by_kind IN ('engine', 'user'))
);

-- DEDUPLICATION, at the storage layer. One primary link per external outcome.
-- A second reminder cannot also claim the same payment.
CREATE UNIQUE INDEX IF NOT EXISTS uq_messaging_outcome_primary
    ON messaging_outcome_links (cmp_id, owner_product, outcome_kind, external_id)
    WHERE is_primary;

CREATE INDEX IF NOT EXISTS ix_messaging_outcomes_scope
    ON messaging_outcome_links (cmp_id, outcome_at DESC);
CREATE INDEX IF NOT EXISTS ix_messaging_outcomes_conversation
    ON messaging_outcome_links (conversation_uuid);
CREATE INDEX IF NOT EXISTS ix_messaging_outcomes_run
    ON messaging_outcome_links (run_uuid)
    WHERE run_uuid IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Messaging's own daily counters.
--
-- These are legitimate: they count OUR events. Messages we sent, replies we
-- received, conversations we resolved, cost the provider charged us. Every
-- figure here is derivable from messaging_messages and is kept only so the
-- Command Centre does not scan a year of messages to draw a trend line.
--
-- Note what is absent: no revenue, no collections, no order counts. Those are
-- other products' facts and they are fetched live. This table would be the
-- obvious place to "just cache" them and that is exactly why the boundary is
-- written down here.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_daily_metrics (
    metric_id         BIGSERIAL PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,
    -- The business day in the company's configured timezone, which is why this
    -- is a DATE and the events behind it are TIMESTAMPTZ.
    metric_date       DATE         NOT NULL,
    channel           VARCHAR(24)  NOT NULL,

    -- Each state counted separately. `accepted` is not `delivered`: a product
    -- that reports one as the other reports a carrier outage as success.
    messages_queued        INTEGER NOT NULL DEFAULT 0,
    messages_accepted      INTEGER NOT NULL DEFAULT 0,
    messages_delivered     INTEGER NOT NULL DEFAULT 0,
    messages_read          INTEGER NOT NULL DEFAULT 0,
    messages_failed        INTEGER NOT NULL DEFAULT 0,
    -- Accepted by the provider but with no terminal receipt yet. Neither
    -- delivered nor failed, and counted on its own so the delivery rate's
    -- denominator is honest about what it does not know.
    messages_unconfirmed   INTEGER NOT NULL DEFAULT 0,
    messages_inbound       INTEGER NOT NULL DEFAULT 0,

    conversations_opened   INTEGER NOT NULL DEFAULT 0,
    conversations_resolved INTEGER NOT NULL DEFAULT 0,
    conversations_ai_assisted INTEGER NOT NULL DEFAULT 0,

    -- Provider-reported and estimated cost, apart, with the currency named.
    provider_cost_minor    BIGINT  NOT NULL DEFAULT 0,
    estimated_cost_minor   BIGINT  NOT NULL DEFAULT 0,
    cost_currency          CHAR(3) NOT NULL DEFAULT 'INR',

    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (cmp_id, bo_id, metric_date, channel)
);

CREATE INDEX IF NOT EXISTS ix_messaging_daily_metrics_range
    ON messaging_daily_metrics (cmp_id, metric_date DESC);

-- ---------------------------------------------------------------------------
-- Dismissed suggestions.
--
-- A "next best move" somebody has dealt with must not come back tomorrow. This
-- records the dismissal, not the suggestion: the suggestion is recomputed from
-- live data every time and is never stored.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_dismissed_suggestions (
    dismissal_id   BIGSERIAL PRIMARY KEY,
    cmp_id         INTEGER      NOT NULL,
    user_uuid      TEXT         NOT NULL,
    -- A stable key for the KIND of suggestion plus its subject, hashed by the
    -- engine. Not the suggestion's text, which changes wording.
    suggestion_key TEXT         NOT NULL,
    dismissed_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    -- Dismissals age out, because "3 delivery failures need investigation" is
    -- worth raising again next week if it is still true.
    expires_at     TIMESTAMPTZ  NULL,

    UNIQUE (cmp_id, user_uuid, suggestion_key)
);

CREATE INDEX IF NOT EXISTS ix_messaging_dismissed_lookup
    ON messaging_dismissed_suggestions (cmp_id, user_uuid);

-- ---------------------------------------------------------------------------
-- Idempotency for inbound writes.
--
-- A sibling product posting a reminder retries on a timeout. Without this the
-- retry is a second message to the customer. The response is replayed instead,
-- which is what an idempotency key is supposed to buy.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_idempotency_keys (
    key_id         BIGSERIAL PRIMARY KEY,
    cmp_id         INTEGER      NOT NULL,
    -- Scoped by caller: two products may legitimately generate the same key.
    caller         VARCHAR(64)  NOT NULL,
    idempotency_key TEXT        NOT NULL,
    -- The route it was used on, so replaying a key against a different endpoint
    -- is a conflict rather than a wrong answer.
    operation      VARCHAR(64)  NOT NULL,
    request_hash   TEXT         NOT NULL DEFAULT '',

    response_status INTEGER     NULL,
    response_body   JSONB       NULL,

    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    completed_at   TIMESTAMPTZ  NULL,

    UNIQUE (cmp_id, caller, idempotency_key)
);

CREATE INDEX IF NOT EXISTS ix_messaging_idempotency_age
    ON messaging_idempotency_keys (created_at);
