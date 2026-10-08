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

**One route answers without a session:** `GET /api/shared-reports/{token}`, where the token
*is* the authorisation and authorises exactly one report. There is no report id and no other
parameter on that path, the route is constrained to `[A-Fa-f0-9]{96}` so nothing of the wrong
shape reaches the application, it is rate-limited per address rather than per user, and it
always answers 200 with a state rather than 404 — see `docs/REPORTING.md`. Webhook receivers
are also unauthenticated in the session sense and are verified by provider signature instead;
see `docs/SALLA.md` and `docs/ZID.md`.

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
| `shared-reports` | 20/min per IP                    | `GET /api/shared-reports/{token}` |

Budgets are configurable (`RATE_LIMIT_*`) so they can be tuned per environment.
`auth` applies per IP *and* per e-mail address: raising the per-IP budget for a
shared address does not weaken the per-account protection. `shared-reports` is keyed
by address because it has no user to key to, and is much tighter than the API's guest
budget: a recipient opens a report a handful of times, and a bare token in a URL is the
one thing in this product worth brute forcing.

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

## Client portal

Two sets of routes, and they are deliberately not one. `/api/portals*` is the agency's control over what a client
may read — it carries who exposed what and the suspension reason, and is gated on `portal.view` / `portal.manage`.
`/api/portal*` is the client's own read, gated on the portal's module grants rather than on a role.

A module that was not exposed answers **404, not 403**. Telling a client that a surface about them exists and is
being withheld says something about the agency's intentions rather than about the product, and the portal index is
where a reader learns what they may open.

Agency staff holding `portal.view` read the client routes as a **preview**, through the same code, and every
response carries `is_preview`.

`GET /api/portal/{client}/revenue` requires `revenue_source` with no default, and the refusal carries the
authorities the surface accepts — which is not every source the product knows: attributed revenue is a model and is
never a portal figure. The access check runs **before** that refusal, so a reader who may not open the surface is
told it is not there rather than told which parameter they left out.

See `docs/PORTAL.md`.

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

## Budgets

### `GET|POST /api/budgets`, `GET|PUT|DELETE /api/budgets/{budget}`

Reading needs `budgets.view`; creating and changing needs `budgets.manage`; approving
needs `budgets.approve`. The third permission exists on its own because drafting a plan
and committing a client to a figure are different acts: a media buyer can reshape a plan
all day and still not be the person who says "the client agreed to this".

**Amounts are decimal strings in the major unit.** `"amount": "180000.00"`, not
`180000000`. An API that takes one and an API that takes the other look identical in a
request body, and the mistake is only visible on an invoice. Responses report
`amount_minor` as an integer, as every money field in this API does.

| Field | Notes |
| ----- | ----- |
| `client_id` | Required. A budget without a client is a number nobody agreed to |
| `project_id` | Optional. A client's quarterly budget is agreed before anyone decides which campaigns spend it |
| `period_start` / `period_end` | Inclusive both ends, so a one-day budget covers one day |
| `amount` | Decimal, above zero |
| `notes` | **Internal.** Omitted entirely — not nulled — from any response a client may read, and deliberately left out of the change log so a working note does not become undeletable |

`POST /api/budgets/{budget}/approve` fixes the amount, the period and the currency, and
records who approved it and when. After that, **changing those fields returns `422`
`invalid_budget`**: the record exists to say what a client agreed to, and a figure that
can be quietly revised says nothing. The name, project and notes stay editable, and so
do the allocations — that is how a plan adapts without the commitment moving. To change
an approved amount, archive it with `POST /api/budgets/{budget}/archive` and approve a
new one, which leaves both on the record.

A `DELETE` works on a draft only. An approved budget has spend measured against it, so
deleting it would make the quarter unreconcilable; the response says to archive it
instead.

Every create, change, approval, archive, deletion and reallocation is written to the
audit log with its before and after. That is the immutable change log the governance
hierarchy needs, and it lives in the audit trail rather than a second history table that
would have to be kept honest separately. Approving a budget is flagged as a **security
event**, because committing a client's money has to be as findable as a credential
change.

### `PUT /api/budgets/{budget}/allocations`

Replaces the whole tree, for the same reason a dashboard's widgets are replaced: the
tree a reader sees is the whole plan, so an allocation they removed has to go. The
request nests three levels — `platform` → `campaign` → `ad_group` — rather than sending
parent ids, so a campaign allocation cannot arrive claiming the budget as its parent.

