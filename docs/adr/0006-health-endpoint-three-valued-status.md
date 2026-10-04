# 6. Three-valued health status

- **Status:** Accepted
- **Date:** 2026-10-04

## Context

A binary up/down health check forces a bad choice: either a stalled queue worker takes
the whole platform out of the load balancer, or a real outage is reported as healthy.

## Decision

`ServiceStatus` is `ok | degraded | down`. Each dependency is probed behind a
`HealthProbe` port; the overall status is the worst individual status.

`GET /api/health` returns **200** for `ok` and `degraded`, and **503** only for `down`.
The database is `down` when unreachable; the queue is `degraded` when reachable but
backing up, since requests still succeed while asynchronous work lags.

Probes must not throw — a probe that fails reports `down` with the reason.

## Consequences

- Monitors get an honest signal and operators get the detail in the body.
- The use case has no I/O, so every combination is unit-testable without infrastructure.
- Adding a dependency (object storage, a provider) means adding one probe class and one
  line in the composition root.
