# 2. PostgreSQL everywhere, UUIDv7 primary keys

- **Status:** Accepted
- **Date:** 2026-10-04

## Context

The spec requires PostgreSQL with UUID primary keys on API-facing entities. The Laravel
skeleton ships SQLite and bigint keys, and defaults the test suite to SQLite in memory.

## Decision

PostgreSQL is the only database, in development, test and production. The SQLite file
and its bootstrap step were removed. Feature tests run against a dedicated
`digitalsmart_test` database.

API-facing entities use UUIDv7 via a `HasUuidPrimaryKey` trait. Laravel 12's `HasUuids`
already issues v7; `HasVersion4Uuids` is deliberately not used. Internal tables that are
never exposed (e.g. `personal_access_tokens`) keep bigint keys, but their morph columns
are UUIDs.

## Consequences

- Tests are slower than in-memory SQLite, and CI must run a PostgreSQL service.
- In exchange, JSONB, check constraints, partial indexes and PostgreSQL's real planner
  behaviour are exercised by the suite — a green run means something.
- UUIDv7 keeps index inserts sequential, which matters at metrics volume, while keys
  stay safe to expose in URLs and safe to generate client-side.
- Keys are 16 bytes rather than 8; accepted for the isolation and URL-safety benefits.
