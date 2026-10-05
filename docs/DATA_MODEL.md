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
