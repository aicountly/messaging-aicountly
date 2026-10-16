# Changelog — AICOUNTLY company-lifecycle receiver kit

## 1.1.0 (2026-10-16)

Backward compatible: a 1.0 manifest validates and behaves as before (the 1.0 test files run
unchanged). The only behaviour change: a purge 1.0 refused solely because a child was listed after its
parent through a DEFERRABLE foreign key now runs with `SET CONSTRAINTS ALL DEFERRED`.

Added
* **Row-level retention** — `mode: 'retain_referenced'` + `referenced_by` on a `*_tables` entry: rows a
  remaining row references (directly or through other `retain_referenced` tables) are kept, the rest
  purged; one recursive query over `pg_constraint` inside the purge transaction. `retain_where` (one SQL
  condition) keeps matching rows of a table and purges the others.
* **Children of retained parents** — a `via` entry may point at a `*_retain` table (the children are
  purged, the parent kept); `via.include_kept` for children of kept rows.
* **Retention expiry** — `retain_years` or `retain_days` + `retain_from` on retained entries (and
  `retain_where` entries); `bin/worker.php --expire-retained[=dry-run]` (daily) archives and deletes rows
  of purged companies / people once the period has passed, then the `retain_referenced` rows no longer
  referenced; records each table in `aic_lifecycle_expiries` (`schema-1.1.sql`). Manage is not told. The
  validator warns (never refuses) about retained entries without a period.
* **Scrub** — `scrub: {"table.column": null | redact | json_paths:[…]}` applied to archive copies and to
  retained / kept rows at purge time (pg_temp helpers; nothing installed in the product's schema).
* **FK cycles** — deferrable cycles are deferred; `break_cycle: ['table.column']` NULLs the column on
  purged rows after archiving them; restore relinks (needs a primary key).
* **key_columns** — a row belongs to the target when any of several columns matches.
* **HookContext** — hooks' third argument: `archiveRows()` (into the purge's archive; restore upserts by
  primary key), `retain()` (statutory data outside the manifest → ack `retained[]`). Before-hooks
  (`company_before`, `account_before`).
* **Legal hold** — Kit option `legal_hold`: a reason stops the purge; ack status `held` with
  `detail: {reason}`, job `held`, re-checked daily, `purged` once lifted; expiry skips held targets.
* **Machine-caller gating** — `ReadOnlyGuard::checkMachine()` and `machineAllow` (Kit::guard's second
  argument): machine writes into a restricted company answer 423 by default.
* **DeferredSend** — the sender side of a 423 `COMPANY_RESTRICTED`: `deferred_restricted`,
  `dropped_company_deleted`, hourly Manage company-status re-check (`Kit::deferredSend()`).
* `bin/worker.php --check-manifest`; `--status` shows `schema_1_1` and manifest warnings; `--dry-run`
  shows warnings. `ManifestDiscovery` drafts `retain_years` / `retain_from` for retained guesses.
* `schema-1.1.sql`: `aic_lifecycle_expiries`; job status `held`.

## 1.0.0 (2026-10-14)

First release: company events (`company_restricted`, `company_unrestricted`, `company_purge_requested`)
and `account_purge_requested`; receiver, guard, reviewed manifest, archive-first purge engine, worker,
signed acks.
