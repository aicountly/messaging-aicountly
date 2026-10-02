# Appointments ↔ Messaging: client notices — wire contract (v1)

> This file is **identical in `appointments-aicountly` and `messaging-aicountly`**
> (`docs/APPOINTMENTS_MESSAGING_CONTRACT.md`). If the two differ, one of them is
> wrong; change both in the same change. The exchanges it describes are written as
> fixtures by Messaging's own suite and replayed by Appointments' (§15), and the
> summary in `calendar-react-app/docs/ecosystem-alignment/CONTRACTS.md` §16 points
> here.

## 1. Who owns what

One delivery owner per workflow.

| Workflow | Decided by | Delivered by |
| --- | --- | --- |
| A **client's** confirmation, reminders, cancellation and reschedule notices | **Appointments** (when, why, which booking, what the client consented to) | **Messaging** (provider, approved template, sender identity, consent enforcement at send time, delivery receipts) |
| A practitioner's own diary reminders on Calendar events | Calendar — **owner-personal only** | Calendar. A different workflow; it never goes through Messaging and Messaging never reads Calendar |
| An agent's one-off nudge for ONE booking | an agent, in Messaging ("Appointment nudge (manual)" journey) | Messaging. It is **not** the reminder schedule: timing, cancel and reschedule belong to Appointments, and a booking Appointments already reminds should not be nudged as well |

Appointments keeps its own timers (`appointment_reminders`); there is no central
automation engine. Messaging holds no copy of a booking: it receives what the
message needs in the request, and reads a booking live (`GET v1/bookings/{uuid}`)
only for its own inbox panel.

**Email is not Messaging's.** Messaging refuses it (`email_not_enabled`) and
Appointments records an `email` step `not_sent` with that reason without calling.
**Voice is not Messaging's** (`voice_belongs_to_receptionist`; Receptionist is now
Lobby).

## 2. Activation

* Appointments' `MESSAGING` feature flag is **off by default**: it needs
  `APPOINTMENTS_MESSAGING_ENABLED=1` **and** `MESSAGING_SERVICE_KEY`. While it is
  off nothing is sent and nobody is called, every screen that mentions client
  messaging says exactly **“No client messages are sent”**, and a notice that falls
  due is recorded `not_sent` with that reason.
* **Forward-only.** The first time the worker sees the flag on (and each time it
  comes back on) it records the moment. Every notice still scheduled for a time
  before it is recorded `expired` — “Its time passed before client messaging was
  switched on” — never sent late. Notices due after it are untouched.
* The checklist that turns it on is `docs/MESSAGING_ACTIVATION_CHECKLIST.md`
  (Appointments).

## 3. Transport

`POST {MESSAGING_API_BASE}/api/v1/messages` — a **service key**, never a session.

| Header | |
| --- | --- |
| `X-Service-Key` | Appointments' key. Messaging maps it to the product `appointments` (`SERVICE_KEYS=appointments:<key>`); the message's `origin` and `service_app` come from the key, never the body |
| `Idempotency-Key` | required, §9 |
| `Content-Type: application/json` | |

`MESSAGING_API_BASE` must be set **explicitly** in every Appointments process that
calls Messaging. A worker has no request host to derive it from, and
`bin/reminders.php` exits 3 rather than guess between sandbox and production.

`cmp_id` is read from the **JSON body** (or the query string) — never a header and
never inferred from the key. A request without it is `400 context_required`.

## 4. The request

