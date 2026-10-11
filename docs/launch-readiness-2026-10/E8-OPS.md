# E8-OPS — operations, deploy safety and test gates (launch readiness 2026-10)

Package E8-OPS of the eight-product launch-readiness corrections, redone on current `main`
(branch `claude/e8-ops`, merged with `origin/main` before the final gates). This file covers what
the package changed **in this repository**; the estate-wide pieces (ops kit, contract graph, repair
register, secret-exposure kit) live in `console-react-app/ops/estate/`.

Evidence labels: EXECUTED (run here, on PostgreSQL 16 / real HTTP where it matters), CONTRACT,
STATIC, OPERATIONALLY-PENDING (an operator step; prepared, not performed).


## Findings touched in `messaging-aicountly`

| Finding | Status | What was done / evidence |
|---|---|---|
| F096 | implemented | Staged schema-before-code release (bin/ops/release.sh: stage outside docroot, pre-migration snapshot, migrate from stage, switch, jobs) in pay/sales/purchases/billing/pos/books/Inventory; production deploys main-only, bound to GitHub environments, cancel-in-progress false, host keys pinned (StrictHostKeyChecking=yes, appleboy fingerprints) in 16+ repos. Kit/release tests EXECUTED (router 27-29/0, books 17/0, inventory 16/0). Environment reviewers and *_SSH_KNOWN_HOSTS secrets are operator settings. |
| F109 | implemented | tests.yml (workflow_dispatch/workflow_call) + deploy job needs: verify in crm, appointments, messaging, advisor, vault, drive, lobby, calendar (hrms already gated). Suites EXECUTED locally on PostgreSQL: crm 109/0, appointments 54/0, messaging 43/0, advisor 174/0, vault 96/0, drive 33/33 files, calendar 152/0 + HTTP 97/0, lobby 152/152; web checks all green. |
| F378 | implemented | Production deploys: main-only + environment; cancel-in-progress false (drive/hrms included); usable:false is fatal in production post-deploy for crm, appointments, messaging, drive (advisor, vault, calendar, hrms already fatal). |

## Gates run for this repository (2026-10-11, after merging origin/main)

* server-php/tests/run.sh: 43 passed, 0 failed (EXECUTED, PostgreSQL 16); web typecheck + test:ui (EXECUTED).
* Workflow rule scan over all 98 workflows in the 22 E8-OPS checkouts: every trigger is workflow_dispatch / workflow_call, every file parses (EXECUTED).
* console-react-app/ops/estate/secret-incident/check-literals.sh over the 22 checkouts: no known secret shape at HEAD (EXECUTED).

## Operator steps this package leaves (none performed here)

* GitHub: set required reviewers and the deployment-branch rule on the `production` / `sandbox`
  environments; store each product's `*_SSH_KNOWN_HOSTS` (and `*_SSH_FINGERPRINT` for appleboy
  actions) — deploys refuse to trust an unpinned host key unless `DEPLOY_ALLOW_KEYSCAN=true`.
* Deploy receivers before their consumers (the receiver preflight stops a deploy otherwise; the order
  is in `console-react-app/ops/estate/CONTRACT_GRAPH.md`).
* A08: access-log review, rotation in the documented order, provider revocation (Alankit), evidence in
  `console-react-app/ops/estate/secret-incident/tracker.csv`.
* A27 / F089: work through `python3 ops/estate/repair-register/register.py plan` per company, recording
  dry-run, apply and post-check (or a dated risk acceptance).
* Route Console's Cron Job Monitor alerts to a person; confirm the first heartbeat of each job after the
  first staged deploy.

