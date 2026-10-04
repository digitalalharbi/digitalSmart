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

cleanup() {
  [[ -n "${API_PID:-}" ]] && kill "$API_PID" 2>/dev/null || true
  [[ -n "${WEB_PID:-}" ]] && kill "$WEB_PID" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Preparing $E2E_DB"
createdb "$E2E_DB" 2>/dev/null || true
(
  cd "$ROOT/backend"
  DB_DATABASE="$E2E_DB" php artisan migrate:fresh --seed --force --quiet
)

echo "==> Starting API on :$API_PORT"
(
  cd "$ROOT/backend"
  DB_DATABASE="$E2E_DB" php artisan serve --host=127.0.0.1 --port="$API_PORT"
) > /tmp/digitalsmart-e2e-api.log 2>&1 &
API_PID=$!

echo "==> Building and serving the SPA on :$WEB_PORT"
(
  cd "$ROOT/frontend"
  VITE_DEV_API_PROXY="http://127.0.0.1:$API_PORT" npm run build
) > /tmp/digitalsmart-e2e-build.log 2>&1

(
  cd "$ROOT/frontend"
  VITE_DEV_PORT="$WEB_PORT" VITE_DEV_API_PROXY="http://127.0.0.1:$API_PORT" npm run preview
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
VITE_DEV_PORT="$WEB_PORT" E2E_BASE_URL="http://127.0.0.1:$WEB_PORT" npx playwright test "$@"
