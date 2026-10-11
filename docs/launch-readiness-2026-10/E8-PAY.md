# E8-PAY — Messaging's side (messaging-aicountly)

Branch `claude/e8-pay` from `origin/main` (2026-10-11).

| id | status | what changed / evidence |
|---|---|---|
| F024 | implemented | `tests/service.php` writes two more exchanges from the real router and controllers: `environment_mismatch` (401 `service_environment_mismatch`, a call naming another deployment) and `service_company_not_bound` (403, a company that has not allowed Appointments, nobody signed in); the suite fails if either answer changes. `docs/APPOINTMENTS_MESSAGING_CONTRACT.md` names the environment header and the per-company binding (§2, §3, §11, §15, §16) and is byte-identical with appointments-aicountly's copy, which also holds the two fixtures byte-identically. EXECUTED `server-php/tests/run.sh` on PostgreSQL 16: all suites green. Harness: give the ecosystem Messaging server `SERVICE_KEY_COMPANIES` for its companies — E8-HARNESS. |