Four things are refused with `422` `invalid_allocation`:

- allocations summing to more than the approved budget;
- a branch summing to more than the allocation above it;
- a child naming a different platform than its parent — the total under "Meta" would
  otherwise include Google spend;
- a campaign or ad group allocation hanging off the budget directly.

Summing to **less** than the budget is allowed and reported as `unallocated_minor` at
every level. An unallocated remainder is a decision nobody has made yet, and seeing it
is how somebody remembers to make it.

### `GET /api/budgets/{budget}/pacing`

The budget, the spend that landed inside it, and what the two say together. Three things
this will not do, each of which would produce a plausible screen:

- **Pace a draft.** A variance from a figure nobody agreed to means nothing, so
  `pacing` is null and `unavailable_reason` says why.
- **Pace spend it cannot total.** Spend covering two currencies, spend reported in a
  currency the budget is not in, or sources that cannot be combined all arrive as a null
  with a reason — and then there is no pacing either, rather than pacing against a
  partial total. Understated spend is the one direction that overcharges a client
  without anyone noticing.
- **Compare an allocation with spend it cannot be matched to.** A campaign allocation
  written before the campaign exists has no campaign id, so it has no actual spend; that
  is said rather than shown as a zero.

`pacing.expected_minor` is the budget spread **evenly** across the period, and
`tolerance` (0.10) is published alongside it so a reader knows what "expected" means
without a footnote. `projected_minor` is today's rate carried to the end, and it is
**null before the period starts** — a zero there would read as a forecast of no spend.
`daily` is one entry per day a provider actually reported, so a gap stays a gap instead
of being filled with a zero that flattens the cumulative line.

Statuses: `not_started`, `on_track`, `underspending`, `overspending`, `exhausted`,
`ended`, `unknown`. `exhausted` is checked before the period end, because a budget that
ran out on day three has a problem worth naming for the rest of the month.

### `GET /api/budgets/alerts`

The approved budgets that need looking at today, recomputed on every request rather than
stored — a stale alert is worse than none. Overspending first, then the ones whose spend
could not be totalled, then underspending: money leaving too fast cannot wait for a
discussion about delivery. Budgets whose spend could not be totalled are **included**
with their reason, because "we cannot tell you how this is pacing" is itself something an
operator needs to know, and dropping them would make the list look reassuringly short.

### `POST /api/budgets/forecast`

Budget → results, or target → budget. A POST despite being a read: the request carries a
provider list, a scope and a target that do not belong in a URL, and nothing is stored,
so a reader can try as many numbers as they like.

Send either `budget` (a decimal) or `target_metric` with `target_value`
(`conversions`, `clicks` or `impressions`). Optional `providers`, `window_days` (7–365,
default 90), `client_id`, `project_id`.

**Rates come from the workspace's own delivery and nothing else.** There is no
industry-benchmark table in this product, because a rate nobody in this workspace earned
cannot be defended to the client paying for it. Consequences, all deliberate:

- A platform with fewer than three days of observed delivery gets **no projection** and
  a reason. A number from two days of one campaign, rendered like any other, is the kind
  of estimate that gets quoted back as a commitment.
- A platform reporting in two currencies gets no rates: a cost per click averaged across
  currencies is not a cost.
- Headline rates are **weighted** — total spend over total clicks — not an average of
  daily rates, which would let a quiet day with two clicks count as much as a busy one.
- The band on each figure is the **25th to 75th percentile of the daily rate**, a real
  statistic about the account's own variation rather than a percentage chosen to look
  like a range.
- `confidence` is `none`, `low`, `medium` or `high`, from the number of observed days and
  the spread of the rate. An unknown spread is never treated as a stable one.
- `source` on a projection is always `estimated`; `rate_source` reports what the rates
  themselves came from, so sandbox-derived rates stay visible as such.
- `benchmarks.version` identifies the sample — window, scope and observed days per
  platform — so a figure quoted in a meeting can be reproduced later, and a different
  version means the numbers have moved.
- `gaps` names what could not be worked out, rather than leaving empty fields a reader
  fills in as zero.
- Every response carries a `disclaimer`. It is in the payload rather than left to the
  interface to remember, so every consumer gets it — including the ones written later.

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

