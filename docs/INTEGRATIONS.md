# Integrations

Every integration in this product is a **live authenticated API call made on the
request that needs the answer**. None of them is a copy, a cache or a
synchronisation job. Read [DATA_OWNERSHIP.md](DATA_OWNERSHIP.md) first if that
distinction is not already clear.

## The sibling Aicountly products

| Product | What Messaging asks it for | Flag | Keys |
| --- | --- | --- | --- |
| **Manage** | Whether this session may open this company; the company and branch masters | *none — not optional* | `MANAGE_SERVICE_KEY`, `MANAGE_API_BASE` |
| **Contacts** | Who a phone number belongs to (company lookup, attributed only on exactly one match); a contact's details | `CONTACTS` | `CONTACTS_API_BASE` (no key: read with the person's own session) |
| **Books** | Outstanding bills for the ledger a contact is linked to in Contacts (books/ledger_account), overdue dues, receipts — as the signed-in person, per Manage's financial year | `BOOKS` | `BOOKS_API_BASE` (no key: Books has no product-key access) |
| **Sales** | Orders and their fulfilment state | `SALES` | `SALES_SERVICE_KEY`, `SALES_API_BASE` |
| **Pay** | A payment link, and whether it has been paid | `PAY` | `PAY_SERVICE_KEY`, `PAY_API_BASE` |
| **Appointments** | Bookings for a customer (read live, by booking **uuid**, for the inbox panel and outcomes) | `APPOINTMENTS` | `APPOINTMENTS_SERVICE_KEY`, `APPOINTMENTS_API_BASE` |
| **Drive / Vault** | A document and its malware-scan verdict | `DRIVE` | `DRIVE_SERVICE_KEY`, `DRIVE_API_BASE` |
| **Reach** | Campaign context, so Messaging does not rebuild campaign planning | `REACH` | `REACH_SERVICE_KEY`, `REACH_API_BASE` |
| **Billing** | Subscription and plan context | `BILLING` | `BILLING_SERVICE_KEY` |
| **AI Pulse** | Every AI task — drafts, translation, rewrites, summaries, classification, journey proposals, narration — on the model Console binds to Pulse | `AI` (on by default) | `PULSE_API_ORIGIN` (optional); `PULSE_SERVICE_KEY` only for service-key callers |
| **Console** | A channel secret kept by reference (`console:<name>`), optional | *none* | `CONSOLE_API_URL`, `CONSOLE_SERVICE_KEY` |

Manage has no feature flag because it is the tenant boundary. A deployment
without it cannot safely serve anybody, so "turning it off" is not a state this
product offers.

Each `*_API_BASE` defaults to the right host for `APP_ENV`, so a normal
deployment sets only the service keys.

## Two ways Messaging authenticates outbound

**As the user** — `withSession($auth->sesKey())`. Used for everything a screen
shows a person. Books then applies *its own* permissions to its own data, so a
user who cannot read a ledger in Books cannot read it through Messaging either.
This is why `Auth` keeps the caller's `ses_key` at all. AI Pulse is called the
same way: the user's session as Bearer, the company as `cmp_id`, and Pulse
checks both with Manage before any model runs. Only a sibling product calling
Messaging with its service key (nobody signed in) makes Messaging use the
estate service key with Pulse, naming the person as `actor_uuid`.

**As the product** — `withService($actorUuid)`. Used for background work with no
person behind it: a journey tick, a dispatch worker re-reading an invoice before
sending. The actor uuid records *whose* journey it is, for the audit trail.

A backend proxy that granted broader access than the authenticated user would be
a privilege-escalation route dressed up as a convenience. There is none.

## The five states, and why they are five

Every cross-product result is wrapped in an envelope carrying a `state`. The UI
renders each one differently because each one needs a different thing from the
person reading it.

| State | Means | HTTP | Retryable | What the screen says |
| --- | --- | --- | --- | --- |
| `ready` | It answered | 2xx | — | the data, plus when it was read |
| `pending` | Nobody has connected this product | — | no | "Connection pending", and which key is missing (to an administrator) |
| `unavailable` | It is configured but did not answer | 429, 5xx, timeout | **yes** | "Unavailable", with a Retry |
| `forbidden` | This user may not see this | 401, 403 | no | "Permission required" |
| `unsupported` | This deployment does not offer it | 404 | no | the feature is absent, not broken |

The distinction that matters most is `forbidden` versus `unavailable`. "You may
not see this" is permanent and needs a conversation with an administrator;
"Books is having a bad minute" is temporary and needs a Retry button. Showing
the first as the second invites a user to retry something that will never work.
`pending` versus `unavailable` matters for the same reason in the other
direction: an unconfigured integration is not an outage, and paging somebody at
night for one is how alerts get ignored.

