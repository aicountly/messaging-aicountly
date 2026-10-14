# AICOUNTLY company-lifecycle receiver kit (v1.0.0)

The one way an AICOUNTLY product receives Manage's **company lifecycle** decisions — view/export-only
after a lapsed subscription, then delete — and Manage's **account purge** (a closed AICOUNTLY account's
personal data), deletes what it must keep nothing of, keeps what the law requires, and tells Manage.

Manage decides **when**; the product decides **how**, through a reviewed manifest. Contract and
Manage's side: `manage-aicountly/docs/company-lifecycle/README.md`. Reference implementations built
before the kit: Books (`books-react-app/server-php`, migration 227) and Inventory
(`Inventory-aicountly/server-php`, migration 022) — same contract, their own code.

> **Copy, don't edit.** Copy this whole folder into the product (§1). Fix bugs here, bump `VERSION`,
> and copy again. Manage's `server-php/tests/company_lifecycle_kit_drift_test.php` pins the kit's
> signature and event list to Manage's own.

PHP ≥ 8.1, ext-pdo_pgsql, ext-json (ext-curl preferred; falls back to streams). No Composer, no
framework. Namespace `Aicountly\Lifecycle`. PostgreSQL only — every product backend is PostgreSQL
(surveyed 2026-10; only my.aicountly, which sends rather than receives, is MySQL), so there is no MySQL
variant; add one to `schema.sql` and `PurgeEngine` before a MySQL product integrates.

| File | What |
|---|---|
| `VERSION` | kit version (semver); record it in the product's commit message when vendoring |
| `autoload.php` | `require_once` it; maps `Aicountly\Lifecycle\` → `src/` |
| `schema.sql` | the four tables (`aic_lifecycle_events`, `_state`, `_jobs`, `_acks`) — the product's migration, verbatim |
| `src/Signature.php` | HMAC-SHA256 over `"{ts}.{body}"`, constant time, ±300 s |
| `src/Config.php` | `LIFECYCLE_WEBHOOK_SECRET`, `MANAGE_API_URL`, `MANAGE_PRODUCT_SERVICE_KEY` |
| `src/Receiver.php` | raw body + headers → validate → store → `[status, json]` (202 / 401 / 413 / 422 / 503) |
| `src/EventStore.php` | PDO on the kit's tables: idempotency by `event_id`, `occurred_at` ordering, jobs, acks |
| `src/ReadOnlyGuard.php` | allow / 423 `COMPANY_RESTRICTED` from state + method + path allow-list; companyinfo sync |
| `src/PurgeManifest.php` | loads + validates the product's reviewed manifest |
| `src/PurgeEngine.php` | archive-first purge in one transaction, refusals, dry run, read-back, restore, archives |
| `src/AccountPurge.php` | `account_purge_requested`: the manifest's `user_tables` (+ hooks) |
| `src/AckClient.php` | signed ack to Manage, retry policy; Manage `internal/company-status` pre-check |
| `src/Worker.php` | runs queued purges, refusals, retries, then due acks |
| `src/ManifestDiscovery.php`, `bin/discover-manifest.php` | prints a **draft** manifest from `information_schema` (never used at runtime) |
| `bin/worker.php` | cron entry + operator commands (status, dry run, requeue, archives, restore) |
| `src/Kit.php` | builds the pieces from the product's `.env`; framework adapters are 3 lines |
| `examples/` | CodeIgniter 4 and plain-PHP adapters, an example manifest, a `--bootstrap` file with hooks |
| `tests/` | `php tests/run.php` — framework-free, against a scratch PostgreSQL database |

## 1. Vendor

```bash
# from the product repo root (adjust server-php/ to the product's API folder)
rm -rf server-php/lib/company-lifecycle
cp -R "../manage-aicountly/Saas Common Handler/company-lifecycle" server-php/lib/company-lifecycle
rm -rf server-php/lib/company-lifecycle/tests      # optional: tests stay in Manage
git add server-php/lib/company-lifecycle
```

`server-php/lib/company-lifecycle/` is the convention (the deploy rsyncs `server-php/` wholesale, so it
ships). A product whose API folder is named differently uses `<api folder>/lib/company-lifecycle/`.
Load it once from the bootstrap: `require_once ROOTPATH . 'lib/company-lifecycle/autoload.php';`
(CodeIgniter 4: `ROOTPATH` is `server-php/`) or `require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';`.
Never let the web server serve `lib/` (CI4 serves only `public/`; for plain-PHP products check `.htaccess`).

## 2. The six integration steps

