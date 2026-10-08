# Data model

## Principles

- **PostgreSQL** is the only datastore of record. Redis is cache, queue and broadcast
  transport — never a source of truth.
- **UUIDv7 primary keys** on every API-facing/domain entity. Time-ordered, so index
  inserts stay sequential at metrics volume, and safe to expose in URLs. (Internal
  tables such as `personal_access_tokens` keep bigint keys.)
- **Constraints belong in the database**: foreign keys, unique indexes, check
  constraints and not-null are declared in migrations, not merely validated in PHP.
- **JSONB only where flexibility is justified** — provider-specific raw payloads,
  report definitions, widget layouts. Anything filtered, aggregated or joined is a
  real column.
- **Money** is stored as integer minor units plus an ISO-4217 currency code. No floats.
- **Soft deletes** only where a business recovery story exists; otherwise rows are
  archived by status.

## Phase 0 (implemented)

| Table                   | Key    | Notes                                  |
| ----------------------- | ------ | -------------------------------------- |
| `users`                 | UUIDv7 | Identity; profile and roles in Phase 1 |
| `sessions`              | string | `user_id` is a UUID foreign key        |
| `password_reset_tokens` | email  | Framework table                        |
| `personal_access_tokens`| bigint | Sanctum; `tokenable` is a UUID morph   |
| `cache`, `cache_locks`  | string | Cache fallback store                   |
| `jobs`, `job_batches`, `failed_jobs` | mixed | Queue infrastructure      |

## Planned shape

The isolation spine, introduced in Phases 1–2:

```
Workspace ─┬─ User (via membership + role)
           ├─ Client ─── Project ─┬─ ProjectIntegrationBinding ─── ExternalAccount
           │                      ├─ Campaign ─ AdGroup ─ Ad ─ Creative
           │                      ├─ Budget ─ Allocation ─ SpendLimit
           │                      ├─ Task / Approval / Report / Presentation
           │                      └─ AuditLogEntry
           └─ ProviderConnection ─── ExternalAccount
```

- A `ProviderConnection` holds encrypted credentials and belongs to a workspace.
- An `ExternalAccount` is a discovered ad account under a connection.
- A `ProjectIntegrationBinding` is the **explicit** grant that lets one project read one
  external account. Without a binding, a project sees nothing — this is the rule that
  keeps one client's spend invisible to another.

### Integrations (Phase 2, implemented)

| Table | Key | Notes |
| ----- | --- | ----- |
| `provider_connections` | UUIDv7 | One per provider per workspace. Holds the encrypted credential blob, hidden from serialisation. |
| `external_accounts` | UUIDv7 | Discovered ad accounts. `workspace_id` denormalised from the connection so scoped queries need no join. Unique on `(provider_connection_id, external_id)`. |
| `project_integration_bindings` | UUIDv7 | The grant. Unique on `(project_id, external_account_id)`; deleting it revokes access immediately. |

Deleting a connection cascades to its accounts and their bindings, by the
schema's own foreign keys rather than by application code remembering to.

### Advertising structure (Phase 3, implemented)

| Table | Key | Notes |
| ----- | --- | ----- |
| `campaigns` | UUIDv7 | Unique on `(external_account_id, external_id)`. Carries the normalised status **and** objective plus the provider's own `raw_status`, `raw_effective_status` and `raw_objective`. |
| `ad_groups` | UUIDv7 | Meta's ad set, Google's ad group, X's line item, LinkedIn's campaign, under one vocabulary. Targeting kept verbatim in JSONB, because it is deeply nested and never filtered in SQL. |
| `creatives` + `creative_assets` | UUIDv7 | One creative, many assets: a carousel's cards, a video and its thumbnail, a responsive ad's variants. Assets are replaced wholesale on sync, never merged. |
| `ads` | UUIDv7 | Links ad group, campaign and creative. `creative_id` is nullable and does not cascade: creatives are shared, and an ad can be synced before its creative is. |

