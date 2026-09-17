#!/usr/bin/env bash
# Run the Messaging test suite against a throwaway PostgreSQL database and a
# local stub standing in for the Aicountly products Messaging reads from.
#
#   server-php/tests/run.sh
#
# Requires: php with pdo_pgsql and curl, and a reachable PostgreSQL.
#
# The .env it writes is a TEST .env and OVERWRITES any local one — which is why
# this exists as a script rather than as instructions saying "set these by hand".
# Every value in it is a placeholder; no real credential belongs here or
# anywhere else in the repository.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DB_NAME="${TEST_DB_NAME:-messaging_test}"
DB_USER="${TEST_DB_USER:-messaging_test}"
DB_PASS="${TEST_DB_PASS:-messaging_test}"
DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${TEST_DB_PORT:-5432}"
STUB_PORT="${STUB_PORT:-8795}"

cat > "$ROOT/.env" <<ENVEOF
APP_ENV=local
APP_PRODUCT_KEY=messaging
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS

# Every cross-product client points at the one stub.
MANAGE_API_BASE=http://127.0.0.1:$STUB_PORT
CONTACTS_API_BASE=http://127.0.0.1:$STUB_PORT
BOOKS_API_BASE=http://127.0.0.1:$STUB_PORT
SALES_API_BASE=http://127.0.0.1:$STUB_PORT
PAY_API_BASE=http://127.0.0.1:$STUB_PORT
APPOINTMENTS_API_BASE=http://127.0.0.1:$STUB_PORT
DRIVE_API_BASE=http://127.0.0.1:$STUB_PORT
REACH_API_BASE=http://127.0.0.1:$STUB_PORT

# Placeholder service keys. The tests assert on behaviour, never on a value.
MANAGE_SERVICE_KEY=test-manage-service-key-0123456789
CONTACTS_SERVICE_KEY=test-contacts-service-key-0123456789
BOOKS_SERVICE_KEY=test-books-service-key-0123456789
MESSAGING_SERVICE_KEY=test-messaging-service-key-0123456789

# app:key pairs for the inbound service contract. Placeholders, and the only
# reason they are here is so tests can prove the contract refuses a browser
# session and requires an Idempotency-Key. A real key never lives in a file.
SERVICE_KEYS=appointments:test-appointments-inbound-key-0123456789,billing:test-billing-inbound-key-0123456789

# Integrations are switched on per test with Features::overrideForTesting(),
# so the default here is OFF — which is also what exercises the
# graceful-degradation paths on every screen.
MESSAGING_WEBHOOK_BASE_URL=https://messaging.aicountly.test
ENVEOF

php "$ROOT/bin/migrate.php" > /dev/null

# Re-run the migrations to prove they are idempotent. A migration that only
# works on an empty database is a migration that fails on the next deploy.
php "$ROOT/bin/migrate.php" > /dev/null

php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null || true' EXIT

# Wait for the stub rather than sleeping a guessed amount.
for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$STUB_PORT/api/health" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

php "$ROOT/tests/integration.php"

# The HTTP layer, twice: once with the sibling products answering and once
# without.
#
# "Every dashboard still renders when Books is unreachable" and "business
# context refuses rather than guessing" are both promises this product makes,
# and only one of them can be tested with the stub running.
SIBLINGS=up php "$ROOT/tests/http.php"

kill $STUB_PID 2>/dev/null || true
wait $STUB_PID 2>/dev/null || true

SIBLINGS=down php "$ROOT/tests/http.php"
