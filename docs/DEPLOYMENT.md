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
DB_NAME=<cpaneluser>_messaging
DB_USER=<cpaneluser>_messaging
DB_PASS=...

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
`<cpaneluser>_messaging` — use the full prefixed names in `.env`, and
`DB_HOST=localhost`, because on cPanel the database is on the same machine.

### Migrations

```bash
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
```

Check `/usr/local/bin/php` against the account's actual PHP binary — cPanel
often has several, and the CLI one is not always the default in `PATH`.

Without the queue cron, a message dispatched from the UI still goes out: the
controller processes the job inline so the agent sees the outcome rather than a
spinner that resolves somewhere else. What waits is anything queued by a
journey, and anything that needs a retry.

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
