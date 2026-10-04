# 4. Host-native application processes, containerised datastores

- **Status:** Accepted
- **Date:** 2026-10-04

## Context

Docker was not available on the machine where Phase 0 was built, while PostgreSQL 16 and
Redis were already running natively. Ports 8000 and 5173 were already occupied by other
local stacks.

## Decision

`docker-compose.yml` provides **PostgreSQL and Redis only**. The API, Horizon, Reverb
and Vite run on the host. Both paths are supported and documented, and a dedicated
`digitalsmart` database role exists locally so credentials are identical across native
development, Docker and CI.

digitalSmart binds **8001** (API), **5174** (SPA) and **8080** (Reverb). Vite's dev
server is pinned to `127.0.0.1`, because Vite 8 otherwise binds IPv6 only, which breaks
Playwright and tooling that address `127.0.0.1`.

## Consequences

- Fast edit/refresh loop and native debugging; no container rebuild in the inner loop.
- Contributors must install PHP, Node and the client libraries themselves — documented
  in `docs/DEVELOPMENT.md`.
- Running several local stacks side by side no longer causes port collisions.
- Production containerisation of the application processes is deferred to Phase 11.
