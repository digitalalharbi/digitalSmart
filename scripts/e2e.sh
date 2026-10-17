#!/usr/bin/env bash
#
# Run the end-to-end suite against an isolated stack.
#
# The suite signs in, creates clients and creates projects. Pointing it at the
# development database would steadily fill a developer's workspace with test
# records, so it gets its own database, migrated and seeded fresh each run.
#
# Usage:  scripts/e2e.sh [playwright args...]
#         PORT=5178 scripts/e2e.sh --project=chromium

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
E2E_DB="${E2E_DB:-digitalsmart_e2e}"
API_PORT="${API_PORT:-8011}"
WEB_PORT="${VITE_DEV_PORT:-5178}"

# `kill` on a subshell leaves the real server orphaned, still holding the port —
# and the next run then talks to a stale process with the previous run's
# configuration, which fails in ways that look like application bugs. Each
# server is started with `exec` so the recorded PID *is* the server, and the
# ports are checked before anything starts.
cleanup() {
  for pid in "${API_PID:-}" "${WEB_PID:-}"; do
    [[ -z "$pid" ]] && continue
    pkill -P "$pid" 2>/dev/null || true
    kill "$pid" 2>/dev/null || true
  done

  # And then whatever still holds either port.
  #
  # Both servers put processes beyond the reach of the recorded PID. `artisan serve` runs
  # `php -S` as a child which forks PHP_CLI_SERVER_WORKERS grandchildren; `npm run preview`
  # runs `vite preview` through a shell. Killing the PID and its direct children leaves the
  # rest reparented to init and still listening — and a leaked preview server is worse than
  # a leaked API one, because it keeps a Node process busy rather than idle. Ten of them
  # accumulated across runs before this existed, and the machine they were on was the one
  # running the tests.
  for port in "$API_PORT" "$WEB_PORT"; do
    local holders
    holders=$(lsof -nP -iTCP:"$port" -sTCP:LISTEN -t 2>/dev/null || true)
    [[ -n "$holders" ]] && kill $holders 2>/dev/null || true
  done
}
trap cleanup EXIT

require_free_port() {
  local port="$1" what="$2"
  if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "Port $port is already in use ($what). Stop it, or set a different port." >&2
    lsof -nP -iTCP:"$port" -sTCP:LISTEN >&2
    exit 1
  fi
}

require_free_port "$API_PORT" "API_PORT"
require_free_port "$WEB_PORT" "VITE_DEV_PORT"

# Read the application's own connection settings, so the database is created
# by the role the application connects as. Creating it as the shell user
# instead leaves the app without privileges on it — and the failure is easy to
# miss, which is why nothing here is run with --quiet.
env_value() {
  local key="$1" default="$2"
  local line
  line=$(grep -E "^${key}=" "$ROOT/backend/.env" 2>/dev/null | tail -1) || true
  local value="${line#*=}"
  value="${value%\"}"; value="${value#\"}"
  echo "${value:-$default}"
}

DB_HOST="$(env_value DB_HOST 127.0.0.1)"
DB_PORT="$(env_value DB_PORT 5432)"
DB_USERNAME="$(env_value DB_USERNAME digitalsmart)"
DB_PASSWORD="$(env_value DB_PASSWORD digitalsmart)"

echo "==> Preparing $E2E_DB as $DB_USERNAME"
PGPASSWORD="$DB_PASSWORD" createdb -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$E2E_DB" 2>/dev/null || true
# Password hashing is deliberately cheap for this run, and the seed has to agree with the
# server or nothing signs in.
#
# This is not a corner cut for speed alone. `.env` sets 12 rounds, which is right for
# production and costs a few hundred milliseconds of pure CPU per verification. Every test
# here signs in, two browser projects run at once, and PHP's built-in server hands each
# request to a worker for the whole of it — so a burst of sign-ins fills the accept queue and
# the browser is told the server is unreachable. That failure reads exactly like an
# application bug and was mistaken for one.
#
# Four rounds is what `phpunit.xml` already uses for the other suites. It is safe here for the
# same reasons: this database is created fresh, dropped at the end, and holds only seeded demo
# passwords. Nothing about the application's own hashing configuration changes.
E2E_BCRYPT_ROUNDS="${E2E_BCRYPT_ROUNDS:-4}"

(
  cd "$ROOT/backend"
  DB_DATABASE="$E2E_DB" BCRYPT_ROUNDS="$E2E_BCRYPT_ROUNDS" php artisan migrate:fresh --seed --force

  # E2E_AGENCY=1 builds the whole sandbox agency on top — sixty clients, a year of habits, every role's
  # working day — so the role journeys run against what a Smart Code morning actually looks like. Off by
  # default: the rest of the suite makes assumptions about the small base demo.
  if [[ "${E2E_AGENCY:-}" == "1" ]]; then
    DB_DATABASE="$E2E_DB" BCRYPT_ROUNDS="$E2E_BCRYPT_ROUNDS" php artisan demo:agency
  fi
)

