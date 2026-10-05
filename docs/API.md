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

## Analytics

### `GET /api/analytics`

One endpoint answers the scorecard, the chart and the table. They have to agree,
and computing them from a single query with a single set of filters is the only
way to guarantee that — a scorecard that disagrees with the sum of its own chart
is the fastest way to lose a reader's trust in a dashboard.

Requires `analytics.view`. An actor without `projects.view_all` has every query
narrowed, in SQL, to the projects they are assigned to.

| Parameter | Notes |
| --------- | ----- |
| `start`, `end` | `Y-m-d`, inclusive, in the ad account's own timezone |
| `preset` | `7d`, `14d`, `28d` (default), `30d`, `90d`, `this_month`, `last_month`. All end **yesterday**: today is still accumulating, and including it makes every metric look worse than it is |
| `compare` | `none` (default), `previous_period`, `previous_year` |
| `metrics[]` | From the catalogue below; defaults to the standard scorecard |
| `dimension` | `date`, `provider`, `external_account`, `campaign`, `ad_group`, `ad` — omit for totals only |
| `order_by`, `limit` | Breakdown ordering (default: spend, descending) and size (max 500) |
| `providers[]`, `account_ids[]`, `campaign_ids[]`, `ad_group_ids[]`, `ad_ids[]`, `project_ids[]`, `client_id` | Filters. The narrowest structural filter decides which **level** of stored rows answers the query |

Every figure is returned as an object, not a bare number, because a number alone
cannot be rendered honestly:

```json
{
  "metric": "roas",
  "label": "ROAS",
  "value": 3.84,
  "format": "ratio",
  "precision": 2,
  "is_minor_units": false,
  "currency": null,
  "source": "sandbox",
  "requires_label": true,
  "unavailable_reason": null,
  "comparison_value": 3.12,
  "change_ratio": 0.2308,
  "change_is_good": true,
  "lower_is_better": false
}
```

Three things about that shape are load-bearing:

- **`value` is nullable, and null is an answer.** It means the figure does not
  exist, and `unavailable_reason` says why in words a person can act on — "Not
  reported by this platform", "Covers more than one currency", "Reach is
  deduplicated by the platform, so it cannot be added across 4 entities". Clients
  must render a gap, never a zero.
- **Money stays in minor units** all the way to the browser (`is_minor_units`),
  so nothing rounds in transit, and `currency` says how to format it.
- **`source`** is one of `live`, `synced`, `stale`, `partial`, `sandbox`,
  `manual`, `estimated`. The response carries it per figure *and* once for the
  whole surface, taken from the weakest row involved, so a chart and its scorecard
  cannot disagree about how trustworthy they are.

`series` returns one point per day in the range, **including days with no data**
(`row_count: 0`, every value null). A chart that omits a day joins the two either
side with a straight line, hiding the fact that delivery stopped — which is the
most important thing on the screen that day.

### `GET /api/analytics/catalogue`

Metrics, dimensions, providers and source states, with their labels, formats and
precision. Served rather than duplicated in the client: a metric's format and
direction are domain facts, and a second copy in TypeScript is how a dashboard
ends up showing CPA as a count on one screen and money on another. It also
publishes `additive_across_entities`, so the interface can explain why reach
disappears when more than one entity is selected.

## Saved views

### `GET|POST /api/analytics/views`, `PUT|DELETE /api/analytics/views/{view}`

A saved view is a stored analytics question. Reading needs `analytics.view`;
creating or changing one needs `saved_views.manage`.

**A preset is stored, not the dates it resolved to.** A view called "last 28 days"
should still mean that next month — freezing the dates would turn it into a snapshot
of one period, which is a different feature with a different name. An explicit
`start`/`end` is also accepted and *is* stored literally, because a closed month is a
legitimate thing to save.

The query is rebuilt into a real `AnalyticsRequest` on the way in rather than stored
as whatever JSON arrived, so a view saved today still parses in six months.

`POST /api/analytics/views/{view}/opened` records that a view was used. Separate from
reading it, so the ordering in the picker reflects what someone actually opens rather
than what a background refresh fetched.

Names are unique per owner, and a reused one is a `422` with
`"code": "name_already_used"` — the same rule, and the same reasoning, as for
dashboards.

## Dashboards

### `GET|POST /api/dashboards`, `GET|PUT|DELETE /api/dashboards/{dashboard}`

A dashboard is a canvas of widgets over a 12-column grid, plus the filters its
widgets inherit.

**The widget set is replaced, not merged.** What a reader sees is what the dashboard
is, so a widget they removed has to go — and diffing a set of grid positions is a
worse way to reach that than sending the set. Omitting `widgets` entirely means
"leave the canvas alone", which is how a rename or a visibility change avoids
resending everything; `widgets: []` means "the canvas is now empty". Those are
different instructions and both are supported.

| Field | Notes |
| ----- | ----- |
| `visibility` | `private`, `workspace`, or `project`. The third needs a `project_id`, and the database refuses it without one |
| `filters` | The dashboard's own period and filters. Keys outside the analytics vocabulary are dropped rather than stored |
| `widgets[].type` | `scorecard`, `line`, `bar`, `share`, `scatter`, `table` |
| `widgets[].filter_overrides` | **`null` means inherit; `{}` means override with nothing.** Two different instructions, preserved as such end to end |
| `widgets[].column/row/width/height` | Placement. Width is 3–12 columns, height 1–6 rows |
| `grid_columns` (response) | The grid the server validates against, so the client lays out on the same one rather than keeping its own copy of the number |

Five things are refused with `422` and a message naming what would fix it, because
these reach someone composing a dashboard rather than a developer reading a log:

- a widget with no metric;
- a scatter with one metric — it plots one against another, so it needs two;
- a chart type that cannot show the chosen dimension, e.g. a share of total across
  ninety dates, which draws perfectly and means nothing;
- two widgets that would overlap. An overlap does not error in a browser — one widget
  simply covers the other, and the hidden one is invisible until someone moves it, so
  refusing the save is the only way the reader finds out;
- a name the caller already used, as `"code": "name_already_used"`. Names are unique
  per owner — two identical entries in a picker are useless — and a unique index
  enforces it either way; the check is what turns an everyday reused name into a
  `422` that says which name to change, instead of a failed insert surfacing as a
  `500` carrying the constraint and the statement. Two *different* people may each
  have a "Weekly review".

`POST /api/dashboards/{dashboard}/duplicate` is the one place a name conflict is not
reported. The default copy name is chosen by the server, so a second copy becomes
`… (copy 2)` rather than failing on a name the caller never typed; a `name` the
caller *does* supply is theirs to change and conflicts as above.

Only the owner may change or delete a dashboard; a colleague who can see it gets a
`404` if they try, because whether someone else's dashboard exists is not something a
caller learns by attempting to edit it. `POST /api/dashboards/{dashboard}/duplicate`
is how a shared dashboard gets adapted instead: it needs only visibility of the
source, and the copy is always **private**, whatever the original was — inheriting a
shared audience would publish a draft the moment it was made.