```json
{
  "cmp_id": 7,
  "bo_id": 2,
  "channel": "whatsapp",
  "to": "+919876543210",
  "template": "appointment_reminder",
  "language": "en",
  "kind": "reminder",
  "reference": "7d4f0b1e-52c3-4a8e-9f10-2b6a8c3d9e01",
  "reference_label": "AP-1042",
  "scheduled_for": "2026-10-13T04:30:00Z",
  "not_after": "2026-10-13T06:00:00Z",
  "contact_uuid": "c0000000-0000-4000-8000-000000000001",
  "variables": {
    "client_name": "Priya Nair",
    "service_name": "Initial consultation",
    "when": "Wed 14 Oct 2026, 10:00 AM IST",
    "reference": "AP-1042",
    "date": "Wed 14 Oct 2026",
    "time": "10:00 AM",
    "timezone": "Asia/Kolkata",
    "timezone_label": "IST",
    "starts_at_local": "2026-10-14T10:00:00+05:30"
  },
  "consent": {
    "basis": "staff_attestation",
    "source": "staff:1001",
    "captured_at": "2026-10-12T11:00:00Z",
    "contact_verified": true,
    "evidence_ref": "7d4f0b1e-52c3-4a8e-9f10-2b6a8c3d9e01"
  }
}
```

| Field | Rule |
| --- | --- |
| `cmp_id` | required, the **booking's** company. `bo_id` optional (branch) |
| `channel` | `whatsapp` or `sms` (`rcs` exists in Messaging, unused here). `email` / `voice` are refused; anything else `unknown_channel` |
| `to` | **E.164 only**: `+` and 8–15 digits, first digit 1–9, no spaces (`^\+[1-9][0-9]{7,14}$`). Messaging never guesses a country code: anything else is `422 invalid_address`, `retryable:false` |
| `template` | the template **name** for the kind (§6). Messaging sends only a provider-approved version |
| `language` | default `en` |
| `kind` | `confirmation` \| `reminder` \| `cancellation` \| `reschedule`. Stored with the message; the template must be the kind's |
| `reference` | the **booking uuid** (a durable reference, not a copy of the booking); `reference_label` is the human reference (`AP-1042`). Messaging stores them with the message and links the conversation to them. (A legacy `{product,id}` object is still read.) |
| `scheduled_for` | when Appointments meant it to go (informational) |
| `not_after` | ISO 8601 **with an offset**: the last moment it is worth delivering (§10) |
| `contact_uuid` | optional Contacts reference |
| `variables` | §6/§7. Scalar values only. A variable the template declares and the request lacks is `422 variables_missing` |
| `consent` | §8. Optional; without it only a consent Messaging already holds can satisfy the send |

**Sender identity.** The sending company is `cmp_id`; its sender is the company's
connected channel in Messaging (WhatsApp number / SMS sender). Messaging fills the
template variable `sender_name` from that connection's display name; a caller
cannot set it (a value sent is ignored).

## 5. Recipients

Appointments turns what the client typed into E.164 with `Support\Phone`:

1. `+<digits>` and `00<digits>` are taken as written (separators removed), 8–15
   digits; they never consult a default country.
2. A **local** number is read in the company's default country — the company's
   `phone_default_country` setting, else the country of its timezone (never a
   constant) — and only for countries whose national length is known; the trunk
   prefix (leading `0`, or `1` in the North American plan) is dropped and the
   length must fit. There is deliberately no “starts with the country code, so it
   already has one” heuristic (`9123456789` is a valid Indian mobile).
3. What cannot be read is **not sent**: the reminder is `not_sent` with the reason
   (“…has no country code (+…) and this company has no default country…”).

Messaging validates again (§4) and keys consent and suppression on the same
canonical form.

## 6. Templates and variables

Four templates, by name, per channel. They are created as **drafts** for a company
by `bin/seed-appointment-templates.php --cmp=N [--apply]` (Messaging; dry run by
default) and then edited, submitted to the provider and approved by an
administrator. Nothing here is, or claims to be, provider-approved: a WhatsApp
utility template needs provider approval, and an SMS template on an Indian route
needs its DLT registration. Until then a send is refused `template_not_approved`
(retryable — an administrator may fix it within minutes) and Appointments records
the notice `failed` with that reason after its attempts.

| Kind | Template name | Declared variables |
| --- | --- | --- |
| confirmation | `appointment_confirmation` | `client_name`, `service_name`, `when`, `reference`, `sender_name`* |
| reminder | `appointment_reminder` | `client_name`, `service_name`, `when`, `reference`, `sender_name`* |
| cancellation | `appointment_cancellation` | `client_name`, `service_name`, `when`, `reference`, `sender_name`* |
| reschedule | `appointment_reschedule` | `client_name`, `service_name`, `old_when`, `when`, `reference`, `sender_name`* |

