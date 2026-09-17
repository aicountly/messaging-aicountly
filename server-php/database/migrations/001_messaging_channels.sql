-- ---------------------------------------------------------------------------
-- Channel connections and their capabilities.
--
-- A "channel" here is a configured way to reach a customer: one WhatsApp
-- Business number, one RCS agent, one SMS sender. Not a provider — the provider
-- is a property of the connection, chosen by configuration, because which SMS
-- company a business uses is their decision and not this schema's.
--
-- NO SECRETS IN THESE TABLES. `credential_ref` names where the credential
-- lives (an environment key, or a Console-managed reference); the credential
-- itself is never written to this database and never leaves the server. A
-- column called `access_token` here would be a column that ends up in a backup,
-- a `pg_dump` in somebody's downloads folder, and an audit row.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_channel_connections (
    connection_uuid   UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,

    -- whatsapp | rcs | sms, and future OTT channels as adapters land.
    channel           VARCHAR(24)  NOT NULL
                      CHECK (channel IN ('whatsapp', 'rcs', 'sms', 'ott')),

    -- Which adapter drives it. Configuration-driven: a deployment picks its
    -- provider, and nothing in this product prefers one.
    provider          VARCHAR(48)  NOT NULL,

    display_name      TEXT         NOT NULL DEFAULT '',

    -- The sender identity as the provider knows it: a phone number in E.164, an
    -- RCS agent id, an alphanumeric sender id. This is TRANSPORT IDENTITY, not
    -- a contact master — see messaging_conversations.customer_address.
    sender_address    TEXT         NOT NULL DEFAULT '',
    -- Provider-side account identifiers needed to call the API. Ids, not keys.
    provider_account_ref TEXT      NOT NULL DEFAULT '',

    -- Where the credential is, never the credential.
    credential_ref    TEXT         NOT NULL DEFAULT '',

    status            VARCHAR(24)  NOT NULL DEFAULT 'setup_pending'
                      CHECK (status IN (
                          'setup_pending',      -- created, credentials not present
                          'verification_required', -- provider wants a step we cannot do for them
                          'connected',
                          'suspended',          -- provider disabled it
                          'disconnected'
                      )),
    -- Why it is not connected, in words an administrator can act on. Never a value.
    status_detail      TEXT        NULL,

    -- Last time a real provider call succeeded, and what it said. NOT a copy of
    -- provider data — a timestamp and a category, so the screen can say "checked
    -- 2 minutes ago" instead of implying live state it did not verify.
    last_health_check_at   TIMESTAMPTZ NULL,
    last_health_check_ok   BOOLEAN     NULL,
    last_health_check_note TEXT        NULL,

    -- Provider-reported quality/limit signals, as the provider last reported
    -- them, with the time they were read. Nullable because most providers do not
    -- report them and a zero here would read as "throttled to nothing".
    provider_quality       VARCHAR(24) NULL,
    provider_rate_limit    INTEGER     NULL,
    provider_state_read_at TIMESTAMPTZ NULL,

    -- Webhook wiring. The secret is a reference, as above.
    webhook_secret_ref     TEXT        NOT NULL DEFAULT '',
    webhook_verified_at    TIMESTAMPTZ NULL,
    last_webhook_at        TIMESTAMPTZ NULL,

    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by        TEXT         NOT NULL DEFAULT '',
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_by        TEXT         NOT NULL DEFAULT '',

    -- Optimistic concurrency: two integration administrators editing the same
    -- connection must not silently overwrite each other.
    row_version       INTEGER      NOT NULL DEFAULT 1
);

-- One sender address per channel per company. A second row for the same
-- WhatsApp number is not a second channel, it is a misconfiguration that would
-- make delivery events ambiguous.
CREATE UNIQUE INDEX IF NOT EXISTS uq_messaging_conn_sender
    ON messaging_channel_connections (cmp_id, channel, sender_address)
    WHERE sender_address <> '';

CREATE INDEX IF NOT EXISTS ix_messaging_conn_scope
    ON messaging_channel_connections (cmp_id, bo_id, channel, is_active);

-- ---------------------------------------------------------------------------
-- What a channel can actually do.
--
-- This table exists because of one specific way to get a messaging product
-- wrong: assuming every sender accepts inbound replies. An alphanumeric SMS
-- sender id in India cannot receive anything. Showing a reply box on a
-- conversation that arrived over one is showing a box that silently discards
-- what the agent types.
--
-- Capabilities are recorded per connection, sourced from the adapter's declared
-- contract and refreshed from the provider where the provider reports them.
-- Nothing in the UI infers a capability from the channel name.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_channel_capabilities (
    capability_id     BIGSERIAL PRIMARY KEY,
    connection_uuid   UUID         NOT NULL
                      REFERENCES messaging_channel_connections (connection_uuid) ON DELETE CASCADE,
    cmp_id            INTEGER      NOT NULL,

    capability        VARCHAR(48)  NOT NULL,
    supported         BOOLEAN      NOT NULL DEFAULT FALSE,
    -- 'adapter' when it comes from the adapter's declared contract, 'provider'
    -- when the provider told us. The screen labels them differently because one
    -- is a promise and the other is an observation.
    source            VARCHAR(16)  NOT NULL DEFAULT 'adapter'
                      CHECK (source IN ('adapter', 'provider')),
    detail            TEXT         NULL,
    read_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    UNIQUE (connection_uuid, capability)
);