# Sanctum only establishes a session for requests it recognises as first-party,
# and CORS only admits the listed origin. Both are keyed by host *and port*, so
# they have to follow whichever port this run uses — otherwise every request is
# treated as third-party and nothing authenticates.
E2E_ORIGIN="http://127.0.0.1:$WEB_PORT"
E2E_STATEFUL="127.0.0.1:$WEB_PORT,localhost:$WEB_PORT"

# Every browser in the run shares one address, so a whole suite's traffic lands
# in a single per-IP bucket sized for one human. The limiters are left in force
# — just budgeted for a test run rather than a person. Per-account protection,
# which is the one that matters against brute force, still applies.

# PHP's built-in server accepts one connection at a time unless it is told to
# fork. Two browser projects run at once and every dashboard widget issues its own
# analytics query, so on one worker a sign-in queues behind several half-second
# aggregations and the browser eventually reports the server as unreachable — a
# harness limit that reads exactly like an application bug.
#
# Workers alone were not enough: the dominant cost was password hashing at production
# rounds, which is why `E2E_BCRYPT_ROUNDS` is set above. Both matter, and the hashing
# mattered more. `--no-reload` is required
# for the workers to be created at all — Laravel warns and falls back to one
# otherwise — and costs nothing here, because the suite runs a build rather than code
# somebody is editing.
#
# Sixteen is headroom, not a measured optimum. Enough that a browser never waits on an
# accept queue, few enough that the forked processes are not themselves the load.
echo "==> Starting API on :$API_PORT"
(
  cd "$ROOT/backend"
  PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-16}" \
  DB_DATABASE="$E2E_DB" \
  BCRYPT_ROUNDS="$E2E_BCRYPT_ROUNDS" \
  FRONTEND_URL="$E2E_ORIGIN" \
  SANCTUM_STATEFUL_DOMAINS="$E2E_STATEFUL" \
  RATE_LIMIT_API_GUEST="${RATE_LIMIT_API_GUEST:-1000}" \
  RATE_LIMIT_API_USER="${RATE_LIMIT_API_USER:-1000}" \
  RATE_LIMIT_AUTH="${RATE_LIMIT_AUTH:-500}" \
  RATE_LIMIT_HEALTH="${RATE_LIMIT_HEALTH:-1000}" \
  exec php artisan serve --host=127.0.0.1 --port="$API_PORT" --no-reload
) > /tmp/digitalsmart-e2e-api.log 2>&1 &
API_PID=$!

echo "==> Building and serving the SPA on :$WEB_PORT"
# The build runs `tsc -b`, which is stricter than a bare `tsc --noEmit` (it applies
# the app's own tsconfig, including noUnusedLocals). A failure here is a real type
# error, so it is reported with its output rather than leaving the next step to fail
# with a confusing connection refused.
if ! (
  cd "$ROOT/frontend"
  VITE_DEV_API_PROXY="http://127.0.0.1:$API_PORT" npm run build
) > /tmp/digitalsmart-e2e-build.log 2>&1; then
  echo "The SPA build failed:" >&2
  tail -30 /tmp/digitalsmart-e2e-build.log >&2
  exit 1
fi

(
  cd "$ROOT/frontend"
  VITE_DEV_PORT="$WEB_PORT" VITE_DEV_API_PROXY="http://127.0.0.1:$API_PORT" exec npm run preview
) > /tmp/digitalsmart-e2e-web.log 2>&1 &
WEB_PID=$!

echo "==> Waiting for the stack"
for _ in $(seq 1 60); do
  api=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$API_PORT/api/health" || true)
  web=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$WEB_PORT/" || true)
  if [[ "$api" == "200" && "$web" == "200" ]]; then
    echo "    api $api, web $web"
    break
  fi
  sleep 1
done

if [[ "${api:-}" != "200" || "${web:-}" != "200" ]]; then
  echo "The stack did not come up. Logs:" >&2
  tail -20 /tmp/digitalsmart-e2e-api.log /tmp/digitalsmart-e2e-web.log >&2
  exit 1
fi

echo "==> Running Playwright"
cd "$ROOT/frontend"

# The suite caches one signed-in session per role so it does not re-authenticate on every test.
# Those sessions belong to the database this run just created and dropped, so a session left over
# from a previous run points at a user that no longer exists. Cleared here rather than trusted.
rm -rf test-results/.sessions

VITE_DEV_PORT="$WEB_PORT" E2E_BASE_URL="http://127.0.0.1:$WEB_PORT" npx playwright test "$@"
