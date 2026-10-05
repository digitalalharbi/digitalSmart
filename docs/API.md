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

| Limiter  | Default budget                          | Applied to            |
| -------- | --------------------------------------- | --------------------- |
| `api`    | 120/min per user, 30/min per IP (guests) | all `/api` routes     |
| `health` | 60/min per IP                            | `GET /api/health`     |
| `auth`   | 5/min per IP **and** 5/min per e-mail    | credential endpoints  |

Budgets are configurable (`RATE_LIMIT_*`) so they can be tuned per environment.
`auth` applies per IP *and* per e-mail address: raising the per-IP budget for a
shared address does not weaken the per-account protection.

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

### Authentication

| Endpoint | Purpose |
| -------- | ------- |
| `POST /api/auth/login` | Establish a session. Body: `email`, `password`, optional `remember`. |
| `GET /api/auth/me` | The current user, their workspaces and effective permissions. |
| `POST /api/auth/logout` | End the session. `204`. |
| `POST /api/workspaces/{id}/activate` | Switch the session's active workspace. |

Rejected credentials return `422` with a message identical for a wrong password
and an unknown address — the login form is not an account enumeration oracle. A
deactivated account is the deliberate exception, because the person needs to know
to contact an administrator. A user who belongs to no workspace is signed back
out rather than left half-authenticated.

`GET /api/auth/me` returns a `permissions` array. The SPA uses it to decide what
to *offer*; it is never the security boundary.

### Clients

| Endpoint | Permission |
| -------- | ---------- |
| `GET /api/clients` | `clients.view` |
| `POST /api/clients` | `clients.create` |
| `GET /api/clients/{id}` | `clients.view` |
| `PUT /api/clients/{id}` | `clients.update` |
| `PATCH /api/clients/{id}/status` | `clients.update` |
| `DELETE /api/clients/{id}` | `clients.delete` |

List parameters: `search`, `status[]` (`prospect`, `active`, `paused`,
`archived`), `page`, `per_page` (max 100), `sort` (`name`, `status`,
`created_at`, `updated_at`), `direction`. An unrecognised sort column is ignored
rather than interpolated.

Responses are enveloped:

```json
{
  "data": [ ... ],
  "meta": { "total": 42, "page": 1, "per_page": 25, "last_page": 2, "has_more": true }
}
```

A client is created as a `prospect`. Status changes go through
`PATCH /{id}/status` and respect the domain's transitions —
`prospect → active | archived`, `active → paused | archived`,
`paused → active | archived`, and `archived` is terminal. An illegal transition
returns `422` with `"code": "invalid_status_transition"`.

Deletion is a soft delete: projects, spend history and the audit trail survive.

### Projects

| Endpoint | Permission |
| -------- | ---------- |
| `GET /api/projects` | `projects.view_all` or `projects.view_assigned` |
| `POST /api/projects` | `projects.create` |
| `GET /api/projects/{id}` | `projects.view_all` or `projects.view_assigned` |
| `PUT /api/projects/{id}` | `projects.update` + visibility |
| `PATCH /api/projects/{id}/status` | `projects.update` + visibility |
| `DELETE /api/projects/{id}` | `projects.delete` + visibility |
| `GET /api/projects/{id}/members` | visibility of the project |
| `POST /api/projects/{id}/members` | `projects.manage_members` |
| `DELETE /api/projects/{id}/members/{user}` | `projects.manage_members` |

**Visibility is the isolation rule.** A caller holding `projects.view_all` sees
every project in the workspace. Everyone else sees only the projects they are
explicitly assigned to — applied in the query, so an unassigned project is
absent from listings and returns 404 individually. An actor with no assignments
sees an empty list, never the full set.

Status transitions follow the domain: `planning → active | archived`,
`active → paused | completed | archived`,
`paused → active | completed | archived`,
`completed → active | archived`, and `archived` is terminal. Only `active`
permits campaign spend, and each status change records that fact in the audit
log.

A project cannot be moved to another client: the client is read from the stored
project on update rather than accepted from the request.

### Workspace members

| Endpoint | Permission |
| -------- | ---------- |
| `GET /api/members` | `users.view` |
| `PATCH /api/members/{user}/role` | `users.update` |
| `PATCH /api/members/{user}/status` | `users.deactivate` |
| `DELETE /api/members/{user}` | `workspaces.manage_members` |

A workspace must always retain at least one **active owner**. Demoting,
suspending or removing the last one returns `422` with
`"code": "last_owner_protected"` — otherwise an administrator could lock
everybody out of a tenant with no way back in.

Status changes take effect on the caller's very next request: a suspended member
holds no permissions at all, whatever their role says. A member removed from the
workspace receives `403` with a message explaining they hold no active
membership; their user account and audit trail survive, because an audit entry
that cannot be attributed is not evidence.

### Audit log

| Endpoint | Permission |
| -------- | ---------- |
| `GET /api/audit-logs` | `audit.view` |

Newest first, always scoped to the active workspace. Parameters: `search`,
`action[]`, `actor_id`, `security_only`, `page`, `per_page`. Entries are
append-only and secrets are redacted from recorded diffs.

### Integrations

| Endpoint | Permission |
| -------- | ---------- |
| `GET /api/integrations/providers` | `workspaces.view` |
| `PUT /api/integrations/providers/{provider}` | `workspaces.update` |
| `POST /api/integrations/providers/{provider}/discover` | `workspaces.update` |
| `GET /api/integrations/providers/{provider}/health` | `workspaces.view` |
| `DELETE /api/integrations/providers/{provider}` | `workspaces.update` |
| `GET /api/integrations/accounts` | `workspaces.view` |
| `GET /api/projects/{id}/integrations` | visibility of the project |
| `POST /api/projects/{id}/integrations` | `workspaces.update` |
| `PATCH /api/projects/{id}/integrations/{account}` | `workspaces.update` |
| `DELETE /api/projects/{id}/integrations/{account}` | `workspaces.update` |

Credentials are **write-only**. `PUT` accepts them; every response reports only
which *keys* are held (`credential_keys`), never a value.

Listing bindings needs only project visibility — a media buyer should see which
accounts feed their project — while granting and revoking need workspace
administration, because that is the access boundary between clients.

Three provider-specific statuses:

| Status | Code | Meaning |
| ------ | ---- | ------- |
| `503` | `provider_not_configured` | Credentials are missing, or present but unusable because the provider has not granted API access. `blocked_external: true`, with `missing_credentials` when that is the cause. |
| `501` | `provider_unavailable` | The capability does not exist to be used; no credential would help. |
| `502` | `provider_request_failed` | A normalised provider failure, with `retryable` and `retry_after_seconds`. |

See `docs/INTEGRATIONS.md` for what each provider needs and how sandbox mode
works.

---

Campaign and metrics endpoints arrive in Phase 3.