Both a normalised value and the provider's own string are stored for status and
objective. That is not redundancy. When a campaign is "active" but not
delivering, the answer is in Meta's `effective_status`, and an operator comparing
our screen with the platform's has to see the same word.

### Metrics (Phase 3, implemented)

| Table | Key | Notes |
| ----- | --- | ----- |
| `daily_metrics` | UUIDv7 | The fact table. One row per entity per day per level. |
| `conversion_metrics` | UUIDv7 | One row per action type per entity per day. |

Four decisions in `daily_metrics` are worth stating, because each one prevents a
specific wrong number:

1. **Levels are stored, not derived.** A campaign row is fetched from the
   provider, not summed from its ads. Reach is deduplicated across children and
   attribution moves conversions between levels, so a rolled-up figure is one no
   platform would agree with.
2. **Every count is nullable.** `NULL` means the provider did not report the
   metric; `0` means it reported none. Conflating them turns a reporting gap into
   a performance story — a non-video ad with `video_completions = 0` reads as
   "nobody finished it".
3. **Money is `bigint` minor units,** never a float, and `date` is a `date` in
   the account's own timezone. A platform's "day" is defined in that timezone,
   and converting would shift spend between days.
4. **`source` is never absent.** It holds a `MetricSource` — `live`, `synced`,
   `stale`, `partial`, `sandbox`, `manual` or `estimated` — and the interface
   must label anything that is not `live`. An aggregate carries its weakest
   input's label, so one sandbox row makes the whole figure sandbox.

Idempotency is enforced by the database, not by the sync remembering to check:
`UNIQUE (entity_type, entity_id, date)` on `daily_metrics` and
`UNIQUE (entity_type, entity_id, date, raw_action_type, attribution_window)` on
`conversion_metrics`. The latter's `attribution_window` is **not nullable** and
defaults to `'default'`, because PostgreSQL treats `NULL`s as distinct in a
unique index — a nullable column there would make every re-sync insert a second
row instead of updating the first, and conversions would double every time a day
was re-read. Which is exactly what settling attribution requires us to do.

Normalised metric names are provider-agnostic (`spend`, `impressions`, `clicks`,
`conversions`, `revenue`, …); every adapter maps its own vocabulary into them, and the
mapping is part of the adapter's test suite.

### Sync bookkeeping (Phase 3, implemented)

| Table | Key | Notes |
| ----- | --- | ----- |
| `sync_runs` | UUIDv7 | Every attempt, successful or not, with range, counts, duration and error. Answers "why is yesterday missing?" from the product rather than from a log file. |
| `sync_cursors` | UUIDv7 | Unique on `(external_account_id, kind)`. Holds an opaque pagination cursor for structure and a `synced_through` / `resync_from` watermark pair for time series. |
| `provider_errors` | UUIDv7 | Normalised failures by category (`auth`, `rate_limit`, `permission`, …), so a throttled connection reads as a pattern rather than a one-off. |

`resync_from` exists because performance data is not finished when a day ends.
Meta attributes a purchase to the day of the click, so with a 28-day window last
month's figures are still moving; each run deliberately re-reads that far back
rather than trusting the high-water mark.

## Migration rules

- One concern per migration; never edit a migration that has run in a deployed
  environment.
- Index creation on large tables uses `CONCURRENTLY` outside a transaction.
- Every foreign key states its delete behaviour explicitly.

### Saved views and dashboards (Phase 4, implemented)

| Table | Key | Notes |
| ----- | --- | ----- |
| `saved_views` | UUIDv7 | A stored analytics query, as JSONB. Unique on `(workspace_id, created_by, name)` |
| `dashboards` | UUIDv7 | Name, audience, and the filters its widgets inherit |
| `dashboard_widgets` | UUIDv7 | Type, metrics, dimension, optional filter override, and a placement on the 12-column grid |

The query and the filters are JSONB rather than columns, which is a deliberate
trade: neither is ever filtered or aggregated in SQL, both follow the analytics
request object which will keep growing, and a column per filter would mean a
migration every time one is added. What it costs is that an invalid query is caught
only on read — which is why the request object validates it again on the way out.