\* Messaging fills `sender_name`; Appointments never sends it. For a reschedule,
`when`/`reference` are the **new** booking's and `old_when` the old one's.

Appointments sends the declared variables **plus** `date`, `time`, `timezone`
(IANA), `timezone_label`, `starts_at_local` so a company can edit its template to
use them. The same lists are in `tests/fixtures/*/contract.json` of both
repositories, which each suite asserts against its own code.

`no_show` is **not** a kind: nothing sends a no-show message (a message about a
missed appointment is a decision about tone and fees that has not been made);
the `no_show` lifecycle event only withdraws pending reminders.

## 7. Times are in the booking's timezone

`when` is the appointment instant rendered in the **booking's own IANA timezone**
(`appointment_bookings.timezone`) as `Wed 14 Oct 2026, 10:00 AM IST`: not UTC, not
the server's zone, not the provider's, and not assumed to be India. The label is
the zone's own abbreviation (`IST`, `BST`/`GMT` across a DST change, `EDT`); a zone
with no letter abbreviation reads `UTC+04:00`. Messaging renders the variables it
is given verbatim.

## 8. Consent: recorded at booking, enforced at send time

**Appointments records** (`appointment_booking_consents`, one row per booking,
carried unchanged through a reschedule): the **basis**, its **source**, when it was
captured, and whether the contact was **verified**.

| Basis | Recorded when | Verified |
| --- | --- | --- |
| `public_form_checkbox` | a guest ticked the box on the public booking page | **never** (a guest typed the number) |
| `staff_attestation` | a signed-in member of staff says the client agreed | yes — named in `source` (`staff:<uuid>`) |
| `existing_relationship` | a staff booking for a directory contact (`contact:<uuid>`), or a partner backend vouching for its own customer (`partner:<label>`) | yes |

The body asks; the **credential decides**: a guest cannot claim a staff
attestation or a verified contact.

**Messaging enforces.** Every send carries the booking's `consent` object. For a
product on its allow-list (`CONSENT_SERVICE_APPS`, default `appointments`) and a
transactional purpose (decided by the key, never the body), Messaging records it
with `actor_kind = service` and evidence source `service_booking`, then evaluates
consent **at dispatch**, on exactly the records in front of it:

* a **withdrawn** record or an **active suppression** is *never* overridden by a
  caller; only a person, in Messaging, lifts them;
* `staff_attestation` / `existing_relationship` with `contact_verified:true` →
  `granted`;
* `public_form_checkbox` → `granted` only with `contact_verified:true`, or when the
  deployment has set `CONSENT_ACCEPT_UNVERIFIED=1` (off by default — an unverified
  checkbox proves someone ticked it, not that the number is theirs); otherwise it is
  recorded **`pending`**: visible to an agent in Channels & Trust, nothing is sent;
* no consent at all → refused.

A refusal is **terminal** and is reported as `delivery_state: suppressed` with a
reason code: `no_consent`, `consent_pending`, `withdrawn`, `suppressed`
(`no_address`). Appointments records the notice `suppressed` with those words and
**never retries it**.

### Consent wording

One wording, owned by Appointments (`BookingConsent::PUBLIC_CHECKBOX_TEXT`,
published as `client_notifications.consent_text` on the public page payload next to
`enabled`/`notice`):

> **Public booking page, beside the checkbox (shown only while client messages are on; never pre-ticked):**
> “Send me my booking confirmation and appointment reminders by WhatsApp or SMS on the phone number I have given. I can reply STOP at any time to stop them.”
>
> **While they are off (instead of the checkbox):** “No client messages are sent.” The page adds that the client will not receive a confirmation or reminders by message from this booking.

**For a partner that books on a customer's behalf (Advisor).** The consent text that
mentions reminders must say they are sent only when enabled. Replace the
unconditional “Aicountly Appointments needs one to confirm the booking and send
reminders” with:

