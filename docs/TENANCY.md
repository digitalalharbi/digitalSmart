# Tenancy

**Workspace is the tenant.** A Client and a Project are scopes inside it, never tenants. One person is one `users` row and
may belong to several workspaces through `workspace_members`; their permissions, clients, connections and (later) plan are
those of the workspace they are working in.

```
Workspace (tenant, subscription boundary later)
├── Client → Project → (project_members grant sight of the client's data)
└── ProviderConnection → ExternalAccount → ProjectIntegrationBinding
```

## The three defences, and the tests that hold them

| Defence | Where | Executable proof |
|---|---|---|
| Permission | `AuthorizationGuard` in every use case; `ensureProjectVisible` for project-scoped reads | feature tests per module |
| Workspace-scoped query | repositories and read models filter by the actor's `workspace_id`; another tenant's id is **not found** (404), never forbidden | `tests/Feature/Tenancy/TenantRouteMatrixTest.php` |
| Database constraint | `workspace_id` FKs; transitive tables cascade from a tenant-owned parent; uniques name the tenant or a tenant-owned parent | `tests/Feature/Tenancy/TenantSchemaTest.php` |

Row-level security is evaluated in [ADR 7](adr/0007-postgresql-row-level-security.md): not enabled now, with the rollout that
would make it safe.

## The executable matrix

`TenantRouteMatrixTest` builds the demo and the demo agency as the foreign tenant, gives it one of everything its seeders do
not make (through the API as its own owner), and then, as the owner of a fresh workspace holding every permission:

1. **Every authenticated route that takes an identifier** (152 calls at the time of writing) is called with foreign
   identifiers. Not one may answer 2xx or 5xx. A route whose identifier the matrix cannot source fails the test, so the
   matrix cannot fall behind the API; the four routes outside it each name the test that covers them instead.
2. **Every authenticated list, search and filtered read** is called with foreign ids as filters (`client_id`, `project_id`,
   `campaign_id`, `report_id`, `lead_id`, `account_id`, `q`, …). No response may contain a foreign identifier or a foreign
   client's name.

That covers list, read, search, filter, reference by known UUID, update, delete, archive, attach, assign, export, share,
report, presentation, task creation, linking, provider mutation, analytics and AI reads, and agents — each is a route. Being
told about another tenant's thing is covered by the notification resolver's tests (`PermissionAwareRecipientResolver`), which
resolve recipients from the subject's own workspace.

## What the audit found and fixed (PR A)

| Finding | Severity | Fix |
|---|---|---|
| `GET /projects/{project}/members` listed **another workspace's project members** (names, emails, roles) to any reader holding `projects.view_all` | **High** — cross-tenant personal data | `ensureProjectVisible` now requires the project to be in the active workspace before workspace-wide visibility applies; not found otherwise. Same root cause closed `/assignable` and `/integrations`. |
| `PUT /shortcuts/pins/{type}/{id}` and recents stored a reference to another workspace's client, project or campaign | Medium — cross-tenant reference | the store checks the thing exists in the workspace; not found otherwise |
| `GET /portfolio/clients/{client}` answered 200 (empty) for another tenant's client | Low — existence not denied | client visibility checked first; not found |
| `GET /reports/{report}/snapshots` and `/shares` read straight from the controller: foreign id → empty 200; inside the tenant, no permission and no client visibility check | Medium — in-tenant authorization gap | `ReadReportHistory` use case: `reports.view` / `reports.share` and visibility, or not found |
| `POST /analytics/views/{view}/opened` stamped any id in the workspace, including a colleague's private view | Low | `MarkViewOpened` finds the view the way reading it does |
| `store_webhook_events` unique on `(provider, external_event_id)` across all workspaces: two agencies registering one shop — the second's delivery read as a redelivery and dropped | Medium — one tenant silently losing orders | unique now `(workspace_id, provider, external_event_id)`; regression test |

## Tenant ownership of every table

`TenantSchemaTest` classifies each table:

- **Carries `workspace_id`** — every business table.
- **Scoped through a cascading parent** — `automation_rule_streaks` (rules), `creative_assets` (creatives),
  `dashboard_widgets` (dashboards), `form_fields` (forms), `lead_round_robin`, `project_members`, `tracking_daily_rollups`
  (projects). Each FK is `ON DELETE CASCADE` to a table that carries `workspace_id`.
- **Global by nature** — `users` (identity), `permissions` and `permission_role` (catalogue), `creative_specs` (platform
  specifications), `personal_access_tokens` (owned by a user, `mcp` ability only), and the framework's tables.

Unique constraints on tenant tables either include `workspace_id`, or are bounded by a tenant-owned parent (an external
account, a project, a store, a report…), or are a random token unique everywhere on purpose. External identities are always
bounded by the tenant's own `external_accounts` row: two workspaces connecting one real ad account get two rows and two
independent campaign hierarchies.

## Cross-tenant references refused

| Reference | Refused by |
|---|---|
| Project A binding ExternalAccount B | `BindAccountToProject` finds both the project and the account in the actor's workspace, or not found |
| Tracking link A bound to campaign B | `EloquentCampaignBindingResolver` resolves the hierarchy within the link's workspace and project |
| Lead A assigned to a user without access | `OwnerDirectory` (active member, permission, client visibility) |
| Report A including project/client B | `SaveReport` refuses a client the reader cannot see in their workspace |
| File A attached to client B | attachments live under a request, found in the reader's workspace and audience |
| Provider write A against account B | `CarryOutApproval` reads the account and connection in the approval's workspace |
| Pin / recent A naming B's entity | the shortcut store (this PR) |

## Queue and job isolation

| Job | Scope carried | Revalidated before external work |
|---|---|---|
| `SyncBoundAccountJob` | workspace id + external account id | account found in the workspace, at least one sync-enabled binding, connection in the workspace |
| `ReadStoreJob` | workspace id + store id | store found in the workspace |
| `RunExportJob` | export id + workspace id + user id | the actor is bound as that user in that workspace; every read re-runs their permissions (a removed member holds none) |
| `DispatchProviderEventJob` | dispatch id | the dispatch row's own workspace decides the connection; nothing is taken from a session |
| `ProcessStoreWebhookJob` | journal event id | only a signature-verified row is processed, in the workspace the signature matched |

No job reads the session or "the current workspace"; switching workspace in a browser cannot change what a queued job does.

## Files

Private disk only; read back through an endpoint that re-applies the reader's access. New writes are keyed
`workspaces/{workspace_id}/client-requests/…` and `workspaces/{workspace_id}/exports/…` (older rows keep the path they were
stored with). No tenant file has a public URL. Presentations are rendered on request and not stored. The only public files
are a user's avatar (identity, not tenant data) and the product's own assets.

## Cache, realtime, search

- **Cache** holds no tenant data today (a health sentinel and the public GitHub status). A tenant-dependent cache key must
  include the workspace id.
- **Realtime**: one private channel per person per workspace and one per board per workspace; `ChannelAuthorizer` re-checks
  active membership and the board's permission on every subscription; payloads carry no readable content.
  (`tests/Feature/Notifications/BroadcastChannelTest.php`.)
- **Search** and the command palette are routes; the list sweep calls them with foreign filters and a search term.

## Rules for new code

- A new route that takes an identifier enters the matrix (add its parameter to `TENANT_PARAM_TABLES`) or names its test.
- A new table carries `workspace_id`, or cascades from a parent that does, or is declared global with a reason.
- Another tenant's identifier is answered **not found**.
- A queued job carries the ids it needs and re-reads them in their workspace before calling anybody outside.
