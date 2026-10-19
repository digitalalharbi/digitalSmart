#!/usr/bin/env bash
# Refuse heavy work on a nearly full disk. Low disk space is an operational blocker, not a warning: on 2026-10-19 the
# development machine reached ~110 MB free mid-run and PostgreSQL stopped answering, which failed tests for reasons
# that had nothing to do with the code. Read-only — it never deletes anything.
#
# Usage: scripts/check-disk.sh [minimum-GB] [path]   (defaults: 10 GB, the repository's disk)
set -euo pipefail

MIN_GB="${1:-${MIN_FREE_GB:-10}}"
TARGET="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
FREE_GB="$(df -Pk "$TARGET" | awk 'NR==2 {printf "%d", $4 / 1048576}')"

if [ "$FREE_GB" -lt "$MIN_GB" ]; then
  echo "✗ ${FREE_GB} GB free on the disk holding $TARGET; ${MIN_GB} GB needed before heavy work (E2E, builds, deploys)." >&2
  echo "  Investigate read-only first (docs/DEPLOYMENT.md, 'Disk space'); delete nothing without knowing what it is." >&2
  exit 1
fi

echo "✓ ${FREE_GB} GB free (minimum ${MIN_GB} GB)"
