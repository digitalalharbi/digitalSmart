# Tenant isolation acceptance report

How one workspace is kept from another's data, as proven by executable checks. Details of the model are in `docs/TENANCY.md`.

| Requirement | Status | Evidence |
|---|---|---|
| Every route that takes an identifier refuses another workspace's — 404, never success or a server error | VERIFIED | `TenantRouteMatrixTest`: every `{param}` route swept with a foreign id, including campaign plans and launch executions |
| List and filter endpoints do not leak another workspace's rows | VERIFIED | `TenantRouteMatrixTest` (list sweep) |
| Every table names its tenant, or is classified as transitive or global with a reason | VERIFIED | `TenantSchemaTest` (plans catalogue global; plan targets, creatives, launch executions transitive) |
| Every tenant-less unique constraint is explained | VERIFIED | `TenantSchemaTest` (tokens and idempotency references unique across the installation on purpose) |
| Project visibility inside a workspace (membership ≠ all projects) | VERIFIED | `CampaignPlanTest`, existing visibility suites |
| Files stored under the workspace's own prefix | VERIFIED | `TenantStorageTest` |
| Queued jobs carry their workspace and re-read under it | VERIFIED | launch and activation jobs take the workspace id; `CampaignLaunchTest` |
| Shared cache cannot carry tenant data | VERIFIED | architecture test forbids the `Cache` facade in every layer |
| Realtime channels authorise per person and workspace; payloads carry no readable data | VERIFIED | `BroadcastChannelTest` (`docs/OPERATIONS.md`) |
| Search and the command palette scope to the active workspace | VERIFIED | search suites; active workspace bound per request |
| Exports re-authorise at generation; shared report links only narrow | VERIFIED | export and report-share suites |
| AI context built only from already-authorised tenant data | VERIFIED | assistant suites (`ClientVisibility` before any read) |
| Platform operators are not tenant members and see no tenant business data | VERIFIED | `PlatformTest` |
| Active-workspace switch race (in-flight requests reverting a switch) | VERIFIED (fixed) | `MultiWorkspaceIdentityTest` |

**Database row-level security** is designed, not enabled: `docs/adr/0007-postgresql-row-level-security.md` records the decision
and the conditions for turning it on. Isolation today is enforced in the application and proven by the matrix above.

## Findings fixed during the program

Six in PR A (one high: cross-tenant project members via a `view_all` path), recorded in `docs/TENANCY.md`; the session race in B.
