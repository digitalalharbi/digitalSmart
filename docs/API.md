# API contract

## Conventions

- Base path `/api`. JSON in, JSON out; the API never returns HTML, including on error.
- Identifiers are UUIDv7 strings.
- Timestamps are ISO-8601 UTC.
- Monetary amounts are returned as minor units plus an explicit ISO-4217 currency —
  never as a bare float.
- Every response carries `X-Request-Id` (echoed if the caller sent a valid UUID). Quote
  it in bug reports; it ties the request to logs, jobs and audit entries.

## Documentation

An OpenAPI 3.1 document is generated from the code by
[Scramble](https://scramble.dedoc.co/):

- Human-readable: `GET /docs/api`
- Machine-readable: `GET /docs/api.json`

The document is generated from real signatures, resources and form requests, so it
cannot drift from the implementation the way a hand-written spec does.

## Authentication

First-party SPA authentication uses **Laravel Sanctum** with stateful cookies:

1. `GET /sanctum/csrf-cookie` → sets `XSRF-TOKEN`.
2. `POST /api/auth/login` (Phase 1) → establishes the session cookie.
3. Subsequent requests send the session cookie plus `X-XSRF-TOKEN`.

OAuth connections to ad platforms are **integrations**, not application auth — see
`docs/INTEGRATIONS.md`. Sanctum API tokens exist for machine clients; Passport would
only be introduced if digitalSmart itself became an OAuth2 provider.

## Errors

Standard Laravel shape:

```json
{
  "message": "The given data was invalid.",
  "errors": { "budget_amount": ["The budget amount must be at least 1."] }
}
```

| Status | Meaning                                                      |
| ------ | ------------------------------------------------------------ |
| 401    | Not authenticated                                             |
| 403    | Authenticated but not authorised (policy/permission denied)    |
| 404    | Not found, or not visible to the caller's workspace/project    |
| 409    | Conflict with current resource state                           |
| 422    | Validation failed                                              |
| 429    | Rate limited — see `Retry-After`                               |
| 503    | A required dependency is unavailable (see `/api/health`)       |

A resource outside the caller's scope returns **404, not 403**: existence itself is not
disclosed across tenant boundaries.

## Rate limits

| Limiter  | Budget                                  | Applied to            |
| -------- | --------------------------------------- | --------------------- |
| `api`    | 120/min per user, 30/min per IP (guests) | all `/api` routes     |
| `health` | 60/min per IP                            | `GET /api/health`     |
| `auth`   | 5/min per IP **and** 5/min per e-mail    | credential endpoints  |

## Endpoints

### `GET /api/health`

Reports whether the platform's dependencies are serving. `200` when operational,
`503` when any dependency is `down`. A `degraded` dependency still returns `200`:
requests succeed, but asynchronous work may lag.

```json
{
  "data": {
    "application": "digitalSmart",
    "version": "0.1.0",
    "environment": "local",
    "status": "ok",
    "services": [
      { "name": "database", "status": "ok", "detail": "pgsql 16.14" },
      { "name": "cache",    "status": "ok", "detail": "redis" },
      { "name": "queue",    "status": "ok", "detail": "redis (0 queued)" }
    ]
  }
}
```

`status` is one of `ok`, `degraded`, `down`, and the overall status is the worst
individual service status.

### `GET /up`

Framework liveness probe for load balancers. No body of interest; `200` means the PHP
process booted.

---

Endpoints for identity, workspaces, clients and projects arrive in Phase 1 and are
documented here as they land.