### Step 1 — migration
Copy `schema.sql` **unchanged** into the product's migration chain as the next number, e.g.
`server-php/database/migrations/NNN_aic_company_lifecycle.sql`, and register it the way that product
registers migrations (manifest file, catalog class, runner list). It is idempotent and additive. If the
product grants table rights to a separate app user, add the four tables (and the `aic_lifecycle_jobs_id_seq`,
`aic_lifecycle_acks_id_seq` sequences) to its grant script. The archive schemas the purge creates are
made by the same user that runs the worker, which therefore needs `CREATE` on the database
(`GRANT CREATE ON DATABASE <db> TO <user>;`).

### Step 2 — route
`POST /api/internal/manage/company-lifecycle`, **outside** every session, auth, CSRF, company-scope and
rate-limit filter — the HMAC is its only credential. Raw body in, `[status, json]` out:

```php
// CodeIgniter 4 controller (examples/adapter-codeigniter4.php)
[$status, $json] = service('lifecycleKit')->receiver()->handle((string) $this->request->getBody(), $this->request->headers());
return $this->response->setStatusCode($status)->setJSON($json);

// plain PHP (examples/adapter-plain-php.php)
[$status, $json] = $kit->receiver()->handle((string) file_get_contents('php://input'), getallheaders() ?: $_SERVER);
http_response_code($status); header('Content-Type: application/json'); echo json_encode($json);
```

Build the Kit once (`Kit::fromEnv('<app key>', manifest: <path of the manifest>)`; pass the product's
own `PDO` as the second argument when it already has one or resolves its database names from Console).
`<app key>` is the Manage catalog id (`books`, `inventory`, `sales`, `docs`, `chat`, …); pass
aliases in `Kit::fromEnv(..., aliases: ['drive'])` if Manage could name it differently. Add the route to
every route catalog the product keeps (security registries, smoke lists).

### Step 3 — guard hook
Where the product has **already** verified (Manage `companyinfo`) that the person may open the company —
so a caller who may not open it still gets 403 — add:

```php
$refusal = $kit->guard(self::LIFECYCLE_ALLOW)->check($cmpId, $method, $path);
if ($refusal !== null) { /* respond $refusal[0] (423) with JSON $refusal[1] */ }
```

and where the product reads Manage `companyinfo` (it is the source of truth; it corrects a missed event):

```php
$kit->guard()->syncFromCompanyInfo($cmpId, $companyInfo['data'] ?? []);
```

