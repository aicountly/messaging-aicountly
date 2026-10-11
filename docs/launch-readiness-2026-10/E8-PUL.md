# E8-PUL — Messaging (branch claude/e8-pul)

| Finding | Status | What changed | Evidence |
|---|---|---|---|
| F104 | implemented | `Channels\ConsoleSecrets` resolves `console:<name>` channel secrets with Messaging's **own** Console AI service identity key (`CONSOLE_AI_SERVICE_KEY`) for its real hostname (`messaging.aicountly.com` production, `messaging.gh.aicountly.com` sandbox/local, `MESSAGING_CONSOLE_DOMAIN` override) instead of the estate `CONSOLE_SERVICE_KEY` and the slug `messaging` (which Console answered 404 `unknown_domain`). Console migration 061 (console-react-app, claude/e8-pul) creates the domains and the `messaging` / `messaging-sandbox` identities. | EXECUTED: `tests/console_real.php` against the REAL Console API (console-react-app claude/e8-pul, PostgreSQL, Console's own libraries for seeding): own key resolves the secret; the estate key gets 403 `shared_key_saas_domain` even with the transition window open; estate-key-only config resolves nothing; a revoked key fails closed — 6/6. `tests/run.sh` (PostgreSQL `e8pl_messaging_test`): 151 + 84 + 41 + 43 + 43 passed, 0 failed (the stub now refuses the estate key and the slug domain too). |

Operator step (OPERATIONALLY-PENDING): in Console `php scripts/ai_service_identity.php mint messaging` (and
`messaging-sandbox`), set the key as `CONSOLE_AI_SERVICE_KEY` in Messaging's `api/.env`. Only deployments
with `console:<name>` channel references need it; variable-name references are unaffected. Ordered
checklist: console-react-app `docs/AI_KEY_TRANSITION_CHECKLIST.md`.
