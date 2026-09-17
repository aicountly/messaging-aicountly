-- ---------------------------------------------------------------------------
-- Conversations and messages — the records Messaging genuinely owns.
--
-- READ THIS BEFORE ADDING A COLUMN HERE.
--
-- A stored message is HISTORICAL CORRESPONDENCE. "Your invoice INV-2048 for
-- ₹18,500 is overdue" is a true record of what was said on 12 April. It is NOT
-- a copy of invoice INV-2048, and it must never become one. The customer may
-- have paid on the 13th; Books knows that and this table does not. Every screen
-- that answers "what do they owe?" reads Books live. This table answers a
-- different question — "what did we tell them?" — and it is the only product in
-- the fleet that can.
--
-- So: no `outstanding_amount` column, no `invoice_status`, no `order_total`, no
-- `contact_name` kept in step with Contacts. Where a conversation relates to
-- another product's record, the relationship goes in
-- messaging_external_references as a reference and nothing more.
--
-- The one thing that looks like an exception and is not: `customer_address`.
-- That is the phone number or handle the message physically arrived from or was
-- sent to. It is channel transport identity, it is part of the delivery record,
-- and it cannot be re-derived from Contacts later because a contact's number
-- can change. It is not an address book: Messaging never lists it as a contact
-- master, never searches it as one, and resolves the person behind it through
-- Contacts.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_conversations (
    conversation_uuid UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,

    connection_uuid   UUID         NOT NULL
                      REFERENCES messaging_channel_connections (connection_uuid),
    channel           VARCHAR(24)  NOT NULL,

    -- Transport identity. See the header note.
    customer_address  TEXT         NOT NULL,
    -- Aicountly Contacts owns the person. This is a reference, resolved live.
    -- NULL is normal and permanent for a number nobody has matched to a contact.
    contact_uuid      TEXT         NULL,
    -- What the provider told us the sender calls themselves, e.g. a WhatsApp
    -- profile name. Provider metadata about this conversation, not a contact
    -- record: it is shown as "WhatsApp profile name" and never as "the customer".
    provider_profile_name TEXT     NOT NULL DEFAULT '',

    status            VARCHAR(16)  NOT NULL DEFAULT 'open'
                      CHECK (status IN ('open', 'pending', 'resolved')),
    priority          VARCHAR(16)  NOT NULL DEFAULT 'normal'
                      CHECK (priority IN ('low', 'normal', 'high', 'urgent')),

    -- Set by the AI or by an agent, and labelled as which. A guess presented as
    -- a fact is the thing this product must not do.
    intent            VARCHAR(48)  NOT NULL DEFAULT '',
    intent_source     VARCHAR(16)  NOT NULL DEFAULT 'none'
                      CHECK (intent_source IN ('none', 'ai', 'agent', 'journey')),

    assigned_to_uuid  TEXT         NULL,
    assigned_at       TIMESTAMPTZ  NULL,

    -- Timestamps the Command Centre's metrics are computed from. Each is
    -- deliberately specific, because "response time" is meaningless without
    -- saying whose response.
    first_inbound_at          TIMESTAMPTZ NULL,
    -- The first reply typed or approved by a HUMAN. An automated
    -- acknowledgement is not a first response and recording it here would make
    -- every median look excellent.
    first_human_outbound_at   TIMESTAMPTZ NULL,
    first_automated_outbound_at TIMESTAMPTZ NULL,
    last_inbound_at           TIMESTAMPTZ NULL,
    last_outbound_at          TIMESTAMPTZ NULL,
    resolved_at               TIMESTAMPTZ NULL,
    resolved_by_uuid          TEXT        NULL,
    reopened_count            INTEGER     NOT NULL DEFAULT 0,

    unread_inbound_count      INTEGER     NOT NULL DEFAULT 0,
    -- Whether a human has ever replied. Cheaper than a subquery on every inbox
    -- row and it is a fact about this conversation, not a cached foreign value.
    ai_assisted               BOOLEAN     NOT NULL DEFAULT FALSE,

    -- Preferred language for this conversation, when one has been established.
    language                  VARCHAR(12) NOT NULL DEFAULT '',

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    -- Optimistic concurrency for assignment and status. Two agents grabbing the
    -- same conversation is the normal case in a busy inbox, not an edge case.
    row_version       INTEGER      NOT NULL DEFAULT 1
);

-- One open conversation per customer address per connection. A second open
-- thread for the same number on the same channel is how two agents end up
-- answering the same person without seeing each other's replies.
CREATE UNIQUE INDEX IF NOT EXISTS uq_messaging_conv_open
    ON messaging_conversations (cmp_id, connection_uuid, customer_address)
    WHERE status <> 'resolved';

