# 7. PostgreSQL row-level security: not now, and what would make it safe

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

Workspace is the SaaS tenant. Isolation is enforced today by three layers:

1. **Application permission** — every use case asks the `AuthorizationGuard` before it reads or writes.
2. **Workspace-scoped queries** — every repository and read model filters by the actor's `workspace_id` (and by project
   visibility where the reader is not workspace-wide), and answers another tenant's id as not found.
3. **Database constraints** — `workspace_id` foreign keys on business tables, cascading parents for the few that are scoped
   through one, and unique constraints that name the tenant or a tenant-owned parent (`TenantSchemaTest`).

PR A of the SaaS program (`docs/SAAS_CAMPAIGN_MASTER.md`) asked whether PostgreSQL row-level security (RLS) should become a
fourth layer.

## What RLS would buy

A query that forgets its `workspace_id` filter would return nothing instead of another tenant's rows. That is exactly the
class of defect the tenant route matrix found in this PR (`ensureProjectVisible` returning early for workspace-wide readers):
with RLS on `project_members`, the leaked member list would have been empty.

## What it would cost here

- **Connection context.** RLS needs the tenant on the connection (`SET app.workspace_id = …`) for every request, job and
  command. Laravel reuses connections; under PHP-FPM a request that forgets to set or reset the variable inherits the previous
  request's tenant — the very leak RLS is meant to stop, now silent. Pooling (PgBouncer in transaction mode) makes `SET`
  session state unreliable; `SET LOCAL` inside an explicit transaction on every request is the only safe form.
- **Queue workers and Horizon** process jobs for many tenants on one long-lived connection. Every job would have to set the
  context first and clear it after, including on failure paths and in `failed()` handlers.
- **Cross-tenant work is legitimate in places**: the scheduler's sweeps (`alerts:raise`, `access-requests:alerts`,
  `leads:sla-alerts`, digests, syncs) iterate every workspace; migrations; operator health checks; the future platform control
  plane (PR D). Each needs a role with `BYPASSRLS` or a policy exception — a privileged path that must itself be audited.
- **Support impersonation** (PR D) would need the tenant context switched for the session, with start and end audited.
- **Exports** run as the requesting user in a worker; they would need the context bound beside the actor (already done for
  the actor in `RunExportJob`).
- **Tests** run every feature test as a superuser on one connection with `RefreshDatabase`; policies would be bypassed unless
  the suite ran as an unprivileged role, which changes how migrations and factories run.
- **Performance**: policies add a predicate to every query plan; the composite indexes already lead with `workspace_id` for
  the large tables, so the cost is small but not zero, and planner behaviour with `current_setting()` needs measuring.
- **Transitive tables** (`project_members`, `form_fields`, `dashboard_widgets`, …) have no `workspace_id`; their policies
  would need sub-selects to the parent, or the column denormalised first.

## Decision

**RLS is not enabled now.** The three existing layers stay the defence, and they are made executable:

- `TenantRouteMatrixTest` calls every authenticated route that takes an identifier with another workspace's identifiers
  (and every list with another workspace's filters) and fails on any 2xx, any 5xx, or any foreign id in a response.
- `TenantSchemaTest` fails on a table nobody has classified and on a unique constraint that could let one tenant block another.

RLS may become a fourth layer later, rolled out in this order, each step its own PR:

1. Denormalise `workspace_id` onto the transitive tables that hold sensitive data (`project_members`, `form_fields`).
2. Run the test suite as an unprivileged database role, with a separate migration role.
3. Bind the tenant per request and per job with `SET LOCAL app.workspace_id` inside a transaction, through one middleware
   and one job middleware, and fail closed when it is absent.
4. Enable policies **in permissive audit mode first** on a few high-value tables (leads, form submissions, store customers),
   logging would-be denials, then enforce.
5. A `BYPASSRLS` role for the scheduler, migrations and the platform control plane, used only by those entry points.

## Consequences

- Isolation depends on application code being right, so the matrix and schema tests are mandatory gates, not documentation.
  Every new route that takes an identifier must enter the matrix or name the test that covers it.
- The cost of RLS is deferred until the platform control plane and impersonation exist, which is when its privileged paths
  can be designed once instead of retrofitted.