> “Aicountly Appointments uses your contact details to identify your booking.
> Booking confirmations and reminders are sent by WhatsApp or SMS only if the
> business has switched them on; if it has not, nothing is sent to you.”

and show the **reminder sentence only when `client_notifications.enabled` is true**
on the public page payload. When it is false, show `client_notifications.message`
instead. Email reminders are not sent by Appointments at all: any copy that says
“email reminders go out” (e.g. `docs/ADVISOR_SCHEDULING.md`) is wrong and must be
removed. `enabled:true` means the deployment has switched client messages on; it
does not promise a given client a message (consent, a connected channel and an
approved template are checked per message).

## 9. Idempotency

`Idempotency-Key: appt.<booking_uuid>.<kind>.<channel>.<scheduled_for as YYYYMMDDTHHMMSSZ>`
— per `(booking, kind, scheduled_for)`, and **channel**, because a rule that sends
the same kind on WhatsApp and SMS at the same instant is two intended messages and
Messaging scopes the key per company and caller.

* The **exact request body** is stored on the reminder row before the first
  attempt and resent **byte for byte** on every retry (Messaging binds a key to its
  body; a retry rebuilt from a booking edited meanwhile would be a `409`). The stored
  body (a name and a number) is cleared as soon as the notice is anywhere but
  `scheduled`: there is no retry left to serve.
* Same key + same body → Messaging answers the **original message** (`replayed:true`)
  with its **current** state and sends nothing again.
* Same key + different body → `409 conflict` (`idempotency_key_reused`).
* A refusal that happened **before any message existed** (no template yet, no
  channel, no consent, rate limit, `not_after` passed) is **not stored**: the same
  key may succeed after the cause is fixed. Only an answer about a message that
  exists is replayed.

## 10. `not_after`, expiry, quiet hours, limits

