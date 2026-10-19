#!/usr/bin/env bash
# Piped over SSH by .github/workflows/deploy-production.yml. It runs on the server with DEPLOY_ROOT set, brings the
# server's clean clone to the commit, and hands over to that commit's own deploy script — so the deploy logic that
# runs is always the reviewed version on main, never something sent from the runner.
set -Eeuo pipefail

SHA="${1:?commit sha}"
: "${DEPLOY_ROOT:?DEPLOY_ROOT}"
REPO="$DEPLOY_ROOT/repo"

[ -d "$REPO/.git" ] || { echo "✗ $REPO is not a clone — see docs/DEPLOYMENT.md, first-time setup" >&2; exit 1; }
git -C "$REPO" fetch --prune origin main
git -C "$REPO" checkout --quiet --detach "$SHA"
[ -z "$(git -C "$REPO" status --porcelain)" ] || { echo "✗ the server clone has local changes" >&2; exit 1; }

exec bash "$REPO/scripts/deploy/deploy-production.sh" "$SHA"
