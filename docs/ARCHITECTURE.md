# Architecture

## Shape

```
web/          React 19 + Vite + TypeScript. Builds to web/dist, served as static files.
server-php/   Plain PHP API. No build step, no composer, no vendor directory.
              PostgreSQL via PDO. Deployed to api/ inside the document root.
```

Two halves, deployed together, configured in opposite ways: the React bundle
has its values inlined at build time, the PHP API reads its `.env` on every
request. See [DEPLOYMENT.md](DEPLOYMENT.md).

This is the same shape as the other Aicountly products in the fleet
(`books-react-app`, `appointments-aicountly`, `sales-aicountly` and the rest),
and the plumbing files below are deliberately recognisable across all of them.

## The backend, file by file

```
index.php                  front controller: /api/health, the portal relay, then the router
src/Router.php             {name} segment patterns; 405 and 404 are different answers
src/Routes.php             the whole route table, in three clearly separated groups
src/Http.php               request reading and the fleet response envelopes
src/Auth.php               three ways in: user ses_key, service key, provider webhook
src/Context.php            cmp_id / bo_id, and the live Manage tenant check
src/Permissions.php        this product's own permission catalogue
src/Audit.php              who did what, with secrets and foreign payloads stripped
src/Db.php                 PDO, and Messaging's schema only
src/Features.php           which integrations this deployment actually has
src/Health.php             what /api/health reports
src/ServiceKeys.php        inbound service-key resolution, constant-time
src/CrossServiceCallContext.php   the re-entry guard
src/Clients/               one client per sibling product, all live reads
src/Channels/              one adapter per provider, plus the registry
src/Domain/                consent, conversations, messages, dispatch, journeys, outcomes
src/Ai/                    Console credentials, grounding, verification
src/Controllers/           thin: authorise, call a service, answer
database/migrations/       numbered .sql, forward-only, idempotent
bin/migrate.php            apply pending migrations
bin/dispatch-worker.php    the send queue
bin/journey-tick.php       resume delayed journey runs
tests/                     run.sh, integration.php, http.php, stub/, support/
```

## Request lifecycle

```
                        ┌─────────────────────────────────────────┐
  browser ──────────────▶ index.php                               │
   Bearer <ses_key>     │   /api/health?  → answer, no auth       │
                        │   /api/global/? → relay to the portal   │
                        │   otherwise     → Router                │
                        └───────────────┬─────────────────────────┘
                                        ▼
                             Auth::require()          who is this?
                                        ▼
                       Context::fromRequest()         which company?
                        + assertAllowed()             ← LIVE call to Manage
                                        ▼
                        Permissions::assert()         may they do this?
                                        ▼
                              a Domain service
                                        ▼
                    Http::data() / Http::list()       {data} or {data, meta}
```

Four things are worth knowing about that path.

**The tenant check is a live call and it fails closed.** `Context::assertAllowed`
asks Manage whether this session may open this company. If Manage cannot be
reached, the answer is `503`, never "allow" — a tenant check that fails open is
not a tenant check. It is memoised per request, because it runs on every scoped
endpoint.

**Permissions are asserted in the backend.** Hiding a control in React is a
courtesy; the route is one `curl` away. `messaging.conversations.reply` and
`messaging.context.financial` are separate grants, so the person answering
WhatsApp is not automatically the person who can see what a customer owes.

**Cross-product reads go out as the user.** `Auth` keeps the caller's `ses_key`
precisely so `BooksClient::withSession()` can present it. Books then applies its
own permissions to its own data, independently of anything Messaging believes.
A backend proxy that granted broader access than the authenticated user would
be a privilege-escalation path, so it does not exist.

**Every response is `Cache-Control: no-store, private`.** A cached
authenticated response is somebody else's conversation.

## Envelopes

```
success, one thing     { "data": { … } }
success, a list        { "data": [ … ], "meta": { "total": n, "limit": n, "offset": n, … } }
failure                { "error": { "code": "…", "message": "…", "details": { … } }, "message": "…" }
```

`meta` carries more than the pagination when it is load-bearing — a rate's
denominator, the actions present in an audit log, the note explaining what a
figure counted.

## The three ways in

| Caller | Credential | Origin recorded as |
| --- | --- | --- |
| A signed-in person | `Authorization: Bearer <ses_key>` | `AGENT` |
| Another Aicountly product | `X-Service-Key` + `X-Actor-Uuid` | the product the key belongs to |
| A channel provider | the provider's own signature over the raw body | `PROVIDER` |

