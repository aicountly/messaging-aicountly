# Changelog — AICOUNTLY company-lifecycle receiver kit

## 1.1.1 (2026-10-16)

Backward compatible: 1.0 and 1.1 manifests validate and purge exactly as before (every 1.0 / 1.1 test
file runs unchanged); `schema-1.1.1.sql` is optional. What changes is retention expiry, and two
`--check-manifest` refusals that are gone because expiry now handles what they guarded against.

Added
* **Retention groups** — a retained row with a period (`company_retain` / `user_retain`, or a
  `retain_where` row) is the root of a group: itself and every row that references it through foreign
  keys, recursively — CASCADE, NO ACTION and RESTRICT always (deleting the root deletes them or is
  refused), SET NULL / SET DEFAULT only into tables an `expires_with` names (otherwise the row merely
  points back — an actor, a representative — and the database nulls its link); tables without a
  company column included (found with `pg_constraint`), down to the next root: a retained row with its own period inside a group is
  contained — it must be due for the group to go, and it expires on its own (a container waits for its
  last child, never the other way round). Groups that share a row wait for each other.
  `--expire-retained` deletes a group only when **all** of it is due — its root past its own period;
  members without a period inherit it, so a retained child with a longer period delays its parent — and no row of another company / person would
  go with it (CASCADE / NO ACTION / RESTRICT reference from another target: the group is reported in
  `blocked`; another target's SET NULL references are left to the database). Then every member row is
  archived (scrubbed) into the expiry archive, deleted children first in the same transaction, and
  checked gone (by position and by primary key). `expire()` reports `groups`, `waiting`, `blocked` and
  `archive_tables`; restore puts a group back parents first.
* **No more refusal for retained parents with children** — 1.1 refused a period on a retained table
  with CASCADE / SET NULL children outside the manifest, and required period tables in child-first
  order. Both are gone: the children expire with the parent. `PurgeEngine::warnings()` (and
  `--check-manifest`, as `expiry_groups` beside the warnings) name, once per root table, the tables that expire with it and which of them the
  manifest does not list.
* `expires_with: [tables]` on a retained entry with a period — the tables the reviewer expects in its
  groups; refused when the foreign keys do not reach one (its rows would be kept for ever). A retained
  table named there needs no period of its own and no longer warns (a period of its own would let it
  expire alone, before its parent). `{table, from: 'signed_at'}` in the list: a member row also holds
  its group until its own date + the root's period (the group runs from its latest record) without
  becoming a root of its own; `{table, column: 'engagement_id'}` makes a table that points at the root
  by a column without a foreign key part of the group as if it had one; `not_before_root: true` makes
  the rows of a retained table with its own period that belong to the group wait for it (an audit
  trail whose firm-level rows keep their own period).
* `expiry_session_settings: {'aicountly.lifecycle_purge': 'on'}` — custom settings `SET LOCAL` inside the
  expiry transaction only, so append-only / immutable triggers let the worker delete expired rows.
* `hooks['expiry_after']` (Kit option) — `fn (string $scope, string $target, array $result, PurgeEngine $engine): ?string`,
  after an expiry committed: delete the stored files of the expired rows (read their keys from
  `$result['archive_tables']` in `$result['archive']`). Errors are logged, never undo the expiry.
* `schema-1.1.1.sql` (optional, additive): `aic_lifecycle_expiries.group_id / group_root / group_count`
  and reason `expired_with_group`. Without it groups still expire and every table is recorded as
  `retention_ended`. `EventStore::hasSchema111()`; `--status` shows `schema_1_1_1`; `--install-schema`
  applies it.
* Products' `retention_anchor_at`-style columns keep working (`retain_from` may be any date column);
  with groups they are no longer needed for new manifests.

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
