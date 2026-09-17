# Security

This product sends messages to a business's customers and reads that business's
financial records. The two obvious failures are sending the wrong thing to the
wrong person, and showing one tenant's data to another. Most of what follows
exists to prevent one of those.

## Identity

Sign-in is the AICOUNTLY portal's, the same as every other product in the
fleet. Two tokens, deliberately different:

| Token | Lifetime | Where it lives |
| --- | --- | --- |
| `auth_token` | long | `localStorage` plus a shared `.aicountly.com` cookie |
| `ses_key` | short | **memory only** |

The `ses_key` is never written to `localStorage`, `sessionStorage` or a cookie.
A short-lived credential sitting in storage is a credential any script can read
for as long as the browser remembers it, which defeats the point of it being
short-lived. There is a test asserting nothing in `auth/portal.ts` writes a
ses-shaped value to storage.

**A client-provided UUID is never proof of identity.** `Auth` resolves the caller
from the credential presented and nothing else.

## Tenancy

`cmp_id` and `bo_id` arrive on every scoped request, and
`Context::assertAllowed()` asks **Manage**, live, whether this session may open
that company.

It fails **closed**. If Manage cannot be reached the answer is `503`, not
"allow" — the alternative is serving one tenant's conversations to another
whenever Manage has a bad minute.

Every query is scoped in SQL. There is no "fetch then filter in PHP" anywhere,
because a missing filter in application code is invisible until it is a breach.
A conversation read under another company's id returns nothing.

An inbound webhook's tenant comes from the connection uuid in the URL, which is
server-side configuration. A payload naming a `cmp_id` is ignored, and there is
a test that sends `cmp_id: 9999` in a signed body and asserts the conversation
lands in the right company.

## Permissions

Messaging has its own catalogue on top of the portal identity, and the split
that matters is this one:

```
messaging.conversations.reply     draft and send replies to customers
messaging.context.financial       view invoice, balance and payment context
```

**These are separate grants.** In a real business the person answering WhatsApp
is not the person who chases money, and a messaging product that leaks every
customer's outstanding balance to whoever staffs the inbox is not one anybody
should deploy. An agent with the first and not the second gets an inbox that
works and a business-context panel that says the financial part is not theirs
to see — with `data: []`, not with figures hidden by CSS.

Two further properties:

- **You cannot grant what you do not hold.** `Permissions::grantable()` narrows
  every profile edit to the caller's own grants, or "manage access" would be a
  route to every other permission. Only the company owner is exempt.
- **Lookup failure grants nothing.** If the permission query fails, the answer
  is `[]`.

The default for a member with no profile is deliberately conservative: read the
inbox, add an internal note. Not financial context, not approve, not send.

Enforcement is in the backend. Hiding a control in React is a courtesy — the
route is one `curl` away — and the HTTP test suite asserts the refusals happen
server-side.

## Credentials

**Browser clients never receive service credentials.** Not a channel token, not
a service key, not a Console credential. The only credentials a browser holds
are its own two portal tokens.

Channel credentials live in the server environment. A connection row stores the
*name* of the variable (`credential_ref`, `webhook_secret_ref`); the adapter
resolves the value at the moment of the call.
`ChannelConnection::toPublicArray()` is an allowlist that exposes
`credential_present` as a boolean and not the reference. Tests assert that the
encoded public form of a connection contains no secret, and that no frontend
file sets an `X-Service-Key` header, embeds a key-shaped literal, or reads a
secret-shaped build variable.

Inbound service keys are compared with `hash_equals` — constant time, so the
comparison does not leak the key a byte at a time.

`Audit::redact()` strips anything matching `token|secret|signature|password|
api[_-]?key|authorization|credential` before a state reaches the audit table.
`ApiClient` logs an outcome, a status, a duration and a correlation id, never a
response body. `WebhookService::redact()` does the same for provider payloads.

`server-php/.env.example` contains placeholders only. A committed
`server-php/.env` fails the deploy workflow outright.

## Writes

**Non-idempotent writes are never retried blindly.** One idempotency key per
user action, generated once — in the browser, before the request; in the backend,
`'msg-' . $messageUuid` for a dispatch job. The frontend's single 401 retry
reuses the same key, so it replays rather than sending a second message.

The published service contract **requires** an `Idempotency-Key` (8–200
characters of `[A-Za-z0-9._:-]`) and refuses the write without one. The same key
replays the original answer and sends nothing.

`submission_unknown` is never retried automatically. See
[ARCHITECTURE.md](ARCHITECTURE.md).

Optimistic concurrency (`row_version`) guards conversations, drafts, templates,
journeys and channel connections. Two agents grabbing the same conversation is
the normal case in a busy inbox, not an edge case.

