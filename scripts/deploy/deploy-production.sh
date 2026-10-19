#!/usr/bin/env bash
# Production deploy for digitalSmart — runs ON the production server, called by .github/workflows/deploy-production.yml.
#
# Release-based, so a failed deploy never touches the running site:
#
#   $DEPLOY_ROOT/
#     repo/                 a clean clone of digitalalharbi/digitalSmart — never edited by hand
#     releases/<sha>/       one directory per deployed commit, exported from repo/ (no .git, no local edits)
#     shared/.env           production configuration and runtime/provider/model credentials — never in Git
#     shared/storage/       Laravel storage: uploads, logs, cache — survives every deploy
#     shared/deploy.env     which services this server runs (see docs/DEPLOYMENT.md)
#     shared/deployments.log  one line per deploy: time, previous, new, status, duration
#     current -> releases/<sha>   the live release; switched atomically
#
# Order, and why: build the new release beside the live one → migrate (forward only; a failure stops here with the
# old release still serving) → cache → switch `current` atomically → reload long-running processes so no worker runs
# old code → health check → on failure, switch back to the previous release and reload again. Migrations are never
# rolled back automatically: application rollback and database rollback are different decisions (docs/DEPLOYMENT.md).
#
# Never, on production: migrate:fresh, migrate:reset, migrate:refresh, db:wipe, db:seed, demo:agency. The governance
# guard fails the build if this file or the workflow ever contains one of them as a command.
set -Eeuo pipefail

SHA="${1:?usage: deploy-production.sh <commit-sha>}"
DEPLOY_ROOT="${DEPLOY_ROOT:?DEPLOY_ROOT must be set (PRODUCTION_PATH)}"
REPO="$DEPLOY_ROOT/repo"
RELEASES="$DEPLOY_ROOT/releases"
SHARED="$DEPLOY_ROOT/shared"
CURRENT="$DEPLOY_ROOT/current"
LOG="$SHARED/deployments.log"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
MIN_FREE_MB="${MIN_FREE_MB:-2048}"
STARTED="$(date -u +%s)"
STAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
PREVIOUS="$(readlink "$CURRENT" 2>/dev/null | xargs -r basename || true)"
RELEASE="$RELEASES/$SHA"

say()  { printf '==> %s\n' "$*"; }
fail() { printf '✗ %s\n' "$*" >&2; record "failed: $*"; exit 1; }
record() {
  local duration=$(( $(date -u +%s) - STARTED ))
  printf '%s\tprevious=%s\tnew=%s\tstatus=%s\tduration=%ss\n' "$STAMP" "${PREVIOUS:-none}" "$SHA" "$1" "$duration" >> "$LOG" 2>/dev/null || true
}

# ---------------------------------------------------------------- preflight
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || fail "not a full commit sha: $SHA"
[ -d "$REPO/.git" ]           || fail "$REPO is not a clone of the repository (see docs/DEPLOYMENT.md, first-time setup)"
[ -f "$SHARED/.env" ]         || fail "$SHARED/.env is missing — production configuration lives on the server, never in Git"
grep -qE '^APP_ENV=production\s*$' "$SHARED/.env" || fail "shared/.env: APP_ENV is not production"
grep -qE '^APP_DEBUG=false\s*$'    "$SHARED/.env" || fail "shared/.env: APP_DEBUG is not false"
FREE_MB="$(df -Pm "$DEPLOY_ROOT" | awk 'NR==2 {print $4}')"
[ "${FREE_MB:-0}" -ge "$MIN_FREE_MB" ] || fail "only ${FREE_MB} MB free on the deploy disk; ${MIN_FREE_MB} MB required"

# Which services this server actually runs. Nothing is restarted that is not named here.
# shellcheck disable=SC1091
[ -f "$SHARED/deploy.env" ] && source "$SHARED/deploy.env"
APP_URL_HEALTH="${APP_URL_HEALTH:?shared/deploy.env must set APP_URL_HEALTH (e.g. https://app.example.com/api/health)}"
FRONTEND_URL_CHECK="${FRONTEND_URL_CHECK:-}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-}"          # e.g. php8.4-fpm — reloaded only if set
REVERB_RESTART_COMMAND="${REVERB_RESTART_COMMAND:-}"  # e.g. "sudo supervisorctl restart reverb" — only if set
USE_HORIZON="${USE_HORIZON:-true}"

exec 9>"$DEPLOY_ROOT/.deploy.lock"
flock -n 9 || fail "another deploy holds the lock"

