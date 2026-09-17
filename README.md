# messaging-aicountly

**AICOUNTLY Messaging** — one place to talk to customers on WhatsApp, SMS and
RCS, with the business context needed to answer them, and a record of what was
actually sent.

A React single-page app built with Vite and TypeScript, with a plain-PHP API and
a PostgreSQL database alongside it. Both halves deploy to cPanel.

| Environment | App | API |
| --- | --- | --- |
| Production | https://messaging.aicountly.com | https://messaging.aicountly.com/api |
| Sandbox | https://messaging.gh.aicountly.com | https://messaging.gh.aicountly.com/api |

## The one thing to know before changing anything

**Messaging stores what Messaging owns. Everything else is read live from the
product that owns it, on the request that needs it.**

There is no database synchronisation between Aicountly products here — no
scheduled imports, no replication, no shared tables, no local mirror of
Contacts, Books, Sales or Inventory, and no persistent cache of another
product's business records. A balance on screen was fetched from Books while
that screen was rendering, and the screen says when.

If a change would add any of those, it is a breaking architectural change and
not an optimisation. [docs/DATA_OWNERSHIP.md](docs/DATA_OWNERSHIP.md) is the
short version; there are tests that fail if the forbidden columns appear.

## What it does

**Five workspaces.**

| Workspace | What it is for |
| --- | --- |
| **Command Centre** | What needs attention now: unanswered conversations, drafts awaiting review, delivery problems, response-time targets |
| **Unified Inbox** | Every channel in one thread list, with live business context beside each conversation and a composer that knows what the channel can carry |
| **Journeys & Templates** | Multi-step operational messaging — read a fact, ask a human, re-read it, send once — plus provider template management |
| **Business Outcomes** | What the messaging actually changed, with the evidence for each claim |
| **Channels & Trust** | Channel health, consent and suppression, delivery investigation, and what AI is permitted to do |

Plus a contacts browser (a live window onto Aicountly Contacts, not a copy),
settings, access control and the audit history.

**Things it deliberately will not do.**

- Send a message that promises a payment link when no link exists. The draft is
  refused server-side, and the Send button is disabled for the same reason.
- Treat an approval as still valid after the text was edited. Approving "₹4,800"
  is not authority to send "₹48,000".
- Send to somebody who opted out between the message being queued and the worker
  picking it up. Consent is re-read immediately before the provider call.
- Retry a send whose outcome is unknown. That is how a customer gets the same
  message twice; it waits for a human instead.
- Send anything during a simulation. Two independent mechanisms stop it, one of
  them a database constraint.
- Show a green tick for "the provider accepted it". A provider taking a message
  is not the customer receiving it.
- Show "0%" for a delivery rate with nothing sent. Unknown is not zero.
- Show a balance to somebody permitted to answer messages but not permitted to
  see money. Those are two separate grants.
- Substitute sample data when another product is unreachable. The screen says
  which product, and disables only what depended on it.

## Documentation

| Document | What is in it |
| --- | --- |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Request lifecycle, message lifecycle, exactly-once dispatch, the eight gates, journeys, adapters, AI |
| [docs/DATA_OWNERSHIP.md](docs/DATA_OWNERSHIP.md) | Who owns what, what may be stored, what may not |
| [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) | Every sibling product and channel provider, the five states, the published service contract |
| [docs/SECURITY.md](docs/SECURITY.md) | Identity, tenancy, permissions, credentials, consent, attachments, AI safety, auditing |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | cPanel deploys, migrations, the queue worker, the two `.env` files |
| [docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md) | Portal SSO |

## Layout

```
web/          React app (Vite). Builds to web/dist, deployed to the document root.
server-php/   PHP API. Deployed to the api/ folder inside the document root.
  src/        the application
  database/   numbered SQL migrations
  bin/        migrate.php, dispatch-worker.php, journey-tick.php
  tests/      run.sh and the suites it runs
docs/         the documents above
```

## Getting started

Requires Node.js 22 or newer, PHP 8.2 or newer with `pdo_pgsql`, and
PostgreSQL 14 or newer.

### The frontend

```bash
cd web
npm install
cp ../.env.example ../.env
npm run dev
```

The dev server runs on http://localhost:5173 and signs in through the
**sandbox** portal. Point `VITE_API_BASE_URL` at a running API — the deployed
sandbox (`https://messaging.gh.aicountly.com/api`) or your local one — and add
`http://localhost:5173` to `CORS_ALLOWED_ORIGINS` in that server's `.env`, since
localhost is the one case where the app and API are not same-origin.

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm run test:ui` | The frontend's logic and design-contract tests |
| `npm run preview` | Serve the production build locally |

### The backend

No build step and no dependencies — a hand-rolled PSR-4 autoloader, the same as
the other products in the fleet. There is no `composer.json` and no `vendor/`.

```bash
cd server-php
cp .env.example .env               # set APP_ENV=local and the DB_* values
php bin/migrate.php --status       # what would run
php bin/migrate.php                # apply it
php -S localhost:8000
```

Migrations are numbered, forward-only and idempotent. Each runs in its own
transaction and is recorded with a checksum, so a half-applied migration cannot
exist and an edited-after-applying file is reported rather than silently
reapplied.

### Background jobs

Neither is required to sign in and read the app; both are required for it to
send anything on a schedule.

```bash
# The send queue. Safe to run in several processes — claiming uses
# FOR UPDATE SKIP LOCKED. The dispatch gates run HERE, immediately before the
# provider call, not at enqueue time.
php bin/dispatch-worker.php                 # one pass, then exit
php bin/dispatch-worker.php --loop          # for a supervisor
php bin/dispatch-worker.php --requeue-stale # recover a dead worker's claims