`Auth::provenOrigin()` is decided by the credential presented, never by
anything in the request body. A browser session reaching
`POST /api/v1/messages` is refused, because a browser asking to send *as a
product* is exactly the origin laundering this prevents.

## Message lifecycle

```
 draft ──▶ awaiting_approval ──▶ approved ──▶ queued ──▶ dispatching
                                                              │
                     ┌────────────────────────────────────────┼──────────────┐
                     ▼                    ▼                   ▼              ▼
             provider_accepted        failed        submission_unknown   cancelled
                     │
              ┌──────┴──────┐
              ▼             ▼
         delivered ──▶    read
```

Two properties hold throughout.

**A status never moves backwards.** `MessageState::advance()` returns `null`
when the incoming status is not forward of the current one. A delivery receipt
that arrives after a read receipt is absorbed, not applied. A late *failure*
notice for a message the customer has already read is discarded, because it
would be worse than no notice at all.

**`submission_unknown` is never retried.** It means the provider did not tell us
whether it took the message. Retrying it is how a customer gets the same
message twice, so the message waits for a human, and reconciliation *asks* the
provider rather than sending again. Where the provider gave no message id there
is nothing to ask about, and the product says so and stops.

## Exactly-once dispatch

Three independent mechanisms, because one is not enough for something that
costs money and trust when it goes wrong:

1. `UNIQUE (message_uuid)` on `messaging_dispatch_jobs`. One message can have
   one job, enforced by the database.
2. `FOR UPDATE SKIP LOCKED` on the claim. Two workers take different rows and
   neither waits, so the queue is safe to run in several processes.
3. One idempotency key per message — `'msg-' . $messageUuid` — generated once
   and reused on every attempt, so a provider that supports deduplication can
   do its part.

An `enqueue` that finds an existing job returns that job rather than an error,
which is what makes a double-clicked Send button harmless.

## The eight dispatch gates

`Domain/DispatchGuard::evaluate()` runs immediately before the provider call,
in the worker, on the message as it stands at that moment:

1. The connection exists, is active and is configured.
2. The channel can carry this message — free-form text, a template, media, a
   link.
3. **Consent and suppression, re-read now.** Not at approval; now. A customer
   who replied STOP after the message was queued does not receive it.
4. Any external fact the message asserts is re-fetched.
5. Rate and window limits.
6. The approval matches the content about to go out.
7. **Every link and attachment the message promises actually exists.**
8. Quiet hours — promotional messages only.

Gate 7 is the one worth reading twice. A draft saying *"the payment link is
below"* with no link is worse than one saying the link is unavailable. An
approval cannot override it: approving content is permission to send *that
content*, not permission to conjure a resource that does not exist. A human who
wants to send it anyway has to edit the promise out, which invalidates the
approval and sends it back for review — exactly the right amount of friction.

## Approval is bound to a content hash

`messaging_messages` carries both `content_hash` (of the current body, type,
language, template, version, variables and attachments) and
`approved_content_hash`. Approving records the second from the first.

Editing an approved draft clears the approval and returns the message to
`draft`. Approving "₹4,800" is not authority to send "₹48,000", and the gate
catches a mismatch even if something ever wrote content without going through
the normal path.

## Journeys

A journey is a graph of typed nodes — `fetch_source`, `condition`,
`check_eligibility`, `draft`, `approval`, `revalidate`, `send`, `pause_notify`,
`stop` — validated before it can be published and executed only from a
**published version**. The version that executes is immutable and carries its
own hash, so a run can always be replayed against exactly the steps that ran.

The `revalidate` node is the interesting one: a reminder re-reads the invoice
after the human approved it and before it sends, and stops if the invoice has
since been paid. Nobody gets chased for money they have already handed over.

**A simulation sends nothing.** Two independent mechanisms:

- the simulator never enqueues, and states `dispatched: false` in its own
  payload;
- `messaging_dispatch_jobs` has `CHECK (mode = 'live')`, so the database
  refuses a simulation job even if the engine were wrong.

## Channel adapters

One interface (`Channels/ChannelAdapter`), one adapter per provider, written
against that provider's own published contract. There is no shared "messaging
API" abstraction pretending WhatsApp, RCS and SMS are the same thing, because
they are not: one requires pre-approved templates to start a conversation, one
depends on the recipient's handset, and one cannot receive a reply at all.