CREATE INDEX IF NOT EXISTS ix_messaging_conv_inbox
    ON messaging_conversations (cmp_id, bo_id, status, last_inbound_at DESC);
CREATE INDEX IF NOT EXISTS ix_messaging_conv_assigned
    ON messaging_conversations (cmp_id, assigned_to_uuid, status);
CREATE INDEX IF NOT EXISTS ix_messaging_conv_contact
    ON messaging_conversations (cmp_id, contact_uuid)
    WHERE contact_uuid IS NOT NULL;
-- Awaiting-reply queue: inbound after the last outbound, or never answered.
CREATE INDEX IF NOT EXISTS ix_messaging_conv_awaiting
    ON messaging_conversations (cmp_id, first_inbound_at)
    WHERE status <> 'resolved';

-- ---------------------------------------------------------------------------
-- Messages.
--
-- Immutable once dispatched. `body` is what was actually sent or received —
-- amendment after the fact would make the audit trail a fiction, so an edit
-- before approval replaces the DRAFT and an edit after dispatch is impossible.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_messages (
    message_uuid      UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    bo_id             INTEGER      NOT NULL DEFAULT 0,
    conversation_uuid UUID         NOT NULL
                      REFERENCES messaging_conversations (conversation_uuid) ON DELETE CASCADE,
    connection_uuid   UUID         NOT NULL
                      REFERENCES messaging_channel_connections (connection_uuid),
    channel           VARCHAR(24)  NOT NULL,

    direction         VARCHAR(12)  NOT NULL CHECK (direction IN ('inbound', 'outbound')),

    -- The lifecycle in section 11 of the brief. Note what is NOT in it: there is
    -- no state meaning "we think it probably arrived". `provider_accepted` and
    -- `delivered` are different rows in this CHECK because they are different
    -- facts, and a product that conflates them reports 100% delivery on a
    -- carrier outage.
    status            VARCHAR(24)  NOT NULL DEFAULT 'draft'
                      CHECK (status IN (
                          'draft',
                          'awaiting_approval',
                          'approved',
                          'queued',
                          'dispatching',
                          'provider_accepted',
                          'delivered',
                          'read',
                          'failed',
                          'cancelled',
                          -- The send timed out after the provider may already
                          -- have accepted it. NOT a failure and NOT a success:
                          -- resending would duplicate, discarding might lose a
                          -- message the customer received. It needs a human or
                          -- a provider status lookup. See DispatchService.
                          'submission_unknown'
                      )),

    body              TEXT         NOT NULL DEFAULT '',
    -- Normalised content type: text | template | media | interactive.
    content_type      VARCHAR(24)  NOT NULL DEFAULT 'text',
    language          VARCHAR(12)  NOT NULL DEFAULT '',

    -- Template send: which template version was used, and the variables bound
    -- to it. The variables ARE stored — they are part of what was sent — but a
    -- variable holding "₹18,500" is the amount AS SENT, timestamped, not the
    -- current balance. See the header note.
    template_uuid     UUID         NULL,
    template_version  INTEGER      NULL,
    template_variables JSONB       NULL,

    -- Where this message came from, proven by the credential that created it.
    origin            VARCHAR(24)  NOT NULL DEFAULT 'AGENT'
                      CHECK (origin IN ('AGENT', 'CUSTOMER', 'JOURNEY', 'APPOINTMENTS',
                                        'BILLING', 'BOOKS', 'SALES', 'POS', 'REACH',
                                        'API_INTEGRATION')),
    -- TRUE when a language model wrote or rewrote this content. Recorded so the
    -- Command Centre can count AI-assisted conversations honestly, and so an
    -- auditor can tell who wrote what.
    ai_generated      BOOLEAN      NOT NULL DEFAULT FALSE,
    ai_run_uuid       UUID         NULL,

    author_uuid       TEXT         NOT NULL DEFAULT '',
    journey_run_uuid  UUID         NULL,

    -- Approval binds to CONTENT, not to a message. Editing approved content
    -- must invalidate its approval, and the way that is made impossible to
    -- forget is to hash what was approved and compare at dispatch.
    content_hash      TEXT         NOT NULL DEFAULT '',
    approved_at       TIMESTAMPTZ  NULL,
    approved_by_uuid  TEXT         NULL,
    approved_content_hash TEXT     NULL,

    -- Provider's own id for this message, once it has one. The join key for
    -- every delivery receipt that arrives later.
    provider_message_id TEXT       NULL,

    -- Provider-reported cost, and our estimate, kept apart on purpose. An
    -- estimate summed into a spend figure and labelled as the bill is how a
    -- business is surprised at the end of the month.
    provider_cost_minor  BIGINT    NULL,
    estimated_cost_minor BIGINT    NULL,
    cost_currency        CHAR(3)   NULL,

    failure_code      VARCHAR(64)  NULL,
    failure_detail    TEXT         NULL,

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    queued_at         TIMESTAMPTZ  NULL,
    dispatched_at     TIMESTAMPTZ  NULL,
    provider_accepted_at TIMESTAMPTZ NULL,
    delivered_at      TIMESTAMPTZ  NULL,
    read_at           TIMESTAMPTZ  NULL,
    failed_at         TIMESTAMPTZ  NULL,

    -- The customer's own timestamp for an inbound message, where the provider
    -- supplies one. Kept apart from created_at, which is when we received it.
    sent_at           TIMESTAMPTZ  NULL,

    row_version       INTEGER      NOT NULL DEFAULT 1
);