Every `ready` result also carries `fetched_at`, which the UI renders as a
freshness note — "read from Books 4 seconds ago". A figure on screen with no
statement of when it was read is a figure somebody will assume is current an
hour later.

## When a product is unreachable

The contract, in full:

- The dependent feature stays behind its flag and is not silently removed.
- The screen shows "Connection pending", "Unavailable" or "Permission required"
  as appropriate, and names the product.
- Only the dependent actions are disabled. The rest of the screen keeps working.
- **No sample data is substituted.** Ever.
- **No successful response is fabricated.**
- **No local replacement database is created** for that product.

The HTTP test suite runs twice — once with the sibling stub up and once with it
stopped — precisely to keep this honest. With the stub down, no panel may claim
`ready`.

## Channel providers

| Provider | Channel | Adapter | What it needs |
| --- | --- | --- | --- |
| WhatsApp Cloud API | `whatsapp` | `WhatsAppCloudAdapter` | an access token; an app secret for `X-Hub-Signature-256` |
| Twilio | `sms` | `TwilioSmsAdapter` | an account sid on the connection; the auth token in the environment |
| RCS | `rcs` | `RcsAdapter` | `MESSAGING_RCS_API_BASE` **and** `MESSAGING_RCS_PROVIDER_STYLE` |

Planned, declared in `ChannelRegistry::PLANNED` and deliberately **not**
connectable: Messenger, LINE, Telegram, Instagram Direct. A planned channel has
no adapter, cannot be connected and cannot be selected on a journey. Listing it
as available with nothing behind it is the placeholder-that-looks-connected
problem this codebase works to make impossible.

### Credentials live in the environment, never in the database

A connection row stores `credential_ref` and `webhook_secret_ref` — the **names**
of environment variables. The adapter resolves the value at the moment of the
call.

The API returns a `credential_present` boolean and nothing else.
`ChannelConnection::toPublicArray()` is an allowlist, so a browser cannot
receive a credential even by accident, and there is a test asserting the encoded
public form contains no secret.

The variable *name* is shown to somebody holding
`messaging.channels.manage`, because "MESSAGING_WHATSAPP_TOKEN is not set on
this server" is the difference between an actionable gap and a mystery. The
value is never shown to anybody.

### Capabilities are data, not assumptions

`ChannelRegistry::capabilities()` composes three layers, in this order:

1. what the adapter **declares** for the provider;
2. what the adapter **refines** for this connection (an alphanumeric SMS sender
   loses `INBOUND`);
3. what the provider has **reported**, recorded in
   `messaging_channel_capabilities` — this wins, because it is an observation
   rather than a promise.

The composer, the dispatch gates and the journey editor all read the result. An
SMS sender id that cannot receive replies disables the reply box instead of
offering an agent somewhere to type that goes nowhere.

### Webhooks

```
GET  /api/webhooks/{provider}/{connection}     provider verification challenge
POST /api/webhooks/{provider}/{connection}     events
```

Four properties:

- **No session.** A provider has none, and demanding one would silently drop
  every delivery receipt. These routes are registered *first*, before the
  authenticated groups.
- **The signature is verified against the raw body**, using the secret named by
  that connection's `webhook_secret_ref`. An unsigned or mis-signed body is
  refused with 403.
- **The tenant comes from the connection uuid in the URL**, which is
  server-side configuration. A payload claiming a `cmp_id` is a payload
  *claiming* one, and it is ignored.
- **Replay is safe.** Each event carries the provider's own event id, recorded
  in `messaging_webhook_receipts` with a unique index, so the same webhook
  delivered twice is processed once.

Twilio signs the request URL as well as the body. That URL is reconstructed
from `MESSAGING_WEBHOOK_BASE_URL` rather than from the incoming `Host` header —
otherwise anybody who can set `Host` could make a forged signature verify.

## The published service contract

Other Aicountly products send through Messaging rather than growing their own
channel code. **Appointments' client notices** (confirmation, reminder,
cancellation, reschedule) are specified in full in
[`APPOINTMENTS_MESSAGING_CONTRACT.md`](APPOINTMENTS_MESSAGING_CONTRACT.md) — the same
file lives in `appointments-aicountly`; read that, not this summary.