## Measurement

Reading needs `tracking.view`; registering and changing needs `tracking.manage`. Neither is
granted to a creative or a client viewer — measurement setup is where a number's meaning is
decided, and that is the agency's to answer for.

The product rules behind these endpoints are in [MEASUREMENT.md](MEASUREMENT.md); the
diagnosis is in [TRACKING.md](TRACKING.md).

### `GET|POST /api/measurement/sources`, `PUT|DELETE /api/measurement/sources/{source}`

What measures a client's work: a GA4 property, a GTM container, an ad account, a store, a CRM,
a website form, a typed figure.

| Field | Notes |
| ----- | ----- |
| `client_id` | Required. Measurement is agreed per client before any project uses it |
| `project_id` | Optional. A project read returns its own sources **and** the client-level ones it inherits, so a project-scoped page does not hide the property doing the measuring |
| `kind` | `analytics`, `tag_manager`, `ad_platform`, `store`, `crm`, `website`, `manual` |
| `provider` | A registry id. **Required for the first four kinds and refused for the last three** — a lead taken by phone has no platform, and demanding one would make the honest cases unrecordable |
| `external_id` | The provider's own id for the thing, e.g. `properties/123456` |

**A kind must match its provider's category** (`analytics`↔`analytics`,
`store`↔`commerce`, …), checked against the Phase 6 registry and refused with `422`
`invalid_measurement_source`. Filing a Salla store as an analytics property would put invoiced
revenue in the column that holds what a browser reported; the two disagree by design, and once
they share a column nobody can say which is wrong.

Registering the same `external_id` for one client twice is the same `422`. A unique index
enforces it either way — the check is what turns a duplicate into a message naming the
property, rather than a failed insert surfacing as a `500` carrying the constraint.

Every response carries three derived fields rather than stored ones:

- `freshness` — `live`, `synced`, `stale`, `silent`, `never_synced` — computed on read, because
  freshness is a statement about now. `last_synced_at` and `last_event_at` travel with it,
  because **`stale` and `silent` are different problems**: one means the figures are old, the
  other means the source is working perfectly and measuring nothing.
- `needs_attention` — true for `stale`, `silent` and `never_synced`.
- `is_revenue_authority` — true for `store` and `crm` only. An analytics property reports what
  a browser said a sale was worth; an ad platform reports what it believes it caused. Neither
  is an invoice.

### `GET|POST /api/measurement/conversions`, `PUT|DELETE /api/measurement/conversions/{definition}`

What counts as success, and which raw event names mean it.

| Field | Notes |
| ----- | ----- |
| `name` | Unique per scope. Two identical entries in a conversion picker are useless |
| `normalized_event` | One of the fourteen vocabulary cases |
| `is_conversion` / `is_primary` / `expects_value` | A primary must be a conversion |
| `mappings[]` | `raw_event_name`, and an optional `measurement_source_id`. **Null means "from any source"** — a different statement from "from a source we cannot name" |

`mappings` is `present` rather than `required`: an empty array is a coherent request shape, and
it is refused in the use case where the message can say *why* — nothing would ever count
towards the conversion — rather than as "the mappings field is required".

Four `422` `invalid_event_definition` cases: no mappings; the same raw name twice in one
definition; a raw name another definition in the scope already claims; a primary that is not a
conversion. The duplicate checks run across the whole scope, because the double count a reader
sees does not care which record caused it.

**A `PUT` replaces the mappings outright**, for the same reason a dashboard's widgets are
replaced: the set of raw names is the whole truth about what the conversion counts, so a name
somebody removed has to stop counting. The audit entry keeps **both sides** — "leads halved in
March" is sometimes a campaign and sometimes a mapping somebody edited, and nothing else can
tell them apart.

Responses publish `is_intent_signal_only`, true for `whatsapp_click`, `phone_click` and
`form_start`. A click is not a conversation and a form start is not a submission; reporting a
cost per click as a cost per lead is the commonest way results get overstated. Counting one is
allowed — it raises a tracking warning, not a refusal.

`observed_count` is **null when no window was asked for** and `0` when a window was asked and
nothing was seen. A create returns null; the health endpoint returns a number. Answering "0"
to a question nobody asked would read as "never happened".

### `GET /api/measurement/health`