Two CHECK constraints carry rules the application would otherwise have to remember:

- `visibility <> 'project' OR project_id IS NOT NULL`. Project-scoped visibility
  without a project has no defined audience.
- `grid_column + grid_width <= 12 AND grid_width >= 3 AND grid_height BETWEEN 1 AND 6`.
  A widget stored outside the canvas disappears from the dashboard with nothing to
  say it was ever there.

`dashboard_widgets.filter_overrides` is **nullable and that nullability is the
model**: null means "inherit the dashboard's filters", `{}` means "override them with
nothing". A cast or default that collapsed the two would silently change what a
widget shows.

### Budgets and allocations (Phase 5, implemented)

| Table | Key | Notes |
| ----- | --- | ----- |
| `budgets` | UUIDv7 | A client's approved amount for a period, with who approved it and when. Unique on `(workspace_id, client_id, name, period_start)` |
| `budget_allocations` | UUIDv7 | A self-referencing tree: platform → campaign → ad group, each row naming its provider and, where known, the account, campaign or ad group it is for |

`client_id` is **required** and `project_id` is **optional**, which is the agency
reality: a client's quarterly budget is agreed before anyone decides which campaigns
will spend it. A budget with no project covers every account bound to that client's
projects; one with a project covers that project's accounts only.

Five CHECK constraints carry rules the application would otherwise have to remember
on every write:

- `period_end >= period_start`, because every pacing figure divides by the period.
- `amount_minor > 0`. A budget of zero is permanently "exhausted" by the arithmetic,
  which would raise an overspend alert about a figure nobody has set.
- `status IN ('draft', 'approved', 'archived')`.
- `status <> 'approved' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)`. A
  budget marked approved with nobody's name on it is exactly the record this table
  exists to make impossible.
- `(level = 'platform' AND parent_id IS NULL) OR (level <> 'platform' AND parent_id IS
  NOT NULL)`. Without it the tree can grow sideways — a campaign allocation hanging
  off the budget directly — and the platform level stops summing to the budget.

Pacing is **not stored**. It is computed from the budget, the period, the spend in
`daily_metrics` and today, every time it is asked for. A stored pacing figure would be
wrong the moment the next sync landed, and a stale alert is worse than none because it
teaches people to ignore the list. The same goes for the alert list and for forecast
rates: all three are derived, so they cannot disagree with each other.

### Measurement (Phase 7, implemented)

Four tables behind [MEASUREMENT.md](MEASUREMENT.md).

`measurement_sources` — what reports events or orders for a client: a GA4 property, a GTM
container, an ad account, a store, a CRM, a website form, a typed figure. `client_id` required,
`project_id` optional, `provider` nullable because a CRM and a phone log have no platform in
the registry. `last_synced_at` and `last_event_at` are both kept, because "the figures are
old" and "it is reporting nothing" are different problems with different remedies. Freshness
itself is **not stored** — it is derived on every read, since it is a statement about now.

A unique index on `(workspace_id, client_id, provider, external_id)`: two records for one
property would double every count read through it.

`event_definitions` — the conversion registry. Unique on
`(workspace_id, client_id, project_id, name)`, with a CHECK that
`is_primary = false OR is_conversion = true`: "the most important thing we are not counting as
a conversion" is not a sentence anybody means, and the database is where that stays true
regardless of which code path writes.

`event_mappings` — which raw event names mean a definition. **This table is the reason nothing
in this product guesses what an event means.** One property's `contact` is a form and another's
is a phone tap, so a raw name counts only because somebody mapped it here.
`measurement_source_id` is nullable and means *from any source*, which is why the read model's
unmapped-name join matches on name alone when it is null.

`observed_event_counts` — one source, one raw name, one day: a count, an optional
`value_minor` and its currency. An aggregate deliberately; a row per event would be a firehose
with no question behind it, and every question this product asks is per day. Two CHECK
constraints: counts and values are non-negative, and **a value implies a currency** — a value
without one is a number without a unit, and the first thing anyone would do with it is add it
to another one.