## Consent

Recorded **per channel and per purpose**, with its evidence. A `transactional`
grant does not satisfy a `promotional` send; the whole point of recording the
purpose is that it narrows.

Consent is **re-read at dispatch**, in the worker, immediately before the
provider call. A customer who replies STOP after a message was queued does not
receive it, and there is a test for exactly that sequence.

Suppression is a separate table from consent, because a hard bounce, a provider
block, a spam complaint and a customer's own STOP all stop a send but only one
of them is a consent decision — recording them together would make "how many
people opted out?" unanswerable. A suppression stops a send regardless of what
consent says.

Opt-out detection is a **whole-body** match against a keyword list, in English
and Hindi. Substring matching would suppress the customer who wrote "stop by the
shop tomorrow" — a business losing a paying customer to its own filter. A
sentence that merely contains "stop" is routed to a human, who can record the
withdrawal with its evidence.

## Outbound requests

- **No arbitrary URL is fetchable by the backend.** Cross-product bases come
  from configuration, and provider bases (`graph.facebook.com`,
  `api.twilio.com`) are compiled-in constants — deliberately not overridable,
  so no environment variable can point a send at a host somebody else controls.
  That is also why the test suite uses a recording adapter rather than an
  overridable base URL.
- `HttpTransport` sets `CURLOPT_FOLLOWLOCATION => false`. A redirect is not
  followed, so a provider response cannot walk a request somewhere else.
- Timeouts are explicit, and a timeout is a distinct outcome from a failure —
  because "we do not know" and "it failed" lead to different actions.
- Outbound calls carry `Cache-Control: no-store`.

## Attachments

- Stored outside the document root. A file served directly is a file served
  without a permission check.
- Download links are signed and time-limited
  (`MESSAGING_ATTACHMENT_SIGNING_KEY`). Rotating the key invalidates
  outstanding links, which is the point.
- The media type is what the server determined, not what the upload claimed.
- Every file is scanned. **An unscanned or infected attachment does not reach a
  customer** — `DispatchGuard` refuses it. With no scanner configured, files are
  marked "not scanned" and therefore not sendable. A product that sends
  unscanned files because nobody configured a scanner is worse than one that
  says it cannot.

## AI

Messaging holds no model provider key. Console holds the configuration and
issues a short-lived credential, so AI is governed in one place for the fleet.
With Console unconfigured every AI feature reports itself unavailable and names
that as the reason.

**Inbound messages and documents are untrusted data.** They cannot redefine
system instructions, reveal secrets or reach a tool. `AiClient::untrusted()`
strips delimiters before content is included, and `interpret()` discards
anything outside the vocabulary the prompt offered — so a model answer, however
it was influenced, cannot name an action the caller did not enumerate.

Autonomous sending is **off by default** and turning it on needs
`messaging.ai.manage` *plus* an explicit `confirm_autonomous_sending` flag. The
backend refuses the change without it, and the UI asks in words rather than
offering a switch that quietly removes the human from the loop. Approval gates,
consent and quiet hours still apply when it is on; the review step is what goes
away.

`messaging_ai_runs` stores no prompt and no grounding payload — a grounding
payload is another product's business data, and storing it would be the
synchronisation this architecture forbids.

## Responses and transport

Every API response carries `Cache-Control: no-store, private`. A cached
authenticated response is somebody else's conversation, and an intermediary
would serve it happily.

`server-php/.htaccess` denies dotfiles over HTTP (`RedirectMatch 404
/\.(?!well-known)`), which is what stops `api/.env` being fetchable, and copies
the `Authorization` header into the request environment — Apache does not pass
it to PHP under CGI/FastCGI otherwise, and without it sign-in fails for everyone
with nothing in the logs to explain why.

List endpoints clamp `limit` to 200. An unbounded limit is a way to ask one
request to read the whole table.

`GET /api/health` is public, so it reports booleans and categories only: no
sender addresses (a sender address identifies the tenant), no environment key
names, no driver strings. The full database error goes to `error_log` instead.
`usable` depends on the database alone — an unconfigured channel is a
configuration state, not an outage.

## Auditing

`messaging_audit_events` records actor, actor kind, source app, action, entity,
before and after state, and reason. It is the answer to "who turned this on",
"who approved that message" and "who read this customer's balance".

It records the **reference and the decision**, never the other product's
records. An entry about a payment reminder names the invoice reference and what
was decided; the balance stays in Books, where it is current. Tests assert that
a recorded state containing a Books payload comes back with the payload dropped
and the reference kept.

## Reporting something

If you find a security problem in this product, raise it with the Aicountly
engineering team privately rather than in a public issue.