```
POST /api/v1/messages                     send (202 accepted, never "sent")
GET  /api/v1/messages/{message_uuid}      delivery_state, reason, events      ?cmp_id=
POST /api/v1/messages/{message_uuid}/cancel   withdraw one Messaging still holds
GET  /api/v1/messages/stats               counts of what THIS product sent    ?cmp_id=
  X-Service-Key:     <the calling product's key>
  X-AIC-Environment: production | sandbox | local   (must equal this server's)
  Authorization:     Bearer <the person's own ses_key>   (when a person is present)
  X-Actor-Uuid:      <optional; must match that session, else only recorded as a claim>
  Idempotency-Key:   <8–200 chars of [A-Za-z0-9._:-]>        (POST only)

  { "cmp_id": 7,                          // required, in the body (or the query)
    "channel": "whatsapp",                // whatsapp | sms (| rcs); never email or voice
    "to": "+919876543210",                // E.164 only — Messaging never guesses a country code
    "template": "appointment_reminder",   // by name; only an approved version is sent
    "kind": "reminder",
    "reference": "<booking uuid>", "reference_label": "AP-1042",
    "scheduled_for": "…Z", "not_after": "…+05:30",
    "variables": { "client_name": "Priya", "when": "Wed 14 Oct 2026, 10:00 AM IST", … },
    "consent": { "basis": "staff_attestation", "source": "staff:1001",
                 "captured_at": "…", "contact_verified": true, "evidence_ref": "<booking uuid>" } }
```

What a service key may do (G19#7, `server-php/src/ServicePolicy.php`):

- **Routes:** only those listed for the product (the four above for
  appointments, billing, books, sales, pos, reach, crm, advisor, voice; Helpdesk
  may read `GET v1/conversations` and `GET v1/conversations/{uuid}/messages`).
  Anything else is `403 service_route_not_allowed`.
- **Environment:** `X-AIC-Environment` must equal the server's `AIC_ENVIRONMENT`
  (else `401 service_environment_mismatch`); a server with none accepts no key.
- **Company:** with a forwarded Bearer, Manage decides with that person's session.
  With nobody present, the company must have allowed the product in Settings
  (`service_products`, needs `messaging.access.manage`) or ops must list it in
  `SERVICE_KEY_COMPANIES` — else `403 service_company_not_bound`.
- **Actor:** a bare `X-Actor-Uuid` is recorded as a claim and never acted on;
  one that disagrees with the forwarded session is `401 actor_mismatch`.

What this endpoint insists on:

- **A service key, not a session.** A browser session is refused (origin
  laundering). The `origin` and `service_app` recorded on the message come from
  the key, and a product reads **only the messages it sent** (another product's
  message is a 404).
- **A company.** `cmp_id` in the body or query; a key does not map to a company.
- **An `Idempotency-Key`.** The same key replays the original message with its
  *current* state and sends nothing. A refusal that created no message is **not**
  stored, so the same key can succeed once the cause is fixed.
- **A 2xx means accepted.** `data.delivery_state` says how far it got
  (`queued`, `sent`, `unknown` → 202; `delivered` → 200). A message that did not go
  is not a 2xx: `failed` → 502, `suppressed` / `expired` → 422, `cancelled` → 409 —
  with `data.message_uuid` when a row exists.
- **Consent is enforced at dispatch.** A product on the allow-list
  (`CONSENT_SERVICE_APPS`) may *record* the consent it captured by sending it in
  `consent`; that is audited (`service_booking`, actor kind `service`), never
  overrides a withdrawal or a suppression, and an unverified public-form checkbox
  is `pending` unless `CONSENT_ACCEPT_UNVERIFIED=1`. Suppression and the rest of the
  gates still run.
- **`not_after`.** A message that outlives it is cancelled `expired`, never sent late.
- **Caps.** Per address per 24 h and per company per minute → `429`.
- **Voice and email are refused.** Telephony is Lobby's/Voice's; email is Aicountly
  Email's.

Configure inbound keys with `SERVICE_KEYS=app:key,app:key` — see
`server-php/.env.example`. Create a company's appointment templates (as drafts, for
an administrator to edit, submit and get approved) with
`php bin/seed-appointment-templates.php --cmp=<id>` (add `--apply` to write).

Reminder **timing** belongs to Appointments. The “Appointment nudge (manual)”
journey is an agent-started one-off for one booking, not the reminder schedule.

## Deliberate non-goals

Each of these is somebody else's product, and building it here would fragment
the fleet:

| Not built here | Where it belongs |
| --- | --- |
| A payment gateway or payment ledger | **Pay** |
| Event storage | **Calendar** |
| Campaign planning | **Reach** |
| Internal chat, meetings, audio/video | **Connect** |
| A telephony engine | **Voice** |
| A second address book | **Contacts** |
| A receivables table | **Books** |
| A model key, a model choice or an AI service of its own | **AI Pulse** runs the model Console binds; Messaging keeps its prompts and checks |