What they share is `send`, `verifyWebhook`, `normaliseWebhook`,
`declaredCapabilities`, `configurationGap` and `lookupStatus`. What differs is
*declared* rather than hidden — an alphanumeric SMS sender reports
`INBOUND: false`, which is what disables the composer rather than giving an
agent a box that eats what they type.

No provider is preferred. Which one a deployment uses is configuration, and the
RCS adapter refuses to send at all until a deployment states which provider
contract it is speaking — silently falling back to SMS would bill a customer for
a channel they did not choose.

## The re-entry guard

Messaging sends `X-Saas-Origin: messaging` on every outbound cross-product
call, and reads the same header inbound. If the product about to be called is
the one whose request is currently being served, the call is suppressed and
reported as unavailable.

Without this, product A serving a request calls product B, which calls A, which
parks a second PHP-FPM worker on a request already waiting — and under load the
pool starves. The header grants nothing: it can only ever suppress one of our
own outbound calls. Where a service key is present, the key decides instead.
See `books-react-app/docs/CROSS_SERVICE_CALL_RULES.md`.

## AI

Messaging holds no model provider key. It asks **Console** for the model
configuration and a short-lived credential, which is what makes AI governable
in one place for the whole fleet. With Console unconfigured, every AI feature
reports itself unavailable and names that as the reason.

Three properties:

- **Grounded.** A draft is generated from facts fetched live and passed as
  grounding. `DraftAssistant::verify()` then checks the output against that
  grounding and flags a figure that is not in it — a made-up balance in a
  payment reminder is the failure this exists to catch. Findings that
  `blocks_send` are the same ones `DispatchGuard` will refuse, so the UI can
  disable Send for the reason the backend would give.
- **Inbound content is data.** `AiClient::untrusted()` strips delimiters, and
  `interpret()` discards anything outside the vocabulary the prompt offered. A
  customer message or an attached document cannot redefine system
  instructions, reveal configuration or reach a tool.
- **No invented predictions.** "Next best actions" are SQL counts of
  Messaging's own records, labelled `verified_fact`, each saying what it
  counted. Outcome insights return `predictions: []` with a note, because a
  forecast this product cannot substantiate is a forecast it does not make.

## Frontend

```
src/auth/            portal SSO: auth_token in storage, ses_key in memory only
src/services/api.ts  typed fetch: envelopes, scope, idempotency, one 401 retry
src/hooks/           useApi, usePolledApi (pauses on a hidden tab), useMutation
src/ui/              the design system: tokens, states, charts, primitives
src/shell/           the application frame, navigation, company picker
src/pages/           five dashboards plus contacts, settings, detail routes
```

`api.ts` generates one idempotency key per user action **outside** the retry, so
the single 401 retry replays rather than sending a second message. Every request
is `cache: 'no-store'` and carries an `X-Correlation-Id`.

The five states each panel can render — loading, empty, error, permission,
pending integration — are separate components in `src/ui`, kept apart on
purpose. "Nobody connected Books" and "Books is down" are different sentences
with different next actions, and collapsing them into one error is how a user
retries something that will never work.

## Tests

```
server-php/tests/run.sh    the whole backend suite
web/            npm run test:ui
```

`run.sh` writes a test `.env`, applies the migrations **twice** (a migration
that only works on an empty database is a migration that fails on the next
deploy), starts a stub standing in for the sibling products, and runs:

- `integration.php` — the domain, against real PostgreSQL and real HTTP;
- `http.php` — the router, controllers and envelopes, **twice**: once with the
  siblings answering and once with the stub stopped, because "every dashboard
  still renders when Books is unreachable" and "business context refuses rather
  than guessing" are both promises and only one of them is testable with the
  stub up.

The stub is a real HTTP server rather than a mocked client, deliberately: a
mocked `ApiClient` would test the mock, not the envelope parsing, the
403-is-forbidden-not-unavailable rule, or the re-entry guard.

What the suite does **not** claim is that Meta or Twilio accept a payload. That
is not knowable from a test suite. The real adapters' decisions — signature
verification, capability declaration, configuration gaps, webhook normalisation
— are tested as the pure functions they are, and the pipeline is tested against
a recording adapter.
