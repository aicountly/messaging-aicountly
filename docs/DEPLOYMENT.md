# Deploying Messaging

## Layout

```
web/          React app (Vite). Builds to web/dist.
server-php/   PHP API. Plain PHP, no build step — deployed as-is.
docs/         this file, plus the auth notes
```

## What lands where on cPanel

| Workflow | Deploys | Destination | Reachable at |
| --- | --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `server-php/` | `<remote root>/` and `<remote root>/api/` | https://messaging.aicountly.com (+ `/api`) |
| Deploy to cPanel Sandbox | `web/dist/` then `server-php/` | `<remote root>/` and `<remote root>/api/` | https://messaging.gh.aicountly.com (+ `/api`) |

`<remote root>` is the `*_SSH_REMOTE_ROOT` secret for that environment,
normally `public_html` (or the subdomain's own document root).

Deployment is manual only — **Actions → pick a workflow → Run workflow**.
Nothing deploys on push or merge.

## One workflow per environment, not per half

Production and sandbox are genuinely separate targets — different SSH
credentials, different servers — so each gets its own workflow. Within one
environment, though, the web build and the API are deployed by the same run,
one after the other: first `web/dist/` to the document root, then
`server-php/` to `api/` inside it. Splitting those into separate workflows
would only mean clicking twice for something that is always meant to happen
together, with two SSH sessions and two sets of runner setup instead of one.

### Why the api folder survives the web deploy step

The web deploy step runs `rsync --delete` against the document root, which
would otherwise remove everything not in the build — including `api/`, since
the API lives inside the document root. That step therefore excludes `api/`
explicitly. **Removing that exclude would delete the entire backend on the
next deploy.**

### Why the API's .env survives the API deploy step

The API deploy step also runs `rsync --delete`, this time against `api/`. The
API's `.env` is created once by hand on the server and exists nowhere else, so
both `--exclude='.env'` and `--exclude='.env.*'` are what keep it alive.
Removing them would wipe the live configuration on the next deploy.

Neither `.env` is ever uploaded either: `.gitignore` keeps them out of the
repository, and the workflow fails the build outright if a committed `.env`
appears under `server-php/`.

## Configuration: two different mechanisms

This is the part worth reading carefully, because the frontend and the backend
behave in opposite ways.

### React (web/) — build time

Vite inlines every `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files that **never read a `.env`
from disk**. Putting a `.env` in the document root has no effect.

To change a frontend value: change it in the workflow (or in the optional
repository variable), then re-run the workflow. The rebuild is what applies it.

Never put a secret in a `VITE_` variable — anything inlined into the bundle is
public to anyone who views the page source.

The API URL needs no configuration in the normal case: with
`PROD_API_BASE_URL` / `SANDBOX_API_BASE_URL` unset, the app calls its own origin
+ `/api`, which is where the same workflow's API step deploys `server-php`.
Those repository variables exist only to override that — for example if the API
moves to its own domain.

### server-php — runtime

PHP reads its `.env` on **every request**. So the API's `.env` belongs on the
server, and only on the server.

Create it once by hand — cPanel File Manager or SSH — at
`<remote root>/api/.env`, from `server-php/.env.example`. That template is the
annotated list, in eight sections; the minimum for a working deploy is:

```
APP_ENV=production

DB_HOST=localhost
DB_PORT=5432
CONSOLE_API_URL=https://console.aicountly.org/api
CONSOLE_DB_DETAILS_KEY=<the key generated on Messaging's row in Console, starting sdb_>
DB_PASS=<password of the user Console names>

MESSAGING_PUBLIC_BASE_URL=https://messaging.aicountly.com
MESSAGING_WEBHOOK_BASE_URL=https://messaging.aicountly.com
MESSAGING_ATTACHMENT_DIR=/home/<cpaneluser>/messaging-attachments
MESSAGING_ATTACHMENT_SIGNING_KEY=<openssl rand -hex 32>

MANAGE_SERVICE_KEY=...
```

`APP_ENV=sandbox` for the sandbox. `GET /api/health` reports the value back,
which is how you confirm you are looking at the environment you think you are.

Everything else — the other sibling products, the channel providers, AI — is
optional in the sense that its absence is a *reported state* rather than a
failure. An unset `BOOKS_SERVICE_KEY` means the business-context panel says
Books is not connected; it does not mean the inbox breaks. Nothing is on by
default except the features Messaging owns outright, and `/api/health` names the
missing key for each one.

AI needs one value in `api/.env`: `PULSE_SERVICE_KEY`, Messaging's own AI Pulse
gateway key, minted on the Pulse host with
`php spark pulse:gateway-key mint messaging` (production and sandbox Pulse each
mint their own) and pasted straight from that terminal. It is sent on every AI
call beside the signed-in user's session; until it is set only the session is
sent, which Pulse accepts only until **2026-11-15** — after that every AI
feature stops (401 `product_key_required`). `CONSOLE_SERVICE_KEY` is not a
substitute. The Pulse host follows this one (`PULSE_API_ORIGIN` only to point
elsewhere). An `api/.env` from before AI moved to Pulse may still hold
`CONSOLE_API_URL` and `CONSOLE_SERVICE_KEY`; keep them only if a channel
connection uses a `console:<name>` reference, otherwise delete both:

```sql
SELECT cmp_id, connection_uuid FROM messaging_channel_connections
WHERE credential_ref LIKE 'console:%' OR webhook_secret_ref LIKE 'console:%';
```

Two of the values above are not optional in the same way:

- **`MESSAGING_WEBHOOK_BASE_URL` is a security control.** Some providers sign
  the request URL, and that URL is reconstructed from this value rather than
  from the incoming `Host` header — otherwise anybody who can set `Host` could
  make a forged signature verify. Set it to the exact origin you registered
  with the provider.
- **`MESSAGING_ATTACHMENT_DIR` must be outside the document root.** A file
  served directly by Apache is a file served without a permission check.

## The database

PostgreSQL, and it holds **Messaging's own tables only**. There is no foreign
data wrapper here, no linked server, no cross-database view and no credential
for another product's database — everything owned elsewhere is read live over
HTTP. See [DATA_OWNERSHIP.md](DATA_OWNERSHIP.md).

On cPanel: create the database and a user under **PostgreSQL Databases**, then
add the user to the database with **ALL PRIVILEGES**. cPanel prefixes both names
with the account name, so a database entered as `messaging` becomes
`<cpaneluser>_messaging` — record the full prefixed names in Console (below), and
use `DB_HOST=localhost`, because on cPanel the database is on the same machine.

**The database name and username are not set in `.env`.** Console > SaaS Database Details records
them per product and environment, and the API asks Console for them
(`GET $CONSOLE_API_URL/database-details/resolve`, the key as a bearer token) on every request,
worker and `bin/migrate.php`. This is the same split Connect uses: Console holds no password,
host or port, so `DB_PASS`, `DB_HOST`, `DB_PORT` (and the optional `DB_SSLMODE`, `DB_SCHEMA`) stay in
`api/.env`, and any other field in Console's answer is ignored. The password in `DB_PASS` must belong
to the (prefixed) user Console names for this row, and that user needs **ALL PRIVILEGES** on the database.

**The variables must be named exactly `CONSOLE_API_URL` and `CONSOLE_DB_DETAILS_KEY`.** The key is
the per-row key Console shows once under *Generate key* (it starts with `sdb_`); Console shows
it only once, so *Rotate key* gives a new one if it was not saved, and rotating kills the old
one. `CONSOLE_SERVICE_KEY` is a different credential and Console rejects it here. If either variable
is missing, misspelled, commented out or empty, Console is simply never asked: the API quietly uses
`DB_NAME` / `DB_USER`, and **commenting those out then leaves no database at all**. A key for the other
environment (production vs sandbox) is refused. `DB_NAME` / `DB_USER` are a local-development fallback
only: they are not read while both Console variables are set.

To see where the connection really comes from, and whether the database accepts it, run on the
server (from `api/`): `php bin/db-check.php`. It asks Console right now (cache bypassed), prints
what Console answered, connects, and checks that every migration is applied; it never prints
the key or the password, and ends with a `Reason:` and what to do when something is wrong.
`/api/health` reports the same: `database.source` is `console` when Console supplies the name and
username and `env` when `DB_NAME` / `DB_USER` do (on a deployed server it should say `console`),
and a failure to obtain them is reported by its own `database.reason` with a `database.hint`:

| `database.reason` | What it means | Fix |
| --- | --- | --- |
| `console_key_missing` | `CONSOLE_API_URL` is set but `CONSOLE_DB_DETAILS_KEY` is not (and `DB_NAME` / `DB_USER` are not set), so Console is never asked | put the `sdb_` key generated in Console > SaaS Database Details in `CONSOLE_DB_DETAILS_KEY` |
| `console_url_missing` | `CONSOLE_DB_DETAILS_KEY` is set but `CONSOLE_API_URL` is not | `CONSOLE_API_URL=https://console.aicountly.org/api` |
| `console_key_rejected` | Console answered 401: the key is revoked, rotated or wrong | generate a key on this deployment's row in Console |
| `console_row_inactive` | Console answered 403: the row is inactive | activate it in Console > SaaS Database Details |
| `console_unreachable` | this server could not reach Console (and no earlier answer is cached) | check `CONSOLE_API_URL` and outbound HTTPS |
| `console_environment_mismatch` | the key belongs to the other environment | use the key from this deployment's own row |
| `console_no_database_recorded` | Console has no database name and username for this row | record them in Console |
| `not_configured` | neither the two Console settings nor `DB_NAME` + `DB_USER` are set | `api/.env` |

### Migrations

```bash
php api/bin/db-check.php            # is the database reached, and from where?
php api/bin/migrate.php --status    # what would run, changes nothing
php api/bin/migrate.php --dry-run   # parse and check each file, roll back
php api/bin/migrate.php             # apply what is pending
```

Numbered, forward-only and idempotent. Each file runs in its own transaction and
is recorded with a checksum, so:

- a half-applied migration cannot exist — either the file is in and recorded, or
  neither;
- re-running is safe and prints `Up to date.`;
- a file that has been **edited since it was applied** is reported (exit code 2)
  rather than silently reapplied, because reapplying it would not undo what the
  old version did.

Nothing in `database/migrations/` drops a table, drops a column or rewrites
data. A change that needs to is a change that needs a human and a backup, not a
deploy step.

**The deploy runs this for you.** After the API is uploaded, each workflow runs
`php api/bin/migrate.php` over the same SSH connection and fails the job if it
fails — an app running against a schema it does not match is worse than a
visibly failed deploy. On the very first deploy the step is expected to fail,
because `api/.env` does not exist yet; create it and re-run.

## Background jobs on cPanel

Neither job is needed to sign in and read the app. Both are needed for it to
send anything.

Under **Cron Jobs** in cPanel, with `<root>` the document root:

```cron
# The send queue. Every minute.
#
# Safe to run concurrently with itself — claiming uses FOR UPDATE SKIP LOCKED,
# and a unique index means one message can only ever have one job, so an
# overlapping run cannot become a duplicate send. The dispatch gates run here,
# immediately before the provider call, not at enqueue time.
* * * * * /usr/local/bin/php /home/<user>/<root>/api/bin/dispatch-worker.php --batch=25 >/dev/null 2>&1

# Resume journey runs whose delay has expired. Every five minutes.
*/5 * * * * /usr/local/bin/php /home/<user>/<root>/api/bin/journey-tick.php >/dev/null 2>&1

# The scheduled operational checks — reads overdue invoices from Books, live,
# and starts a journey run for any that has none. Once an hour is plenty.
#
# It stores no invoice: the rows are processed in memory and discarded, and
# every run re-reads its own invoice before drafting and again before sending.
# It is not a synchronisation job. See DATA_OWNERSHIP.md.
0 * * * * /usr/local/bin/php /home/<user>/<root>/api/bin/journey-tick.php --checks >/dev/null 2>&1

# Return a dead worker's claimed jobs to the queue. Every fifteen minutes.
#
# Deliberately a separate invocation: requeuing on every start would re-run a
# job a live worker is still processing.
*/15 * * * * /usr/local/bin/php /home/<user>/<root>/api/bin/dispatch-worker.php --requeue-stale >/dev/null 2>&1

# Company lifecycle (lib/company-lifecycle): run the purges AICOUNTLY Manage queued
# (rows, then attachment files and Drive documents) and send Manage the signed acks.
*/5 * * * * cd /home/<user>/<root>/api && /usr/local/bin/php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php >> /home/<user>/logs/messaging-company-lifecycle.log 2>&1

# Retention expiry (kit 1.1), daily: the audit trail a purge retained (CERT-In, 180 days) is deleted —
# archived first, recorded in aic_lifecycle_expiries — once 180 days old. Purged companies only.
37 3 * * * cd /home/<user>/<root>/api && /usr/local/bin/php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --expire-retained >> /home/<user>/logs/messaging-company-lifecycle.log 2>&1

# Drop purge archives (they hold the purged rows, personal data) after 90 days. Weekly.
30 3 * * 0 cd /home/<user>/<root>/api && /usr/local/bin/php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --drop-archives-older-than=90 --confirm >> /home/<user>/logs/messaging-company-lifecycle.log 2>&1
```

Appointments' client notices arrive on the service API and are dispatched inline,
so they need no job of their own; the queue cron above is what retries a message
the provider refused for now (and cancels one that outlived its `not_after`).

Before Appointments is allowed to send, a company needs (see
`APPOINTMENTS_MESSAGING_CONTRACT.md` §16): a connected WhatsApp/SMS channel; the four
appointment templates, created with
`php api/bin/seed-appointment-templates.php --cmp=<id>` (a dry run until `--apply`),
edited, submitted and **approved by the provider**; and `SERVICE_KEYS=appointments:<key>`.
Optional settings: `CONSENT_SERVICE_APPS`, `CONSENT_ACCEPT_UNVERIFIED`,
`SERVICE_ADDRESS_DAILY_CAP`, `SERVICE_COMPANY_PER_MINUTE`. The migration is
`007_messaging_service_api.sql` (additive; run `php api/bin/migrate.php`).

Check `/usr/local/bin/php` against the account's actual PHP binary — cPanel
often has several, and the CLI one is not always the default in `PATH`.

Without the queue cron, a message dispatched from the UI still goes out: the
controller processes the job inline so the agent sees the outcome rather than a
spinner that resolves somewhere else. What waits is anything queued by a
journey, and anything that needs a retry.

## Company lifecycle (AICOUNTLY Manage)

When a company's Messaging subscription lapses, AICOUNTLY Manage makes it read-only here and, 45 days
later, asks for its data to be deleted; when an AICOUNTLY account is closed it asks for that person's own
data to be deleted. Contract: manage-aicountly `docs/company-lifecycle/README.md`. Messaging carries
it out with the shared receiver kit, vendored unchanged at `server-php/lib/company-lifecycle/` (v1.1.0;
copy it again from manage-aicountly `Saas Common Handler/company-lifecycle` to upgrade — its README
§ "Upgrading from 1.0" is the checklist).

| Piece | Where |
| --- | --- |
| Tables | `009_aic_company_lifecycle.sql` (the kit's `schema.sql`, verbatim), `010_lifecycle_object_deletions.sql`, `011_aic_company_lifecycle_1_1.sql` (the kit's `schema-1.1.sql`, verbatim: `aic_lifecycle_expiries`, job status `held`). `bin/migrate.php` picks them up by file name; Messaging connects as the one database user that owns its tables, so there is no grant script — a deployment that splits the owner from the app user grants it `SELECT, INSERT, UPDATE, DELETE` on the five `aic_lifecycle_*` tables and `USAGE` on `aic_lifecycle_jobs_id_seq`, `aic_lifecycle_acks_id_seq`, `aic_lifecycle_expiries_id_seq`, and `CREATE` on the database (the archive schemas) |
| Receiver | `POST /api/internal/manage/company-lifecycle` — no session, the HMAC signature is its only credential (`src/Routes.php`, `src/Lifecycle/CompanyLifecycle.php`) |
| Write gate | `Context::assertAllowed()`, after Manage's company check: a write to a read-only company answers **423** `COMPANY_RESTRICTED` (never 403 — reads and exports keep working). Allow-list: `CompanyLifecycle::ALLOW`. Queued sends and journey runs of a read-only company wait. Manage's `companyinfo` `lifecycle` is written through on every company check |
| Machine writes (kit 1.1) | a product's service key with no signed-in person (`POST /api/v1/messages`, `…/cancel` from Appointments, Billing, Secretarial …) goes through the kit's `checkMachine()`: **423** `COMPANY_RESTRICTED` like a person, and the sending product defers the notice (kit `DeferredSend`) until renewal. `CompanyLifecycle::MACHINE_ALLOW` keeps exactly one thing open: **provider webhooks** (`POST /api/webhooks/{provider}/{connection}` — the company's customers writing in, delivery receipts, opt-outs). They are the customers, not the company, and a provider cannot replay them after a renewal; a dropped opt-out would be a consent breach. `WebhookService::receive()` passes them through the gate after resolving the tenant from the connection, so removing the entry is one tested line |
| Messaging as a sender | Messaging writes into one other product, Aicountly Drive (attachments, custodian API), always while a person waits. A Drive that is read-only for the company answers 423; `DriveClient` reads it with the kit's `DeferredSend::refusal()` and the person sees Drive's sentence — there is no outbox to defer into, nothing is retried, nothing is stored here instead |
| What is deleted | `server-php/config/lifecycle-manifest.php` (reviewed): every company table; the audit trail `messaging_audit_events` is **retained** under the CERT-In Directions (180 days: `retain_days: 180`, `retain_from: created_at`); account purge: the person's own dismissed suggestions |
| Retention expiry (kit 1.1) | the daily `--expire-retained` cron line above deletes a **purged** company's `messaging_audit_events` rows once `created_at` is 180 days old (archived first, one row per run in `aic_lifecycle_expiries`; Manage is not told). It is the only expiry on that table — Messaging has no sweep of its own, so nothing races it. A live (or renewed) company's audit trail is not expired by it |
| Outside the database | after the rows: attachment files in `MESSAGING_ATTACHMENT_DIR` and attachments held in Aicountly Drive (custodian delete), retried and recorded in `lifecycle_object_deletions`. Channel credentials are never stored here — the ack names the `credential_ref` / `webhook_secret_ref` secrets ops must remove from the server `.env` / Console |
| Worker | the cron lines above (`--bootstrap=config/lifecycle-kit.php` uses this API's own connection, whose name comes from Console) |
| Console monitors | `messaging.company_lifecycle` (every 5 min, tolerance 2.0) and `messaging.company_lifecycle_expiry` (daily, 86400 s, tolerance 1.25) — register both in Console's `cron_monitors` (console-react-app migration 059 pattern). Messaging has no ops kit yet (`bin/ops/run-job.php`), so the plain cron lines above do not report to them; until it does, `--status` and the log are the check |
| Legal hold | not used: Messaging records no holds (the kit's optional `legal_hold` option is not passed) |

Settings (`api/.env`, never the React build):

| Variable | |
| --- | --- |
| `LIFECYCLE_WEBHOOK_SECRET` | required; ≥ 32 characters (`openssl rand -hex 32`). The same value goes into Manage as `LIFECYCLE_WEBHOOK_SECRET_MESSAGING`. Unset: events answer 503 and nothing is restricted or purged |
| `MANAGE_API_URL` | required; `https://manage.aicountly.com` (sandbox `https://manage.gh.aicountly.com`). No default |
| `MANAGE_PRODUCT_SERVICE_KEY` | optional; Manage is asked just before each company purge whether it still stands |
| `LIFECYCLE_MANIFEST` | optional; overrides `config/lifecycle-manifest.php` |

Go live: deploy (the migrations run), set the two required variables, add the cron lines, then in
Manage set `LIFECYCLE_WEBHOOK_SECRET_MESSAGING` (and `LIFECYCLE_WEBHOOK_URL_MESSAGING` unless the catalog
host is right). Check with `php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php
--status`, and `--dry-run=company:<cmp_id>` / `--dry-run=account:<uuid>` for what a purge would do.
A migration that adds a table with `cmp_id` or `user_uuid` must add it to the manifest in the same commit
(`tests/lifecycle.php` fails until it does).

`php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --check-manifest` prints the
manifest's refusals for both scopes on this database and its warnings (exit 1 on a refusal): run it after
every migration. `tests/lifecycle.php` asserts there are none.

## Provider webhooks

Register each channel's webhook against the connection it belongs to:

```
https://messaging.aicountly.com/api/webhooks/{provider}/{connection-uuid}
```

The Channels & Trust screen shows the exact URL for each connection, along with
whether the webhook has been verified and when one last arrived.

These routes take no session — a provider has none, and demanding one would
silently drop every delivery receipt. They authenticate on the provider's own
signature over the raw body, using the secret named by that connection's
`webhook_secret_ref`. The tenant comes from the connection uuid in the URL,
never from the payload.

### Protecting the API's .env over HTTP

Because `api/` sits inside the document root, `.env` would be fetchable at
`https://messaging.aicountly.com/api/.env` unless Apache is told otherwise.
`server-php/.htaccess` ships the rule that denies it:

```apache
RedirectMatch 404 /\.(?!well-known)
```

The web build does the same for the document root via `web/public/.htaccess`,
but those rules stop applying inside `api/` once the API's own take over.

### The Authorization header

`server-php/.htaccess` also copies the `Authorization` header into the request
environment. Apache does not pass it to PHP under CGI/FastCGI unless told to,
and without it the auth relay forwards no credential — the portal answers 401
and sign-in fails for everyone, with nothing in the logs to explain why.

## Required secrets

Per environment, under Settings → Secrets and variables → Actions → Secrets:

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

Both workflows validate these before building, and verify SSH authentication
before writing anything to the server. Because the deploys run with
`--delete`, a `*_SSH_REMOTE_ROOT` that would resolve to the home directory
itself, a system directory, or anything containing `..` is refused.

## First deploy checklist

1. Create the subdomain in cPanel and note its document root.
2. Create the PostgreSQL database and user, and grant ALL PRIVILEGES.
3. Add the five SSH secrets for that environment.
4. Run **Deploy to cPanel …**. This deploys web and API together. The migration
   step at the end is **expected to fail** on a first deploy, because
   `api/.env` does not exist yet.
5. Create `api/.env` on the server (see above), from
   `server-php/.env.example`. Create `MESSAGING_ATTACHMENT_DIR` outside the
   document root while you are there.
6. Re-run **Deploy to cPanel …**. The migration step should now report what it
   applied.
7. Confirm `https://<host>/api/health` returns the right `env` and
   `"usable": true`, then open the site and sign in. See
   [auth/AICOUNTLY_AUTH_WORKFLOW.md](auth/AICOUNTLY_AUTH_WORKFLOW.md) for what a
   healthy login looks like.
8. Add the cron jobs (see above).
9. Connect a channel in **Channels & Trust**, set its credential variable in
   `api/.env`, and register the webhook URL the screen shows you.

At step 7, `"usable": true` with every integration reported as not connected is
the correct state for a fresh deploy. The product is up and honest about not
being able to send yet; it is not broken.