Deletes cascade in the direction meaning flows: observed counts go with their source, because
they only ever meant anything *as read through it*, and mappings go with their definition.

### Commerce (Phase 11, implemented)

Eight tables behind [ECOMMERCE.md](ECOMMERCE.md), written before either store provider so that
neither defines the shape.

`stores` is client-scoped and the client is **required**: a store with no client is a set of orders
nobody can report on. Unique per workspace, provider and external id.

`store_orders` carries **two state columns**, `payment_state` and `fulfilment_state`, and that is the
most consequential decision in the schema. Every platform has both axes; a single `status` makes
"delivered but refunded" unrepresentable, and the first thing anybody does with a single status is sum
revenue by it. Each provider's own strings travel alongside in `raw_payment_status` and
`raw_fulfilment_status`, because a mapping will be disputed the first time a client's figures differ
from their platform dashboard.

Money is integer minor units with its currency. A discount is a **positive amount taken off**, never a
negative one added — mixing those conventions across providers is how a discount comes to increase
revenue, and a CHECK refuses a negative.

The constraint this schema exists to carry:

```sql
CHECK (attribution_evidence = 'none'
       OR utm_source IS NOT NULL OR utm_medium IS NOT NULL OR utm_campaign IS NOT NULL
       OR click_identifier IS NOT NULL OR coupon_code IS NOT NULL OR referrer IS NOT NULL)
```

An order claiming campaign parameters with no parameters on it is the one defect here that no report
could reveal afterwards, so the database refuses it and a second write path cannot produce it.

`store_order_items` keeps the name and price **as sold**. A line that read the catalogue would rewrite
last quarter's revenue whenever somebody changed a price. Its product reference is nullable, because an
order can name a product since deleted and the line is still a real line of a real sale.

`store_customers` holds the client's customers' contact details and **derives** its lifetime totals
from the orders actually stored, counting only states that recognise revenue and subtracting refunds. A
provider's own total includes orders this workspace never read; the figure that reconciles is worth more
than the one that is larger. This is personal data belonging to the client — see `docs/SECURITY.md`.

`store_webhook_events` is written before any work is done, with a unique index on
`(provider, external_event_id)` and an `ON CONFLICT DO NOTHING` insert. A violation would abort the
surrounding PostgreSQL transaction, turning a harmless redelivery into a failed request for a handler
that also writes an order.

Everything upserts, because every sync re-reads a restatement window and an insert would multiply a
client's revenue by the number of runs. Order lines, refunds and shipments are **replaced** — the lines
are the whole truth about what was sold. Variants are **upserted**, because order items reference them
and deleting one would lose which variant a past sale was for.

### Phases 15 and 20 (implemented) — the tables these notes had not caught up with

This document stopped at Phase 11 while the schema kept going. Two tables added since are worth the same
treatment as the ones above, because both carry a rule in the database rather than only in PHP.

`client_request_attachments` holds the files on a client request, and its columns are the security argument. The
**audience** is on the row, not derived, so an internal attachment is excluded from a client's read in the same
WHERE clause their internal notes are — a competitor's rate card and a note about a client are the same leak with
different consequences. `sniffed_type` is what the file's **bytes** said rather than what its name or header
claimed, constrained to the allow-list so a type this product will not serve cannot be stored at all.
`stored_name` is generated and unique; `original_name` exists only to show a reader what they sent, because a
path built from an uploader's filename is a path traversal. A CHECK enforces that a file from a client is always
visible to that client, for the same reason the messages table does: an interface bug that filed their own upload
as internal would hide it from the person who sent it.

`leads` gained no columns for the CRM bridge and that is the point. A CRM-sourced lead is recognised again by
`(source_kind, external_id)`, which already existed; the **CRM's own stage label** goes in `source_detail`,
because it is a fact about the source rather than a second stage column; and `stage` is written by the sync
rather than by a person, which is the one case where a re-read overwrites instead of filling a gap. The existing
`leads_closed_has_time` CHECK then does real work: a deal closed in the CRM has to arrive with the CRM's own
closing time, never the sync's clock, or the row is refused.
