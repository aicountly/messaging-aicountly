# Data ownership

This is the document to read before adding a table, a cache or a background job
to Messaging. It is short on purpose.

## The rule

**Messaging stores what Messaging owns. Everything else is read live, on the
request that needs it, from the product that owns it.**

There is no database synchronisation between Aicountly products in this
codebase, and adding any would be a breaking architectural change rather than
an optimisation.

## What Messaging owns

These are Messaging's records. They live in Messaging's schema and no other
product is the authority for them:

| Thing | Table |
| --- | --- |
| Channel connections and their capabilities | `messaging_channel_connections`, `messaging_channel_capabilities` |
| Conversations, assignment, labels, internal notes | `messaging_conversations`, `messaging_conversation_assignments`, `messaging_conversation_labels`, `messaging_internal_notes` |
| Messages actually sent or received, and their attachments | `messaging_messages`, `messaging_message_attachments` |
| Approvals, dispatch jobs, provider delivery events | `messaging_approvals`, `messaging_dispatch_jobs`, `messaging_delivery_events`, `messaging_webhook_receipts` |
| Consent decisions and suppressions | `messaging_consent_records`, `messaging_consent_events`, `messaging_suppressions` |
| Templates and their provider approval state | `messaging_templates`, `messaging_template_versions` |
| Journeys, versions and runs | `messaging_journeys`, `messaging_journey_versions`, `messaging_journey_runs`, `messaging_journey_step_runs` |
| Messaging's own daily counters | `messaging_daily_metrics` |
| References to other products' records | `messaging_external_references`, `messaging_outcome_links` |
| Permissions, settings, audit, idempotency | `messaging_permission_*`, `messaging_settings`, `messaging_audit_events`, `messaging_idempotency_keys` |

## What Messaging does not own

| Thing | Authority |
| --- | --- |
| Who the customer is — name, phone, email, address | **Contacts** |
| Company, branch and financial-year masters; who may open which company | **Manage** |
| Invoices, balances, receivables, payables, receipts, GST | **Books** |
| Orders, fulfilment | **Sales** / **Purchases** |
| Subscription and plan | **Billing** |
| Stock | **Inventory** |
| Payment links, payment state, the payment ledger | **Pay** |
| Bookings | **Appointments** |
| Calendar events | **Calendar** |
| Documents and their malware-scan verdict | **Drive / Vault** |
| Campaign planning | **Reach** |
| Internal team chat, meetings, audio/video | **Connect** |
| Telephony | **Voice** |
| AI model configuration and provider credentials | **Console** |
| Identity itself | **my.aicountly.com** |

## A stored message is correspondence, not a record of the invoice

This distinction is the one that gets lost first, so it is worth stating
plainly.

A message that says *"Your outstanding balance is ₹4,800"* is kept forever. It
has to be: it is what was actually sent to a customer, and an audit of a
payment reminder is worthless if the text can change afterwards. So the row is
immutable.

**That row is not the invoice, and it is not the balance.** It is a record of
what was said on a particular day. The current balance is in Books, it has
probably changed since, and every screen that shows a balance fetches it from
Books on the request that renders it. Nothing in Messaging reads an amount out
of a past message and presents it as current.

The same applies to a stored provider cost, a stored contact name captured at
the time, or an order number quoted in a reply: historical correspondence, not
a second source of truth.

## References, not copies

`messaging_external_references` links a conversation to an invoice, an order or
a booking. It has these columns and no others of substance:

```
entity_type  entity_uuid  owner_product  external_id  external_label  relationship
```

There is deliberately **no payload column, no snapshot, no amount and no
status**. `external_label` is a short human label so a link can be rendered
without a round trip ("INV-2026-0091"); it is a caption, and nothing computes
from it. To know what the invoice says, Messaging asks Books.

`messaging_outcome_links` is the same shape for attribution: it records *that*
a conversation preceded an invoice being paid, plus the evidence for the claim.
It records no amount, because the amount is Books'.

There are tests asserting both of these have no such columns
(`server-php/tests/integration.php`), so adding one fails the suite.

## What is allowed