# Resume journey runs whose delay has expired.
php bin/journey-tick.php
php bin/journey-tick.php --checks           # also run the scheduled source checks
```

On cPanel, run both from cron — see
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#background-jobs-on-cpanel).

### Tests

```bash
cd server-php && tests/run.sh      # needs a reachable PostgreSQL
cd web && npm run test:ui
```

`tests/run.sh` writes a **test** `.env` (overwriting any local one), applies the
migrations twice to prove they are idempotent, starts a local stub standing in
for the sibling products, and runs the domain suite plus the HTTP suite — the
latter twice, once with the siblings answering and once with the stub stopped.
Override the database with `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`,
`TEST_DB_USER`, `TEST_DB_PASS`.

## Environment variables

`.env` is git-ignored and is never deployed — `.env.example` is the tracked
template. There are two of them, and they work in opposite ways:

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

### The frontend's variables

| Variable | Description |
| --- | --- |
| `VITE_API_BASE_URL` | API base URL. Empty = this app's own origin + `/api` |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local`, `sandbox`, or `production` |
| `VITE_PRODUCT_KEY` | Portal product key. Derived from the hostname when unset |
| `VITE_PORTAL_LOGIN_URL` | Login portal override. Local development only |

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token, or password in a `VITE_` variable.

#### These are build-time values, not runtime values

Vite substitutes each `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files — **the app never reads a
`.env` from disk at runtime**, so placing a `.env` next to it in the cPanel
document root has no effect. Changing an endpoint means rebuilding and
redeploying.

This is the opposite of `server-php`, which is PHP and does read its own `.env`
on every request.

### The backend's variables

`server-php/.env.example` is the annotated list, in eight sections: the
database, public URLs, the sibling products, the inbound service contract, the
channel providers, attachments, AI, and the feature flags. Every value in it is
a placeholder.

Two things about it are worth knowing without reading it:

- **An absent service key is not an error.** That integration reports itself
  "not connected", the screens depending on it say so, and nothing else breaks.
  No integration is on by default — an integration is on when somebody has
  configured it, never because the code for it shipped.
- **Channel credentials are named, not stored.** A connection row holds the
  *name* of the environment variable; the adapter resolves the value at the
  moment of the call, and the API only ever returns a `credential_present`
  boolean.

## Deployment

Deployment is **manual only**. Nothing deploys on push or merge — both
workflows trigger exclusively via `workflow_dispatch`.

To deploy: **Actions** → pick a workflow → **Run workflow** → pick a branch →
**Run**.

| Workflow | Deploys | To |
| --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `server-php/`, then migrations | document root, then `api/` inside it |
| Deploy to cPanel Sandbox | `web/dist/` then `server-php/`, then migrations | document root, then `api/` inside it |

Production and sandbox deploy separately, so releasing to one cannot disturb the
other. Within one environment, web and API deploy together in the same run —
they always change in step. Source, `node_modules`, and `.env` never reach the
server.

Before deploying, each workflow checks that every required SSH secret is set,
that the remote root is a safe path, that no `.env` is about to be shipped, and
that every PHP file parses — so a misconfigured repository fails in seconds
instead of part-way through a deploy.

After the API is uploaded, the workflow runs `php api/bin/migrate.php` over SSH.
Migrations are additive and idempotent; nothing in `database/migrations/` drops
or rewrites data. If the step fails, the deploy fails loudly rather than leaving
an app running against a schema it does not match.

### Configuration

These repository **secrets** must be set (Settings → Secrets and variables →
Actions → Secrets):

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

`*_SSH_REMOTE_ROOT` is the document root to deploy into. It may be relative,
which is the usual cPanel form — `public_html` resolves against the SSH user's
home directory, giving `/home/<user>/public_html`. An absolute path works too.
Because the deploy runs with `--delete`, the workflow refuses a value that would
resolve to the home directory itself (`.`, `~`, empty), a system directory, or
anything containing `..`.

The repository **variables** `PROD_API_BASE_URL` and `SANDBOX_API_BASE_URL` are
optional. Unset, the app calls its own origin + `/api` — which is where the same
workflow puts the API. Set one only to point the app at a different API domain.

### Messaging on the rsync steps

Each workflow runs two `rsync --delete` steps, one after the other, and the
excludes are what make that safe.

The **web** step syncs the document root and excludes:

- `api/` — the PHP backend lives inside the document root and is deployed by the
  next step in the same run. **Without this exclude the web step would delete
  the entire API.**
- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

The **API** step syncs `api/` and excludes `.env`, `.env.*` and `.git*`: the
API's `.env` is created once on the server and read at runtime, so it must
survive every deploy. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

`web/public/.htaccess` ships with the build and provides the SPA history
fallback — which is also what serves the portal's `/auth/callback` landing — plus
cache headers (`index.html` uncached, hashed assets cached for a year).