CREATE INDEX IF NOT EXISTS ix_messaging_capabilities_lookup
    ON messaging_channel_capabilities (cmp_id, connection_uuid, capability);

-- ---------------------------------------------------------------------------
-- Messaging's own settings and policies.
--
-- Quiet hours, the response target the Command Centre measures against, the
-- business timezone, how much the AI may do without review. All of it is
-- Messaging's, none of it is another product's.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_settings (
    cmp_id                 INTEGER      PRIMARY KEY,

    timezone               VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',
    -- ISO 4217. Spend and outcome values are reported in this currency and a
    -- value in another one is reported separately rather than converted — see
    -- 004's note on currency.
    currency               CHAR(3)      NOT NULL DEFAULT 'INR',

    -- The target the "at risk of missing a response target" suggestion uses.
    -- Nullable: with no target configured, that suggestion is not shown at all
    -- rather than invented against a default nobody agreed to.
    first_response_target_minutes  INTEGER NULL,
    resolution_target_minutes      INTEGER NULL,

    -- Local time, applied in `timezone`. A journey step that would dispatch
    -- inside this window waits instead.
    quiet_hours_start      TIME         NULL,
    quiet_hours_end        TIME         NULL,

    -- AI policy. Defaults are the conservative ones: draft and translate are
    -- allowed, sending is not, and creating financial records is off and is not
    -- Messaging's to turn on — that belongs to the owning product.
    ai_draft_allowed       BOOLEAN      NOT NULL DEFAULT TRUE,
    ai_translate_allowed   BOOLEAN      NOT NULL DEFAULT TRUE,
    ai_summarise_allowed   BOOLEAN      NOT NULL DEFAULT TRUE,
    ai_suggest_allowed     BOOLEAN      NOT NULL DEFAULT TRUE,
    -- Autonomous sending. FALSE by default and deliberately hard to turn on.
    ai_autosend_allowed    BOOLEAN      NOT NULL DEFAULT FALSE,

    default_languages      JSONB        NOT NULL DEFAULT '["en","hi"]'::jsonb,

    updated_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_by             TEXT         NOT NULL DEFAULT ''
);

-- ---------------------------------------------------------------------------
-- Permissions. Messaging's own, layered over the portal identity.
-- See src/Permissions.php for why replying and seeing balances are separate.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_permission_profiles (
    profile_id     BIGSERIAL PRIMARY KEY,
    cmp_id         INTEGER      NOT NULL,
    name           TEXT         NOT NULL,
    description    TEXT         NOT NULL DEFAULT '',
    permissions    JSONB        NOT NULL DEFAULT '[]'::jsonb,
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by     TEXT         NOT NULL DEFAULT '',
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_by     TEXT         NOT NULL DEFAULT '',

    UNIQUE (cmp_id, name)
);

CREATE TABLE IF NOT EXISTS messaging_permission_assignments (
    assignment_id  BIGSERIAL PRIMARY KEY,
    cmp_id         INTEGER      NOT NULL,
    -- The authenticated portal uuid. Never a client-supplied one — see Auth.
    user_uuid      TEXT         NOT NULL,
    profile_id     BIGINT       NOT NULL
                   REFERENCES messaging_permission_profiles (profile_id) ON DELETE CASCADE,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by     TEXT         NOT NULL DEFAULT '',

    UNIQUE (cmp_id, user_uuid, profile_id)
);

CREATE INDEX IF NOT EXISTS ix_messaging_assignments_user
    ON messaging_permission_assignments (cmp_id, user_uuid);

-- ---------------------------------------------------------------------------
-- Audit. Append-only. See src/Audit.php for what may and may not go in it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_audit_events (
    audit_id       BIGSERIAL PRIMARY KEY,
    cmp_id         INTEGER      NOT NULL,
    bo_id          INTEGER      NOT NULL DEFAULT 0,
    actor_uuid     TEXT         NOT NULL,
    actor_kind     VARCHAR(16)  NOT NULL,
    source_app     VARCHAR(32)  NOT NULL DEFAULT 'messaging',
    action         VARCHAR(64)  NOT NULL,
    entity_type    VARCHAR(48)  NOT NULL,
    entity_id      TEXT         NULL,
    before_state   JSONB        NULL,
    after_state    JSONB        NULL,
    reason         TEXT         NULL,
    ip_address     INET         NULL,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_messaging_audit_scope
    ON messaging_audit_events (cmp_id, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_messaging_audit_entity
    ON messaging_audit_events (cmp_id, entity_type, entity_id);
