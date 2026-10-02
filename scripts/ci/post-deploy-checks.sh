#!/usr/bin/env bash
# post-deploy-checks.sh — is the live Messaging really this app, and is it the build just deployed?
#
# Run by the deploy workflows after a deploy, and by verify-live.yml to check the live app without
# deploying anything. Every check goes through verify-live.sh, which passes only on the app's real
# answer, never on the host's anti-bot page, and repeats the request from the server (VERIFY_SSH)
# when the runner is shown that page.
#
# Usage: scripts/ci/post-deploy-checks.sh <production|sandbox>
#
# Environment:
#   VERIFY_SSH         command prefix that runs one command on the server ("ssh deploy-target"
#                      in the workflows); see verify-live.sh.
#   EXPECTED_ENTRY     the hashed entry script of the build just deployed, e.g.
#                      assets/index-C5tx8mVh.js from web/dist/index.html. Empty (checking without
#                      a deploy): the page's <title> is checked instead.
#   EXPECTED_REVISION  the commit just deployed. The Messaging API does not report the revision it
#                      runs, so it is not compared; the entry script stands for the build.
#   VERIFY_BASE_URL    tests only: check this origin (e.g. http://127.0.0.1:18777) instead of the
#                      environment's real one.
set -uo pipefail

target="${1:-}"
case "$target" in
  production) origin="https://messaging.aicountly.com" ;;
  sandbox) origin="https://messaging.gh.aicountly.com" ;;
  *) echo "usage: $0 <production|sandbox>" >&2; exit 2 ;;
esac
base="${VERIFY_BASE_URL:-$origin}"
verify="$(cd "$(dirname "$0")" && pwd)/verify-live.sh"
entry="${EXPECTED_ENTRY:-}"

echo "Checking Messaging ${target} at ${base}"
if [ -n "${EXPECTED_REVISION:-}" ]; then
  echo "The Messaging API does not report its revision, so ${EXPECTED_REVISION} is not compared; the web entry script stands for the build."
fi

failed=0
# check <verify-live.sh arguments...>: one check; a failure is counted, the rest still run.
check() {
  bash "$verify" "$@" || failed=$((failed + 1))
}
# check_with_hint <hint> <verify-live.sh arguments...>: the same, and prints <hint> when it warned.
check_with_hint() {
  local hint="$1" out
  shift
  out="$(bash "$verify" "$@")" || failed=$((failed + 1))
  printf '%s\n' "$out"
  case "$out" in
    *'::warning title=Post-deploy check::'*) echo "  ${hint}" ;;
  esac
}

# It is Messaging's API (fatal). usable:false means the database is unreachable or the schema is
# missing: configuration on the server, not this deploy, so it only warns. A channel is
# deliberately not part of usable: Messaging with nothing connected is up and usable, which is the
# correct state for a fresh deploy.
check_with_hint "usable is false: the database is unreachable or the schema is missing. Check the DB_* values in api/.env and the migrations." \
  json "Messaging API (${target})" "${base}/api/health" \
  '.app == "Messaging"' \
  '.usable == true'

# The web root serves the build just deployed, or at least the Messaging page (fatal).
if [ -n "$entry" ]; then
  check page "Messaging web (${target})" "${base}/" "$entry"
else
  check page "Messaging web (${target})" "${base}/" '<title>Messaging · Aicountly</title>'
fi

# Must never be served: what the deploy leaves under the document root that is a secret, a log,
# SQL, tests, scripts or dependency manifests. Read-only GETs of the first 64 KB, never of a .php
# file under tests/, bin/ or scripts/ (a GET would run it); a failure logs the status, type and
# size of what was served, never its content. /.git/HEAD may instead get the SPA's own page.
for path in /api/.env /api/.env.example /api/error_log \
  /api/database/migrations/001_messaging_channels.sql /api/tests/run.sh /api/bin/; do
  check absent "Messaging ${path} must not be served (${target})" "${base}${path}"
done
check absent "Messaging /.git/HEAD must not be served (${target})" "${base}/.git/HEAD" \
  '<title>Messaging · Aicountly</title>'

if [ "$failed" -gt 0 ]; then
  echo "${failed} post-deploy check(s) failed for Messaging ${target}."
  exit 1
fi
echo "All post-deploy checks passed for Messaging ${target}."