- **In-memory, request-scoped.** The API response for the screen currently
  being rendered, held in a component or a PHP variable for the life of that
  request. It is gone afterwards.
- **Request-scoped deduplication.** `ApiClient` memoises identical GETs within
  one request so a page that needs the same invoice twice fetches it once. The
  memo does not outlive the request.
- **Messaging's own records**, as listed above.
- **Stable external references**, as described above.
- **Provider delivery events**, which are facts about Messaging's own sends.
- **Immutable copies of messages actually sent or received**, as described
  above.

## What is not allowed

Not as a performance fix, not "temporarily", and not behind a flag:

- Scheduled imports of another product's tables
- Cron-based synchronisation of any kind
- Database replication or change-data-capture between products
- Foreign database connections, foreign data wrappers, linked servers
- Application tables shared between products
- Cross-database joins
- A local `messaging_contacts`, `messaging_invoices`, `messaging_orders` or
  `messaging_inventory` table
- Redis or database caches holding other products' business records
- Background jobs that periodically copy other products' records
- `localStorage`, `sessionStorage` or IndexedDB copies of other products'
  business data
- Service-worker caching of authenticated business API responses
- A central analytics warehouse populated by copying other Aicountly
  applications

## The one scheduled job that reads Books, and why it is not synchronisation

`bin/journey-tick.php --checks` reads overdue invoices from Books, live, with an
explicit limit, and starts one journey run per invoice that does not already
have one.

It is not a synchronisation job:

- It writes **no** invoice row. The rows it reads are processed in memory and
  discarded.
- Nothing downstream reads its output. Every journey run re-fetches its own
  invoice before drafting, and **again** before sending.
- It maintains no local table, no watermark of invoice state, and no mirror.

What it produces is a journey run — a Messaging record — holding a reference to
an invoice. That is the "configured scheduled operational check" the product
needs, and it is deliberately the only one.

## The one thing that is cached, and why it is not a business record

`Ai/ConsoleCredentials` caches the short-lived AI credential Console issues, in
**APCu shared memory**, with a TTL — never on disk, so a provider key does not
come to rest on the product host.

That is a credential, not a business record. The prohibition above is on
persisting other products' *business data*: an invoice, a balance, a contact, an
order. A short-lived token, held in process memory for less time than it is
valid for, so that every AI call does not re-authenticate, is ordinary
credential handling. Nothing is inferred from it, no screen renders it, and it
disappears when the process does.

Nothing else is cached anywhere. There is no Redis, no memcached, no
disk-backed cache and no service worker in this product.

## Browser storage

Four things are written, and all four are the viewer's own UI state rather than
anybody's business data:

| Key | What it is |
| --- | --- |
| the selected `cmp_id` / `bo_id` | which company the person last opened |
| the app-launcher icon choice | which icon URL painted last, to avoid a flash |
| a sign-out flag | that a sign-out is in progress |
| a redirect-loop guard | a counter, so a portal bounce cannot loop forever |

No conversation, no contact, no invoice and no balance is written to
`localStorage`, `sessionStorage` or IndexedDB. Neither is the `ses_key` — see
[SECURITY.md](SECURITY.md). There is no service worker, so no authenticated
business response is cached by one.

## Logs, jobs and error reports

A foreign payload must not be persisted indirectly, either. Specifically:

- `Audit::redact()` drops foreign payload keys outright and replaces them with
  *"[foreign data not stored — read live from the owning product]"*. An audit
  entry records the reference and the decision, never the other product's
  record.
- Dispatch job rows hold no fetched business data — a job carries a message
  uuid and an approval hash.
- `ApiClient` logs an outcome, a status, a duration and a correlation id. It
  does not log a response body.
- `messaging_ai_runs` stores no prompt and no grounding payload, for the same
  reason.

## What it looks like when a product is unreachable

The screen says so, names the product, and disables only what depended on it.
It does not substitute sample data, does not fabricate a success, and does not
fall back to a local replacement. The five states each panel can be in are
`ready`, `pending`, `unavailable`, `forbidden` and `unsupported` — see
[INTEGRATIONS.md](INTEGRATIONS.md).
