# E8-ID — identity outage semantics and trusted origins (messaging-aicountly)

Branch `claude/e8-id`, from origin/main d850f9d.

| Finding | Status | What changed | Evidence |
|---|---|---|---|
| MY-AW-04 family (a portal outage answered 401 "sign in again") | implemented | At HEAD, `Portal::validateSesKey()` returned null for every non-200, so a my.aicountly 5xx, timeout or unreadable answer signed the user out. New `Portal::checkSesKey()` gives valid / invalid / unavailable. Authenticated routes and `GET /api/session` answer **503 + Retry-After** (`auth_unavailable`) when the portal cannot say, and 401 only on the portal's own refusal. validatesession times out at 2 s / 6 s (was 8 / 15). Nothing is cached. | EXECUTED `tests/e8id_portal_outage.php` 7/7: real index.php over HTTP; fault listener gives 401 refusal → 401, and 502/503/HTML 200/empty 200/timeout → 503 + Retry-After within ~6 s |
| A20 (Host header picks sibling / Pulse environment) | already_fixed_at_head: `Environment` from AIC_ENVIRONMENT/APP_ENV; the Host header can only make the front controller REFUSE (`Environment::hostContradicts`), never choose | | |

Gates: `TMPDIR=/tmp/e8id_msg TEST_DB_NAME=e8id_messaging … STUB_PORT=33930 bash server-php/tests/run.sh` gives rc=0 (32, 108, 84, 41, 43, 43, portal outage 7).