CREATE INDEX IF NOT EXISTS ix_messaging_msg_thread
    ON messaging_messages (conversation_uuid, created_at);
CREATE INDEX IF NOT EXISTS ix_messaging_msg_scope_time
    ON messaging_messages (cmp_id, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_messaging_msg_status
    ON messaging_messages (cmp_id, status, created_at DESC);
-- Provider ids are unique per connection, not globally: two providers can and
-- do issue the same-looking id.
CREATE UNIQUE INDEX IF NOT EXISTS uq_messaging_msg_provider_id
    ON messaging_messages (connection_uuid, provider_message_id)
    WHERE provider_message_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_messaging_msg_journey_run
    ON messaging_messages (journey_run_uuid)
    WHERE journey_run_uuid IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Attachments.
--
-- The FILE is not stored here. Aicountly Drive owns documents, and where Drive
-- is configured an attachment is a Drive reference. Where it is not, the bytes
-- go to this product's own private object storage under a key that is never a
-- guessable path and is served only through an authorised, expiring URL — never
-- a public one.
--
-- Inbound media is UNTRUSTED. A PDF a stranger sent to a business WhatsApp
-- number is the single most likely hostile input this product will ever handle:
-- it is scanned, it is never executed, its declared type is never believed over
-- its sniffed one, and its text is never treated as an instruction to the AI.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_message_attachments (
    attachment_uuid   UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    message_uuid      UUID         NOT NULL
                      REFERENCES messaging_messages (message_uuid) ON DELETE CASCADE,

    filename          TEXT         NOT NULL DEFAULT '',
    -- What we determined it is, not what the upload claimed.
    media_type        VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
    byte_size         BIGINT       NOT NULL DEFAULT 0,
    checksum_sha256   TEXT         NOT NULL DEFAULT '',

    -- drive | local | provider. 'provider' is a media id we can re-fetch from
    -- the provider and have chosen not to copy.
    storage           VARCHAR(16)  NOT NULL DEFAULT 'local'
                      CHECK (storage IN ('drive', 'local', 'provider')),
    -- A Drive document uuid, an internal object key, or a provider media id.
    -- Never a URL: a stored URL is a stored credential when it carries a token.
    storage_ref       TEXT         NOT NULL DEFAULT '',

    scan_status       VARCHAR(16)  NOT NULL DEFAULT 'pending'
                      CHECK (scan_status IN ('pending', 'clean', 'infected', 'skipped', 'failed')),
    scan_detail       TEXT         NULL,
    scanned_at        TIMESTAMPTZ  NULL,

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_messaging_attach_message
    ON messaging_message_attachments (message_uuid);

-- ---------------------------------------------------------------------------
-- Delivery events — every provider receipt, kept.
--
-- Providers deliver out of order and more than once. Both are normal. So this
-- is an append-only event log with a uniqueness constraint on the provider's
-- own event id, and the message's status is derived by taking the FURTHEST state
-- reached rather than the most recent event seen. A `read` receipt overtaken by
-- a late `delivered` must not move the message backwards.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_delivery_events (
    event_id          BIGSERIAL PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    message_uuid      UUID         NULL
                      REFERENCES messaging_messages (message_uuid) ON DELETE CASCADE,
    connection_uuid   UUID         NOT NULL
                      REFERENCES messaging_channel_connections (connection_uuid),

    -- The provider's id for this EVENT, which is what makes replay safe.
    provider_event_id TEXT         NOT NULL,
    provider_message_id TEXT       NULL,

    event_type        VARCHAR(32)  NOT NULL,
    -- Ordinal of the lifecycle state this event implies, so "furthest reached"
    -- is a comparison and not a pile of if-statements. See Domain/MessageState.
    state_rank        SMALLINT     NOT NULL DEFAULT 0,

    error_code        VARCHAR(64)  NULL,
    error_detail      TEXT         NULL,

    -- When the provider says it happened, and when we received it. Different
    -- numbers, and the gap between them is what a delivery investigation reads.
    occurred_at       TIMESTAMPTZ  NULL,
    received_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    UNIQUE (connection_uuid, provider_event_id)
);

CREATE INDEX IF NOT EXISTS ix_messaging_events_message
    ON messaging_delivery_events (message_uuid, received_at);
CREATE INDEX IF NOT EXISTS ix_messaging_events_failures
    ON messaging_delivery_events (cmp_id, received_at DESC)
    WHERE error_code IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Internal notes, kept in a separate table from messages ON PURPOSE.
--
-- A note that can be rendered by the same code path as a customer-visible
-- message is a note that will one day be sent to a customer. Different table,
-- different endpoint, different type — so the mistake cannot be made by
-- forgetting a boolean.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_internal_notes (
    note_uuid         UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    conversation_uuid UUID         NOT NULL
                      REFERENCES messaging_conversations (conversation_uuid) ON DELETE CASCADE,
    body              TEXT         NOT NULL,
    author_uuid       TEXT         NOT NULL DEFAULT '',
    -- A handoff summary is a note with a purpose: written when a conversation
    -- changes hands so the next agent has the thread without reading it all.
    is_handoff        BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_messaging_notes_conversation
    ON messaging_internal_notes (conversation_uuid, created_at);

-- ---------------------------------------------------------------------------
-- Assignment history and labels.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_conversation_assignments (
    assignment_uuid   UUID PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,
    conversation_uuid UUID         NOT NULL
                      REFERENCES messaging_conversations (conversation_uuid) ON DELETE CASCADE,
    assigned_to_uuid  TEXT         NULL,
    assigned_by_uuid  TEXT         NOT NULL DEFAULT '',
    reason            TEXT         NULL,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_messaging_conv_assign_history
    ON messaging_conversation_assignments (conversation_uuid, created_at DESC);

CREATE TABLE IF NOT EXISTS messaging_labels (
    label_uuid     UUID PRIMARY KEY,
    cmp_id         INTEGER      NOT NULL,
    name           TEXT         NOT NULL,
    colour         VARCHAR(16)  NOT NULL DEFAULT 'neutral',
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    UNIQUE (cmp_id, name)
);

CREATE TABLE IF NOT EXISTS messaging_conversation_labels (
    conversation_uuid UUID NOT NULL
                      REFERENCES messaging_conversations (conversation_uuid) ON DELETE CASCADE,
    label_uuid        UUID NOT NULL
                      REFERENCES messaging_labels (label_uuid) ON DELETE CASCADE,
    cmp_id            INTEGER NOT NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    PRIMARY KEY (conversation_uuid, label_uuid)
);

-- ---------------------------------------------------------------------------
-- References to other products' records.
--
-- THE SHAPE MATTERS. Owner product, external id, relationship type, tenant.
-- There is deliberately NO `payload` column: an "external_references" table
-- with a jsonb blob of the foreign resource in it is a mirror with a modest
-- name, and it is the exact thing the architecture forbids. If a screen needs
-- the invoice, it fetches the invoice.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messaging_external_references (
    reference_id      BIGSERIAL PRIMARY KEY,
    cmp_id            INTEGER      NOT NULL,

    -- What of ours it hangs off.
    entity_type       VARCHAR(32)  NOT NULL
                      CHECK (entity_type IN ('conversation', 'message', 'journey_run', 'outcome')),
    entity_uuid       UUID         NOT NULL,

    -- Which product owns the thing being referenced.
    owner_product     VARCHAR(32)  NOT NULL
                      CHECK (owner_product IN ('contacts', 'books', 'sales', 'purchases',
                                               'billing', 'pay', 'appointments', 'calendar',
                                               'inventory', 'drive', 'reach', 'pos', 'manage')),
    -- The owning product's identifier. TEXT because the fleet is not uniform:
    -- Books uses integer voucher ids, Contacts uses uuids.
    external_id       TEXT         NOT NULL,
    -- A human-facing reference, where the owning product has one (INV-2048).
    -- Stored because it is what an agent recognises and what a delivery
    -- investigation is searched by; it is a label, never a source of truth.
    external_label    TEXT         NOT NULL DEFAULT '',

    relationship      VARCHAR(32)  NOT NULL
                      CHECK (relationship IN ('subject', 'triggered_by', 'attributed_outcome',
                                              'payment_request', 'attachment_source')),

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    UNIQUE (cmp_id, entity_type, entity_uuid, owner_product, external_id, relationship)
);

CREATE INDEX IF NOT EXISTS ix_messaging_extref_entity
    ON messaging_external_references (cmp_id, entity_type, entity_uuid);
CREATE INDEX IF NOT EXISTS ix_messaging_extref_external
    ON messaging_external_references (cmp_id, owner_product, external_id);