# ---------------------------------------------------------------- the commit, from a clean clone
say "Fetching origin into a clean clone"
git -C "$REPO" fetch --prune origin main
git -C "$REPO" cat-file -e "$SHA^{commit}" || fail "$SHA is not in origin"
git -C "$REPO" merge-base --is-ancestor "$SHA" origin/main || fail "$SHA is not on main"
# The clone is never a working copy: detached at the commit, and any stray local change is an error, not a merge.
git -C "$REPO" checkout --quiet --detach "$SHA"
[ -z "$(git -C "$REPO" status --porcelain)" ] || fail "the server clone has local changes — production is not edited by hand"

# ---------------------------------------------------------------- build the release beside the live one
if [ -d "$RELEASE" ] && [ "$(readlink "$CURRENT" || true)" = "$RELEASE" ]; then
  say "$SHA is already live; reloading and checking health only"
else
  rm -rf "$RELEASE.tmp"
  mkdir -p "$RELEASE.tmp"
  git -C "$REPO" archive "$SHA" | tar -x -C "$RELEASE.tmp"

  # Shared, persistent state: the release never owns it, so replacing a release never deletes it.
  rm -rf "$RELEASE.tmp/backend/storage"
  ln -s "$SHARED/storage" "$RELEASE.tmp/backend/storage"
  ln -s "$SHARED/.env" "$RELEASE.tmp/backend/.env"

  say "Backend dependencies"
  (cd "$RELEASE.tmp/backend" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress)

  say "Frontend build"
  (cd "$RELEASE.tmp/frontend" && npm ci --no-audit --no-fund && npm run build)
  [ -f "$RELEASE.tmp/frontend/dist/index.html" ] || fail "the frontend build produced no index.html"

  rm -rf "$RELEASE"
  mv "$RELEASE.tmp" "$RELEASE"
fi

ARTISAN=(php "$RELEASE/backend/artisan")

# ---------------------------------------------------------------- database: forward only, and stop on failure
say "Migrations"
"${ARTISAN[@]}" migrate --force || fail "migrations failed — the previous release is still live and nothing was restarted"

say "Caches"
"${ARTISAN[@]}" optimize:clear
"${ARTISAN[@]}" config:cache
"${ARTISAN[@]}" route:cache
"${ARTISAN[@]}" view:cache

# ---------------------------------------------------------------- switch, reload, verify
switch_to() {
  ln -sfn "$1" "$CURRENT.next"
  mv -Tf "$CURRENT.next" "$CURRENT"
}

reload() {
  [ -n "$PHP_FPM_SERVICE" ] && sudo systemctl reload "$PHP_FPM_SERVICE"
  # Every long-running process re-reads the code: workers finish their job and exit, the supervisor starts them on
  # `current`. Without this, a queue worker keeps running the code of the release it started on.
  if [ "$USE_HORIZON" = "true" ]; then
    php "$CURRENT/backend/artisan" horizon:terminate
  fi
  php "$CURRENT/backend/artisan" queue:restart
  [ -n "$REVERB_RESTART_COMMAND" ] && bash -c "$REVERB_RESTART_COMMAND"
  # The scheduler is a per-minute cron on `current/backend/artisan schedule:run`, so it reads the new code on its own.
  return 0
}

healthy() {
  # Readiness, not liveness: /api/health probes the database, Redis, the queue and the failed-job store and answers
  # 503 when any is down. Then the built frontend, and — where Horizon runs — that its supervisor is back up.
  local attempt ok
  for attempt in $(seq 1 12); do
    ok=1
    curl -fsS --max-time 10 "$APP_URL_HEALTH" >/dev/null || ok=0
    if [ -n "$FRONTEND_URL_CHECK" ]; then
      curl -fsS --max-time 10 "$FRONTEND_URL_CHECK" >/dev/null || ok=0
    fi
    if [ "$USE_HORIZON" = "true" ]; then
      php "$CURRENT/backend/artisan" horizon:status 2>/dev/null | grep -qi 'running' || ok=0
    fi
    php "$CURRENT/backend/artisan" about --only=environment >/dev/null 2>&1 || ok=0
    [ "$ok" -eq 1 ] && return 0
    sleep 5
  done
  return 1
}

say "Switching current → $SHA"
switch_to "$RELEASE"
reload

say "Health check: $APP_URL_HEALTH"
if ! healthy; then
  if [ -n "$PREVIOUS" ] && [ -d "$RELEASES/$PREVIOUS" ]; then
    say "Unhealthy — switching back to $PREVIOUS (application only; migrations stay applied)"
    switch_to "$RELEASES/$PREVIOUS"
    reload
  fi
  fail "health check failed after switching to $SHA"
fi

# ---------------------------------------------------------------- record and prune
record "success"
printf '%s\n' "$SHA" > "$SHARED/DEPLOYED_SHA"
say "Deployed $SHA (previous ${PREVIOUS:-none})"

ls -1dt "$RELEASES"/*/ 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do
  [ "$(readlink -f "$CURRENT")" = "$(readlink -f "$old")" ] || rm -rf "$old"
done