* `not_after` = the appointment's start; for a reminder, `min(start, due + 90
  minutes)`. Appointments never sends after it, and Messaging cancels a queued
  retry that outlives it as **expired** (never sent late) — in the pre-send check,
  in the dispatch guard on every attempt, and in the worker. A request whose
  `not_after` has already passed is `422 expired` with nothing created.
* A reminder more than 90 minutes late, or for an appointment that has started, is
  recorded `expired` and no request is made.
* **Quiet hours.** Messaging holds only *promotional* sends in a company's quiet
  hours; a transactional appointment notice is deliberately not held (someone
  waiting on a confirmation at nine in the evening wants it then). Appointments does
  not add its own quiet window: reminders fire at their rule's offset. A per-company
  quiet window for reminders is a product decision that has not been taken.
* **Rate limits** (Messaging, per calling product): `SERVICE_ADDRESS_DAILY_CAP`
  (default 20 messages per address per 24 h) and `SERVICE_COMPANY_PER_MINUTE`
  (default 120) → `429 rate_limited` with `retry_after`; Appointments waits as long
  as it says.

## 11. What the answer means

**A 2xx means accepted — never sent, never delivered.** Appointments reads the
envelope, not the status line: `data.message_uuid` **and** `data.delivery_state`
must be present and recognised, or the answer is “no usable answer” (retry).

| HTTP | `data.delivery_state` | Meaning | Appointments row |
| --- | --- | --- | --- |
| 202 | `queued` | Messaging has it, not yet at the provider (or in its own retry queue) | `queued` |
| 202 | `sent` | the provider took it — **not** delivery | `sent` |
| 202 | `unknown` | the send timed out; the provider may have it. Not resent | `queued`, `delivery_state: unknown` |
| 200 | `delivered` | (replay/status only) the provider reported delivery | `delivered` |
| 502 | `failed` (`error.code: send_failed`) | terminal; Messaging retried what it could | `failed` |
| 422 | `suppressed` (`no_consent`/`suppressed`) | terminal, never retried | `suppressed` |
| 422 | `expired` | `not_after` passed | `expired` |
| 409 | `cancelled` | withdrawn | `cancelled` |
| 422 | — (`invalid_address`, `validation_failed`, `variables_missing`, `email_not_enabled`, `unknown_channel`) | the request is wrong (`error.details.retryable:false`) | `failed` |
| 422/404 | — (`template_not_approved`, `template_missing`, `channel_not_configured`; `retryable:true`) | an administrator can fix it | retry, counts |
| 400 | — (`context_required`) | Appointments bug/config | retry, counts |
| 401/403 | — | the key is refused | retry, counts |
| 429 / 5xx / 409 `in_progress` / no HTTP answer / unreadable body | — | no usable answer | retry, same key |

When a message row exists the error body **also** carries `data.message_uuid`
(and `error.details.message_uuid`) so staff can trace it. `message_id` is an alias
of `message_uuid`.

## 12. Delivery state comes back by **pull**

Messaging receives the provider's receipts and Messaging has no outbound callback,
so Appointments **reads**: `GET {base}/api/v1/messages/{message_uuid}?cmp_id=N` —
a live read, not a sync of Messaging's data. The worker reads each accepted
message at most every two minutes for 48 hours after acceptance (oldest read
first; three consecutive unreadable answers stop the pass) and stores
`delivery_state`, the provider's `reason_code`/`reason`, `delivered_at` and
`delivery_checked_at` on the reminder row; the staff booking detail shows them.

| Messaging stored status | `delivery_state` |
| --- | --- |
| draft … approved, `queued`, `dispatching` | `queued` |
| `provider_accepted` | `sent` |
| `delivered`, `read` | `delivered` |
| `failed` (consent/suppression failure code at dispatch) | `suppressed` |
| `failed` (anything else) | `failed` |
| `cancelled` (`failure_code expired`) | `expired` |
| `cancelled` (consent code) | `suppressed` |
| `cancelled` (another gate's code) | `failed` |
| `cancelled` (withdrawn) | `cancelled` |
| `submission_unknown` | `unknown` |

A product reads **only the messages it sent** (`service_app` is proven by the key;
another product's message is a `404`, not a `403`). `GET v1/messages/stats` is
scoped the same way. `POST v1/messages/{uuid}/cancel` (`{cmp_id, reason}`) withdraws
a message Messaging still holds (`200`, state `cancelled`); one the provider already
holds answers `409 already_dispatched` and Appointments records what is true.

## 13. Retries

* At most **three attempts** to get Messaging to accept a notice (including the
  first), with backoff **+5 min, +15 min** (or longer if `retry_after` says), always
  the **same key and bytes**. Never past `not_after`: a retry that would land after it
  is recorded `expired`.
* After the third attempt: `failed`, visible on the booking with the last reason
  (`delivery_state: unknown` when Messaging never answered — it may have the message;
  it is **not** claimed to be a definite failure and is never auto-resent).
* **Never retried:** `suppressed`, `expired`, `failed` (Messaging has already retried
  provider errors up to five times), `cancelled`, and a request Messaging says is
  wrong (`retryable:false`).

## 14. Lifecycle → notices

All from `BookingService::lifecycle()`, once per event.

| Event | Notice |
| --- | --- |
| `created` | schedule the rule's reminders (offsets before the start; one already past is not scheduled) and, if the booking is **CONFIRMED**, the confirmation (due now). A **PENDING** booking gets no confirmation (“confirmed” would be false) — a step may say `send_while_pending` for a template that does not claim confirmation. A booking made by a **reschedule** gets reminders but **no** second confirmation |
| `confirmed` | the confirmation, if the booking has none yet |
| `cancelled`, `auto_released` | withdraw scheduled confirmation/reminders (and withdraw at Messaging any message it still holds); **one cancellation notice** per channel (a declined pending request is told too) |
| `voided` | withdraw; **no notice** — a voided booking (Calendar refused the slot, a move that did not complete) was never promised to the client |
| `rescheduled` | withdraw the old booking's pending notices; **one reschedule notice** on the **new** booking with the **new time** (`when`) and the old (`old_when`), on the consent the booking was made under |
| `completed`, `no_show` | withdraw pending reminders; no notice |

A DRAFT is never messaged. A reminder is judged **when it is due**: pending/inactive/unconfirmed
conditions are re-read from the booking then (`skipped`/`cancelled` with the
reason). Calendar's sync state is independent and never gates a client message.

**Which channels.** The company's reminder rule: its steps (`kind` =
confirmation|reminder|cancellation|reschedule, `offset_minutes`, `channel`,
`template_key`, `only_if_unconfirmed`, `send_while_pending`). A rule with no explicit
cancellation/reschedule step sends **one** notice on the channel the rule already
uses (WhatsApp, else SMS). New companies' default rule is WhatsApp confirmation,
WhatsApp 24 h, SMS 4 h (only if not yet confirmed) and WhatsApp notices; existing
companies keep their rules (their `email` step is recorded `not_sent`).

## 15. Fixtures, tests and the drift guard

* `messaging-aicountly/server-php/tests/service.php` runs the **real router and
  controllers** against an approved template and writes
  `tests/fixtures/service-api/*.json` — one exchange per scenario (accepted, queued,
  unknown, failed, no_consent, consent_pending, withdrawn, suppressed, expired,
  template_not_approved, invalid_address, email_not_enabled, rate_limited,
  context_required, status_*, cancel_*) plus `contract.json` (E.164 pattern, kinds,
  templates and variables, built-ins, required request fields, key pattern, states).
  It fails when the receiver's output changes (`REGENERATE_FIXTURES=1` rewrites them).
* `appointments-aicountly/server-php/tests/fixtures/messaging-service-api/` holds the
  **byte-identical copies**. Its Messaging stub (`tests/stub/messaging_v1.php`) sends
  only those bodies, enforces the receiver's request rules, and `tests/messaging.php`
  holds Appointments' real requests to `contract.json`. With
  `MESSAGING_REPO=/path/to/messaging-aicountly` it also fails if any fixture differs.
* The other direction: Appointments writes `tests/fixtures/booking-show.contract.json`
  (the real `GET v1/bookings/{uuid}` `data.booking`); Messaging reads it as
  `tests/fixtures/appointments/booking-show.json` (`SourceReader::normaliseAppointmentsBooking`).
  Bookings are addressed by **uuid**; a human reference is `404`.

Any change to a request rule, a response, a variable or a state is a change to
this file, to the fixtures in **both** repositories, and to `contract.json`.

## 16. Configuration

| | Appointments | Messaging |
| --- | --- | --- |
| Switch | `APPOINTMENTS_MESSAGING_ENABLED=1` (default off) | — |
| Credential | `MESSAGING_SERVICE_KEY` | `SERVICE_KEYS=appointments:<same key>` |
| Origin | `MESSAGING_API_BASE` (explicit, required for the worker) | — |
| Company default country | `phone_default_country` (settings) | — |
| Consent | — | `CONSENT_SERVICE_APPS`, `CONSENT_ACCEPT_UNVERIFIED` |
| Limits | — | `SERVICE_ADDRESS_DAILY_CAP`, `SERVICE_COMPANY_PER_MINUTE` |
| Templates | — | `bin/seed-appointment-templates.php --cmp=N [--apply]`, then submit/approve |
| Channel | — | a connected WhatsApp / SMS channel for the company |
| Cron | `bin/reminders.php` every 1–5 minutes | `bin/dispatch-worker.php` (its own retry queue) |
| Migration | `008_messaging.sql` | `007_messaging_service_api.sql` |

## 17. Known limits (decisions, not oversights)

* No client reply handling (CONFIRM/RESCHEDULE keywords are not parsed); “confirmed
  after a reminder” on the dashboard means **staff** confirmed.
* No email; no voice; no no-show message; no ICS/invitation.
* A confirmation goes out within one worker cycle of the event (run the cron every
  minute for a faster one); it is not sent inline in the booking request.
* Messaging does not verify that `cmp_id` belongs to the key (keys are fleet-wide;
  `messaging-aicountly-F13`): Messaging reads only what a key itself sent, but a key can
  still post for any company it names.
* A company whose reminder rules predate this change keeps them; moving its default
  confirmation off email is a data change for that company's administrator.
