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

### Metrics

Daily metrics are stored at the finest grain the provider reports, normalised to a
common fact table with provider-specific extras in JSONB. Expected to be the largest
table in the system, so: UUIDv7 keys, composite indexes on
`(project_id, date, external_account_id)`, and aggregation performed in SQL rather than
in PHP.

Normalised metric names are provider-agnostic (`spend`, `impressions`, `clicks`,
`conversions`, `revenue`, …); every adapter maps its own vocabulary into them, and the
mapping is part of the adapter's test suite.

## Migration rules

- One concern per migration; never edit a migration that has run in a deployed
  environment.
- Index creation on large tables uses `CONCURRENTLY` outside a transaction.
- Every foreign key states its delete behaviour explicitly.