`LIFECYCLE_ALLOW` lists the writes that only read, render or export, plus the person's own UI state, as
`"METHOD /path"` patterns (`*` one segment, `**` anything): report queries, PDF/Excel/CSV exports,
previews, bulk downloads, preferences and read marks. GET/HEAD/OPTIONS always pass. Write a product test
that every allow-list entry matches a real route. Never map a restricted company to 403/404 — seats stay,
exports must work. Machine callers (other products, Manage's signed callbacks) are the product's call;
the reference receivers let them through. In the SPA, show a read-only banner on a 423 with
`code: COMPANY_RESTRICTED` (`message` and `restricted_until` are ready to display) and from companyinfo
`lifecycle` / `restricted_until`.

### Step 4 — manifest review
1. Draft it from a freshly migrated copy of the product's schema:
   `php lib/company-lifecycle/bin/discover-manifest.php --dsn="pgsql:host=127.0.0.1;dbname=<db>" --user=<u> --app=<key> --out=config/lifecycle-manifest.draft.php`
2. Review **every** line (the `// REVIEW:` notes say what to check), then save as
   `server-php/config/lifecycle-manifest.php` (any path; `LIFECYCLE_MANIFEST` can override it):
   * `company_tables` — every table holding company data, keyed by its company column; **children
     before parents**. A child that reaches the company only through a foreign key uses
     `via: {column, parent, parent_column}`. A table with both a company and a person column is company
     data (also list it in `user_tables` only if it is the person's own setting inside the company).
   * `company_retain` — tables the law requires keeping, each with its **legal reason** (statute and
     period). Typical: audit trails (Companies Act 2013 s.128 + Rule 3(1), 8 years), filed GST/TDS
     returns, e-invoices. They are counted, never copied or deleted, and reported to Manage.
   * `user_tables` — the person's own data that is not company data: personal settings, personal
     notes / calendars / chats / files, AI conversation history, sessions, device tokens. Use
     `only_if_null: "cmp_id"` on a table shared by personal and company rows.
   * `user_retain` — e.g. sign-in / security logs a statute requires (with the reason).
   * `ignore` — everything the scan found that is neither (reference data, the product's own
     bookkeeping), with why.
   * Remove `'draft' => true`. The runtime refuses a draft and any retained table whose reason is empty
     or says TODO.
3. Check it against the database: `php lib/company-lifecycle/bin/worker.php --env=.env --app=<key> --manifest=config/lifecycle-manifest.php --dry-run=company:<a real cmp_id>`
   and `--dry-run=account:<a real uuid>` on staging — counts per table, retained rows, or the refusals.
4. Keep it current: **strict mode** (default) refuses to purge while a table carrying one of a scope's
   key columns is in neither that scope's lists nor `ignore` — so a migration that adds a company table
   must add it to the manifest in the same commit. Add a product CI test that loads the manifest and
   asserts `(new PurgeEngine($pdo, PurgeManifest::load(...)))->problems('company') === []` (and `'user'`)
   on the migrated schema.

The engine also refuses, before writing anything: listed tables/columns that do not exist; a table
outside the purge list with an `ON DELETE CASCADE / SET NULL / SET DEFAULT` foreign key into a purged
table (it would change rows without archiving them — retained tables included); a child listed after
its parent. It never touches a table that is not in the manifest, nor `aic_lifecycle_*`.

### Step 5 — cron (ops kit job line)
Products with the ops kit (`server-php/bin/ops/jobs.txt`) add:

```
# Company lifecycle (lib/company-lifecycle): run the purges Manage queued and ack them. Monitor
# <app>.company_lifecycle must be registered in Console's cron_monitors (migration 059 pattern).
company-lifecycle | */5 * * * *   | {php} bin/ops/run-job.php --name=company-lifecycle --every=300 --monitor=<app>.company_lifecycle -- {php} lib/company-lifecycle/bin/worker.php --env=.env --app=<app> --manifest=config/lifecycle-manifest.php
```

Without the ops kit (cPanel crontab):

```cron
*/5 * * * * cd ~/public_html/api && php lib/company-lifecycle/bin/worker.php --env=.env --app=<app> --manifest=config/lifecycle-manifest.php >> ~/logs/<app>-company-lifecycle.log 2>&1
```

Use `--bootstrap=config/lifecycle-kit.php` instead of `--env/--app/--manifest` when the product passes
its own PDO, hooks (uploaded files, push tokens at a provider, search indexes — see
`examples/lifecycle-kit.bootstrap.php`) or an audit-trail callback (`on_finish`).

### Step 6 — environment (`server-php/.env`, never the SPA)

| Variable | Required | Meaning |
|---|---|---|
| `LIFECYCLE_WEBHOOK_SECRET` | yes | HMAC secret, ≥ 32 chars (`openssl rand -hex 32`). The same value goes into Manage as `LIFECYCLE_WEBHOOK_SECRET_<APP>`. Unset → events answer 503, nothing restricted or purged. |
| `MANAGE_API_URL` | yes | `https://manage.aicountly.com` (sandbox `https://manage.gh.aicountly.com`); a trailing `/api` is accepted. No default — a sandbox must never ack production. |
| `MANAGE_PRODUCT_SERVICE_KEY` | no | bearer for Manage `internal/company-status`, asked just before each company purge; a purge Manage has since reversed is refused. |
| `LIFECYCLE_MANIFEST` | no | overrides the manifest path passed to `Kit::fromEnv()` |
| DB settings | yes | the product's own: `LIFECYCLE_DB_DSN` (+ `_USER`/`_PASSWORD`), else `DATABASE_URL`, else `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`, else CI4 `database.default.*` |

**Go live**: deploy the product (migration, route, guard, manifest, cron) → set the secret in the
product → in Manage set `LIFECYCLE_WEBHOOK_SECRET_<APP>` (same value) and, unless the catalog host is
right, `LIFECYCLE_WEBHOOK_URL_<APP>` → Manage now sends this app everything (it is "integrated").
Update Manage's `docs/company-lifecycle/README.md` §10 integration table.

## 3. The contract (fixed)

### Manage → product
`POST {product}/api/internal/manage/company-lifecycle`, headers `X-Manage-Timestamp: <unix>`,
`X-Manage-Signature: sha256=<hex HMAC-SHA256("{ts}.{raw body}", LIFECYCLE_WEBHOOK_SECRET)>`,
`X-Manage-Event`, `Idempotency-Key` (= event_id).

| event | body | product does |
|---|---|---|
| `company_restricted` | `{event_id, event, cmp_id, app_key, reason: subscription_lapsed, occurred_at, restricted_until}` | company view/export-only here until `restricted_until` |
| `company_unrestricted` | `{…, reason: subscription_renewed}` | lift it |
| `company_purge_requested` | `{…, reason: subscription_lapsed \| account_closed \| owner_deleted}` | purge the company's data here (keep what the law requires), ack |
| `account_purge_requested` | `{event_id, event, uuid, app_key, reason: account_closed, occurred_at}` — **no cmp_id** | purge the person's own non-company data here (`user_tables`), ack |

Answers: `202 {status: accepted, event_id, cmp_id|uuid, outcome: applied|stale|queued, duplicate}`;
`401` bad/stale/missing signature; `413` > 64 KiB; `422 invalid_event` (unknown event, other app's
`app_key`, company event without `cmp_id` or with `uuid`, account event without `uuid` or with `cmp_id`);
`503` not configured / not recorded (Manage retries). Redelivery = same body, fresh timestamp → 202
`duplicate: true`. Company events apply in `occurred_at` order (older → `stale`, changes nothing; a stale
purge request is still queued and refused by the worker so Manage gets an answer).

### product → Manage: the ack
`POST {MANAGE_API_URL}/api/internal/company-lifecycle/ack`, `X-Product-App: <app>`, same timestamp and
signature headers, same secret:

```json
{"event_id":"…","cmp_id":42,"app_key":"books","status":"purged","retained":["public.audit_log"],"detail":"…"}
{"event_id":"…","uuid":"1001","app_key":"books","status":"purged","retained":["public.sign_in_events"],"detail":"…"}
```

`status`: `purged` | `nothing_to_purge` | `failed`. `retained`: the tables still holding the target's
rows by law (reasons in `detail`). Manage answers 200 (also to the same ack again). The kit retries
no-answer/401/5xx with back-off (1, 2, 4 … min, ≤ 6 h, 15 attempts) and stops at once on
400/404/409/410/413/422 (logged as an error; `worker.php --status` lists them).

### Lifecycle on companyinfo
Manage `GET companyinfo?comp_id=&app=<key>` carries `data.lifecycle` (`active | restricted |
purge_pending`) and `data.restricted_until`; restricted writes answer 423
`{code: "COMPANY_RESTRICTED", message, restricted_until}`; GET and the export allow-list pass.

## 4. What a purge does

1. **Refusals first** (ack `failed` with the reason, not retried): the company here is no longer
   `purge_pending`/`purged` (a newer event or companyinfo reversed it), or Manage's company-status says
   `active`/`restricted` (only when `MANAGE_PRODUCT_SERVICE_KEY` is set; unreachable → proceed on the
   signed request). Then the manifest checks (§2 step 4).
2. **One transaction**: `CREATE SCHEMA aic_lifecycle_archive_c<cmp_id>_<UTC YmdHis>` (account:
   `…_u<uuid>_…`) with an `_aic_archive` index; per table in manifest order copy the rows into the
   archive, delete them, check deleted = archived. Any error → everything rolls back, nothing changed.
3. **Read back** after commit: no rows left, all in the archive. Retained tables are counted only.
4. Hooks (files etc.), state → `purged`, `on_finish`, ack queued in the same transaction as the job's end.
5. A failure is retried 3 times (10, 20 min), then acked `failed` → Manage blocks the purge and alerts ops.

The archive reverses a purge until it is dropped. It holds personal data: drop it after the product's
archive window (`--drop-archives-older-than=90 --confirm`, e.g. in a weekly ops job).

## 5. Operator commands

```bash
php lib/company-lifecycle/bin/worker.php --env=.env --app=<app> --manifest=config/lifecycle-manifest.php [command]
  (none)                          run due purges (5) and acks (50) — the cron line
  --status                        jobs/acks by status, acks Manage refused or the kit gave up on, configuration
  --dry-run=company:42            what a purge would archive, delete, retain — or why it is refused
  --dry-run=account:1001
  --requeue=<event_id>            run a failed purge again after the fix (Manage accepts purged after failed)
  --list-archives
  --drop-archives-older-than=90 [--confirm]
  --restore=<archive schema> [--confirm]   put an archive back (parents first) — then tell Manage/ops by hand
  --install-schema                apply schema.sql (only for products without a migration runner)
```

| Symptom | Do |
|---|---|
| Manage alerts *purge blocked — answered failed* | `--status`, read the job's `detail`; fix (usually: a table missing from the manifest), then `--requeue=<event_id>` |
| `--status` lists failed acks (permanent 4xx) | read `last_error`; a 409 means Manage already holds a contradicting answer — escalate to Manage ops |
| Acks retrying | `MANAGE_API_URL` / secret / clock; they continue on their own once fixed |
| A purge must be undone | `--restore=<schema> --confirm` within the archive window; tell Manage ops (the Manage record says purged) |

## 6. Tests

```bash
pg_ctlcluster 16 main start          # or point AIC_LIFECYCLE_TEST_PG_DSN / _USER / _PASSWORD at a server
php tests/run.php                    # unit, receiver, purge engine, worker (each in its own scratch database)
```

Covered: signature (skew, tamper, other secret, short secret), contract parsing for both shapes,
idempotency and `occurred_at` ordering, the guard and companyinfo sync, manifest validation, every
refusal, dry run, archive + delete + retain + read-back, rollback, restore, the account scope, archives,
discovery, end-to-end worker with signed acks, refusals, retries → failed → requeue, ack back-off /
permanent / give-up, hooks, and the CLI. In Manage: `server-php/tests/company_lifecycle_kit_drift_test.php`.

## Changelog

* **1.0.0** (2026-10-14) — first release: company events + `account_purge_requested`.