Accepts `client_id` and `project_id`. Returns the sources with their freshness, the definitions
with what was actually observed over `window_days` (14), `is_healthy`, `critical_count`, and
`issues` sorted worst-first.

Recomputed on every request. A stored diagnosis is wrong the moment the next sync lands, and a
stale warning teaches people to ignore the list.

Each issue carries `code`, `severity`, `summary`, `remedy`, `subject` and **`evidence`** — the
facts it rests on. A tracking centre that says "purchase event missing" without saying how it
knows is one people stop believing after the first false alarm, and a false alarm is certain
because this product can only see what a provider reports to it. The ten codes and what each
refuses to infer are in [TRACKING.md](TRACKING.md#the-issues).

A correctly configured project returns `"issues": []`. Silence is the goal.

### `GET /api/measurement/providers/{provider}/properties`

Every analytics property the workspace's connection can read. Needs `tracking.manage`, not
`tracking.view`: this reaches out to the provider on the workspace's credentials, and reading an
agency's whole analytics estate is a setup action rather than a reporting one.

Deliberately unfiltered — including the properties for clients the agency has left, which is
exactly the list a person must read carefully rather than one the product should prune for them.

Each property carries its data streams, because the site and the `G-` measurement id are how
somebody recognises it as their client's; a list of numeric ids is unusable for that decision.
`is_aggregate` flags a roll-up property: it counts the same event its source properties do, so
measuring one client through both would double every figure and nothing later could detect it.

An unknown provider is a `404`. A provider with no measurement adapter, or one nobody has
connected, is an empty list — not an error, because the integrations page is where that is fixed
and it already says so. Real credentials with no authorised OAuth client are `503`
`provider_not_configured`, naming what is missing.

### `GET /api/measurement/sources/{source}/suggestions`

Candidate conversions, from what the property's owner already marked as a key event. Needs
`tracking.manage`.

**Suggestions only. Nothing is created.** GA4 marking an event key is GA4's opinion — often a
default, sometimes left by whoever configured the property years ago — and what this agency counts
for this client is a different question with an author and an audit entry.

`normalized_event` is populated **only** for names Google documents with a defined meaning, and
is `null` otherwise, including for names that look obvious. A suggestion the product cannot cite
is a guess wearing a citation's clothes; the list is in
[GOOGLE_ANALYTICS.md](GOOGLE_ANALYTICS.md#key-events-are-suggestions-never-conversions).

`counted_once_per_session` is published because it changes what the number means: counted per
session, two purchases in one visit are one key event, and somebody reconciling that against an
order count would otherwise conclude the tracking is broken. `already_mapped` is true for a raw
name some definition in the scope already claims, so confirming the same list twice cannot create
a second definition counting one event.

### `POST /api/measurement/sources/{source}/sync`

Read the source now and write what it reported. Needs `tracking.manage`. Synchronous because it
is a person pressing a button and waiting; the scheduled path is a queued job over the same use
case.

The response is the honest account of the run, not a boolean:

| Field | Notes |
| ----- | ----- |
| `status` | `succeeded`, `partial`, `failed`, or `skipped` |
| `days_read` / `rows_written` / `api_calls` | What it actually did |
| `source` | The **weakest** honesty label anything in the run carries — `sandbox` beats `partial` beats `synced` |
| `caveats` | Why the figures are not totals, one sentence per cause |
| `last_event_date` | The most recent day anything was seen on |
| `failure_message` | What stopped it, when something did |

`skipped` is not a failure: a manual source or a CRM has nothing to read, and a provider without
an adapter is a gap in this product rather than a problem with the client's setup. The interface
needs that distinguishable so it can disable the button instead of offering one that fails.

A run that fails part-way is `partial`, not `failed`, when days did land — those figures are real
and are kept. `last_synced_at` is written even on a failure, because "we tried and got nowhere" is
a different fact from "nobody has asked", and a tracking page has to tell them apart.

Re-running over the same window **replaces** rather than accumulates. The failure that guards
against would look exactly like growth.

### `GET /api/measurement/containers`

Every tag container the workspace's connection can read. Needs `tracking.manage`.

Separate from assessing one: listing is a single cheap call, while reading each container's
published version is a call per container. `public_id` (`GTM-XXXXXX`) is the field that matters —
it is what somebody will find in their client's page source, and the only way to be sure a
container is the right one. `is_web` distinguishes a web container from a server-side or app one, so
nobody expects web page events from something measuring elsewhere.

Empty when Tag Manager is not connected.

### `GET /api/measurement/sources/{source}/readiness`

Whether a client's container is actually doing anything. Needs `tracking.manage`, and the source
must be of kind `tag_manager` — an analytics property has no container, and a `404` saying so beats
an empty page.

**Assessed from the published version, never a workspace.** A workspace holds what somebody is
working on; the live version is what the site runs, and believing the first is the second is the
commonest failure in this area. `has_live_version: false` means the container was never published —
reported as a fact rather than an error, and as the *only* finding, because anything else would bury
it.

The issues use the same shape, severities and evidence rule as `/measurement/health`; the six codes
are listed in [GOOGLE_TAG_MANAGER.md](GOOGLE_TAG_MANAGER.md#what-it-concludes-and-from-what). This
is a separate endpoint because health is derived from our own tables and always answers, while this
needs a live call — and a provider outage must not empty the tracking page of facts it already had.

`measurement_ids_found` and `measurement_ids_expected` both travel on the response so a reader can
judge the id finding rather than trust it: a container may load a property through a custom template
or a server-side container, neither of which this product can see.

A source pointing at a container the connection can no longer read is a `404` — silently assessing a
different container would be the worst available outcome. Real credentials with no authorised OAuth
client are `503` `provider_not_configured`: reporting "ready" or "never published" would both be
fabrications, and either would be acted on.

## Commerce

### `GET /api/commerce/analytics`

What a client's stores sold. Needs `analytics.view` — a client's revenue is reporting, and a role that
may not read analytics has no business reading it.

**`revenue_source` is required.** There is no default and no "all" value, and a request without one is a
`422` `revenue_source_required` listing the allowed values. A Salla total counts invoices; a GA4 total
counts what a browser reported before the payment failed. Answering a request that did not say which
would be answering a question nobody asked, and the answer would be quoted to a client.

| Parameter | Notes |
| --------- | ----- |
| `revenue_source` | **Required.** `salla`, `zid`, `google_analytics`, `platform_reported`, `crm`, `manual`, `attributed` |
| `from` / `until` | Inclusive both ends; `until` covers the whole of its day. Defaults to the last 30 days |
| `client_id` / `store_id` | Narrow the scope |
| `limit` | Breakdown rows, clamped to 100 |

A period that runs backwards is a `422` `invalid_period`.

#### The summary

| Field | Notes |
| ----- | ----- |
| `revenue_source`, `is_invoiced`, `is_modelled` | Who is claiming this. Only a store or a CRM invoiced it; `attributed` is a model's output and must never be shown as actual |
| `source`, `source_label` | The **weakest** honesty label behind the total. A figure spanning a sandbox store and a live one is a sandbox figure |
| `orders`, `revenue_minor`, `refunded_minor`, `net_revenue_minor` | Net is what a client should be shown; gross is published beside it so a refund is visible rather than inferred from a smaller number |
| `average_order_value_minor` | **Null when nothing counted.** A denominator of zero has no average, and "0.00" invites a comparison with last month |
| `pending_orders`, `pending_revenue_minor` | Real orders that are not yet money — mostly cash on delivery. Beside the total, never inside it |
| `attributed_*`, `attribution_coverage` | What share of the money can be traced to marketing at all. A channel report at 30% coverage is a different document from one at 95% |

**Only `paid` and `partially_refunded` orders count.** A pending cash-on-delivery order is a real order
and is not money; in this market there are a great many of them, and counting them would overstate every
report in the week before the courier either collects or comes back.

#### The breakdowns

`by_channel`, `by_campaign`, `by_product` and `daily`. Each row carries `net_revenue_minor`, the
**weakest** `evidence` anything in it rests on, and that evidence's `confidence`.

Weakest rather than strongest, deliberately: a group's total is only as defensible as the flimsiest link
inside it, and showing the best one would flatter the row.

Two rules worth stating plainly:

- **`by_campaign` names a campaign only where the evidence can name one.** An order carrying a bare
  `utm_source` names a *channel*; splitting it across a client's campaigns would be inventing the split.
  A coupon-only order does appear, under the code somebody minted, at a confidence below half.
- **The unattributed row is produced, not dropped.** It is usually the largest, and omitting it so the
  percentages add to a hundred is how a channel report comes to overstate every channel in it. It is
  `is_unattributed: true` with a null evidence, so a chart can mark it as the "we do not know" bucket
  rather than drawing it as a channel. It is omitted only when it would be empty.

Breakdowns are limited to `limit` rows and therefore do not add up to the summary, which is why the
summary travels with them: a reader sees the difference as "the rest" rather than concluding the total is
wrong.

Product rows report **no refunds**. A refund is recorded against an order, and splitting it across lines
would be a guess at which item came back; it is visible on the summary, where it is a fact rather than an
allocation.

## Leads

A lead is a person who did something. Counted taps and form starts are **signals**, they live in the
measurement layer, and nothing turns one into a lead — see [LEADS.md](LEADS.md).

`leads.view` to read, `leads.manage` to record and work. A media buyer has view only: a cost per *qualified*
lead is the honest measure of their work, and judging the quality of what you bought is not a judgement to hand
to the buyer.

### `GET /api/leads`

Filters on `client_id`, repeated `stage[]`, `from`, `until` and `limit` (clamped to 500). Contact details are
included, because the people working a pipeline need them; they are never part of a client-portal response.

`hours_to_first_contact` is derived from when somebody actually **got through**, not from when they tried. A
lead contacted within an hour converts at a different rate from one contacted next week, and without that
figure an agency cannot tell a bad campaign from a slow sales team.

`is_measured` is false for a hand-entered or imported lead. Those are real leads and must never be reported as
campaign results.

### `POST /api/leads`

| Field | Notes |
| ----- | ----- |
| `client_id` | Required |
| `source_kind` | Required, and restricted to the kinds a person may record: `form_submission`, `whatsapp_message`, `phone_call`, `chat`, `manual`. `import` and `crm` are refused — letting somebody type one would let a hand-entered row claim to have come from a system |
| `name` / `email` / `phone` | **At least one.** A lead nobody can identify cannot be worked |
| `received_at` | Defaults to now |
| `value` / `currency` | Decimal major units, as everywhere else in this API |
| `utm_*`, `click_identifier`, `referrer`, `landing_page` | Whatever the lead carried. Never invented by the server |

A click is not a valid `source_kind`, and the enum has no member for one.

**Recording the same person twice within a day returns the existing lead.** A Saudi number written four ways is
one phone, and counting them separately would report four times the volume at a quarter of the cost. Outside the
window it is a new lead, because the same person enquiring four months later is enquiring about something else.
A later arrival can **improve** an attribution — an in-app browser strips the parameters and the customer
resubmits properly — but never weaken one.

### `POST /api/leads/{lead}/stage`

`stage` is required. **A disqualification must carry a `reason`**, refused with `422`
`invalid_lead_transition` otherwise: "not a real enquiry", "out of area" and "price" lead to three different
conversations with a client paying for leads.

Moving a lead on clears a disqualification reason, and reopening one clears its closing time — a stale
`closed_at` makes every cycle-time figure wrong, and a CHECK requires the two to agree. Every move is written to
`lead_events` and to the audit log with both stages.

### `GET /api/leads/centre`

The Lead Center, over `days` (default 30, max 365).

`funnel` carries the counts and the rates. **Every rate is null rather than zero when its denominator is
empty**, and each denominator is the defensible one: the win rate divides by **closed** leads, because an open
lead is not a loss; the qualification rate by leads somebody **reached**, because a lead nobody has phoned has
not failed to qualify.

`won_value_minor`, `open_value_minor` and `average_won_value_minor` are **null unless `currency` is set**, and
`currency` is set only where the window's valued leads were all in one currency. `currency_count` says how many
there were, and `is_value_reportable` is the same statement as a boolean. A sum of minor units across currencies
is not a larger or smaller figure — 250,000 halalas plus 100,000 fils is not 350,000 of anything — so the counts
are reported and the money is withheld, with the reason available to say so. No conversion is attempted: a rate
and a date for that rate are decisions this endpoint has no business making silently.

`by_source` and `by_campaign` break the pipeline down, with the unattributed row always produced — for leads it
is usually the largest, since most enquiries carry no parameters and many arrive by phone. Each `by_source` row
carries its own `currency` under the same rule.

`signals` is the counted-tap column: `whatsapp_click`, `phone_click`, `form_start`. It sits **beside** the
funnel and is never summed with it, and **no ratio between the two is published.** A tap and a lead do not share
a denominator — the same person taps three times, or taps and then phones instead — so the response carries
`signals_note` rather than a rate.

### `GET /api/leads/{lead}/journey`

Everything recorded for the person a lead belongs to. Needs `leads.view`.

**Read this alongside [JOURNEYS.md](JOURNEYS.md), which states the blocker first:** only enquiries and orders
appear. The clicks, sessions and ad impressions before them are not available to this product — Google
Analytics' Data API returns aggregated reports rather than per-user event streams, and the documented route to
event-level data is a BigQuery export this product does not read.

| Field | Notes |
| ----- | ----- |
| `identity_evidence`, `identity_confidence`, `is_reliable` | What makes us think these records are one person. `is_reliable` is false for a shared click identifier: strong about the click, weak about the person |
| `touchpoint_count`, `is_multi_touch` | **`is_multi_touch` is the guard against a one-step funnel being read as a whole customer history.** A single record is not a journey, and the response says so |
| `span_in_days` | Null for a single touchpoint, because "nothing elapsed" and "nothing to measure" are different facts |
| `earliest_attributed_touchpoint` | The first record that carried an attribution, as a fact about **that record** |
| `touchpoints[]` | Each with its **own** attribution, state and value. A touchpoint never inherits another's |
| `limitations[]` | What is missing, as data rather than documentation |

**There is no journey-level `campaign` or `attribution`, deliberately.** Deciding which touch earned the credit
is a model — first-touch, last-touch and linear disagree — and a model presented as a fact is what this product
refuses. That belongs to the attribution phase, labelled as modelled.

A lead the actor cannot reach is a `404` rather than an empty journey: an empty one would confirm the id exists.

## Attribution

`analytics.view`, the same permission the rest of the reporting surface uses. Attribution is a view of
money already readable through analytics and commerce, and a separate permission would let somebody be
granted the modelled view and denied the recorded one.

### `GET /api/attribution/models`

The catalogue, so no client hard-codes attribution vocabulary — the same rule the provider registry
sets. It includes the three models that **cannot be computed**, each with `is_available: false`,
`missing` and `would_unblock`. They are published rather than omitted because an absent option gets
asked for again every quarter and an explained one does not.

`is_modelled` is false for exactly one member, `last_recorded`, which reports the campaign the order
arrived carrying. Every surface showing one of these figures is obliged to act on that field.

### `GET /api/attribution`

**`model` and `revenue_source` are both required, with no defaults.** Each is a decision that changes
the answer — "last-recorded revenue from Salla" and "first-recorded revenue from GA4" are different
claims about the same period, and neither is wrong — so a default would make the decision on somebody's
behalf. A missing model answers `model_required` with both the full list and the computable subset;
asking for one that needs a touch path answers `model_not_available` with what is missing and what would
unblock it.

`coverage` is not optional and is built from the same scan as the rows. It carries the **unattributed
revenue as its own figure**, both coverage rates, the share of attributed conversions that name a
campaign rather than a channel, the **weakest** evidence in the set, and a revenue-weighted confidence.
`is_reportable` is false below 70%, at which point the interface leads with the coverage rather than the
rows.

Each row carries a `link` saying how firmly it ties to an ad campaign, and `link_explanation` saying why
an empty spend cell is empty. **Only `exact_name` carries spend**, matched case-insensitively with
surrounding space removed and nothing looser. `roas` is null wherever it would be meaningless: no spend,
zero spend, a link that does not join spend, or two different currencies.

`unmatched_spend_minor` is ad spend in the period that no attributed revenue was matched to — half the
question an agency is asked.

`lookback_days` applies to models that read a person's history and is published with the answer, because
a different window credits different campaigns for the same orders.

### `GET /api/attribution/comparison`

Every available model over one period, plus `campaigns` with each model's figure and the spread between
them. `material_disagreements` counts the campaigns where the choice of model moves the figure by more
than a fifth — the number the surface leads with, because that is the honest headline.

A campaign absent from a model's rows is **zero** when that model's breakdown was not truncated and
**null** when it was: the first is a real disagreement, the second would be a pagination artefact
reported as one.
