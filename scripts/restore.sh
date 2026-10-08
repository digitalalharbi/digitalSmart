#!/usr/bin/env bash
#
# Restore a backup — and, with --verify, prove it restores without touching the live database.
#
# `--verify` is the point of this script existing. It restores into a throwaway database, compares
# the row counts against the manifest the backup was taken with, and drops it again. A backup that
# has never been restored is a hope; this is how it becomes a backup.
#
# Usage:  scripts/restore.sh <archive> --verify          # into a scratch database, then drop it
#         scripts/restore.sh <archive> --into <dbname>   # into a database you name
#         scripts/restore.sh <archive> --force           # over PGDATABASE itself. Destructive.
#
# Environment (defaults match docker-compose.yml):
#   PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE

set -euo pipefail

ARCHIVE="${1:-}"
MODE="${2:-}"
TARGET="${3:-}"

export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-digitalsmart}"
export PGPASSWORD="${PGPASSWORD:-digitalsmart}"
PGDATABASE="${PGDATABASE:-digitalsmart}"

usage() { sed -n '3,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 1; }

[[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]] && { echo "No such archive: ${ARCHIVE:-<none>}" >&2; usage; }

command -v pg_restore >/dev/null || {
  echo "pg_restore is not on PATH. Install the PostgreSQL client tools (brew install libpq)." >&2
  exit 1
}

case "$MODE" in
  --verify) SCRATCH="restore_check_$(date -u +%s)"; TARGET="$SCRATCH" ;;
  --into)   [[ -z "$TARGET" ]] && usage ;;
  --force)  TARGET="$PGDATABASE" ;;
  *)        usage ;;
esac

# Everything runs against `postgres`, because a database cannot be dropped from inside itself.
admin() { psql -d postgres -v ON_ERROR_STOP=1 -tAc "$1"; }

if [[ "$MODE" == "--force" ]]; then
  echo "!! This will REPLACE every table in '$TARGET' on $PGHOST:$PGPORT."
  echo "!! Type the database name to continue:"
  read -r confirmation
  [[ "$confirmation" == "$TARGET" ]] || { echo "Aborted." >&2; exit 1; }
fi

if [[ "$MODE" != "--force" ]]; then
  admin "drop database if exists \"$TARGET\"" >/dev/null
  admin "create database \"$TARGET\"" >/dev/null
fi

echo "==> Restoring $(basename "$ARCHIVE") into $TARGET"

# `--clean --if-exists` for a restore over an existing database; `--single-transaction` so a
# failure leaves nothing half-applied. `--no-owner` pairs with the dump's own flag.
pg_restore \
  --dbname="$TARGET" \
  --no-owner \
  --no-privileges \
  --single-transaction \
  $([[ "$MODE" == "--force" ]] && echo "--clean --if-exists") \
  "$ARCHIVE"

MANIFEST="$ARCHIVE.manifest"
STATUS=0

if [[ -f "$MANIFEST" ]]; then
  echo "==> Comparing against $(basename "$MANIFEST")"

  # Row counts come from `count(*)` rather than `pg_stat_user_tables`, which is an estimate on a
  # freshly restored database and would compare an estimate with a measurement.
  while read -r table expected; do
    [[ -z "$table" ]] && continue

    actual=$(psql -d "$TARGET" -tAc "select count(*) from \"$table\"" 2>/dev/null || echo missing)

    if [[ "$actual" != "$expected" ]]; then
      echo "   MISMATCH $table: manifest $expected, restored $actual"
      STATUS=1
    fi
  done < <(sed -n '/^tables:/,$p' "$MANIFEST" | tail -n +2 | sed 's/^  //')

  if (( STATUS == 0 )); then
    echo "   Every table in the manifest restored with the row count it was taken with."
  fi
else
  echo "==> No manifest beside this archive, so the restore is unverified."
  echo "    It loaded, which is not the same as being complete."
  STATUS=1
fi

if [[ "$MODE" == "--verify" ]]; then
  admin "drop database if exists \"$TARGET\"" >/dev/null
  echo "==> Dropped the scratch database $TARGET"
fi

exit "$STATUS"
