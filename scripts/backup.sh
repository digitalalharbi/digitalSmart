#!/usr/bin/env bash
#
# Take a restorable backup of the PostgreSQL database.
#
# A backup nobody has restored is a hope, not a backup — so this writes in the custom format
# `pg_restore` reads, records what it contains beside it, and `scripts/restore.sh` is the other
# half that proves it. Run both before you need either.
#
# Usage:  scripts/backup.sh [destination directory]
#         PGDATABASE=digitalsmart_staging scripts/backup.sh /mnt/backups
#
# Environment (defaults match docker-compose.yml):
#   PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${1:-$ROOT/storage/backups}"

export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-digitalsmart}"
export PGPASSWORD="${PGPASSWORD:-digitalsmart}"
PGDATABASE="${PGDATABASE:-digitalsmart}"

command -v pg_dump >/dev/null || {
  echo "pg_dump is not on PATH. Install the PostgreSQL client tools (brew install libpq)." >&2
  exit 1
}

# The server's major version, because a dump taken by an older pg_dump than the server is
# refused by pg_dump itself — and finding that out during an incident is the wrong time.
SERVER_VERSION=$(psql -d "$PGDATABASE" -tAc 'SHOW server_version' | cut -d. -f1)
CLIENT_VERSION=$(pg_dump --version | grep -oE '[0-9]+' | head -1)

if (( CLIENT_VERSION < SERVER_VERSION )); then
  echo "pg_dump is $CLIENT_VERSION and the server is $SERVER_VERSION. Upgrade the client tools." >&2
  exit 1
fi

mkdir -p "$DEST"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
ARCHIVE="$DEST/$PGDATABASE-$STAMP.dump"

echo "==> Dumping $PGDATABASE from $PGHOST:$PGPORT"

#  --format=custom   compressed, and restorable table by table
#  --no-owner        so a restore into a differently-named role works
#  --no-privileges   grants belong to the environment, not to the data
pg_dump --dbname="$PGDATABASE" --format=custom --no-owner --no-privileges --file="$ARCHIVE"

# What is in it, written beside it. An archive whose row counts nobody recorded cannot be
# verified after a restore — which is the only check that matters.
MANIFEST="$ARCHIVE.manifest"
{
  echo "database: $PGDATABASE"
  echo "host: $PGHOST:$PGPORT"
  echo "taken_at: $STAMP"
  echo "server_version: $SERVER_VERSION"
  echo "bytes: $(wc -c <"$ARCHIVE" | tr -d ' ')"
  echo "migrations: $(psql -d "$PGDATABASE" -tAc 'select count(*) from migrations' 2>/dev/null || echo unknown)"
  echo "tables:"
  psql -d "$PGDATABASE" -tAF' ' -c "
    select relname, n_live_tup
    from pg_stat_user_tables
    where n_live_tup > 0
    order by relname
  " | sed 's/^/  /'
} >"$MANIFEST"

echo "==> Wrote $ARCHIVE"
echo "==> Wrote $MANIFEST"
echo
echo "Verify it now, not later:"
echo "  scripts/restore.sh \"$ARCHIVE\" --verify"
