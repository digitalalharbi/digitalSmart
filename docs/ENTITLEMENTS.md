# Plans, features, limits and seats

PR C of the SaaS chain (`docs/SAAS_CAMPAIGN_MASTER.md`). What a workspace **has**, as opposed to what a person **may do**.

## Two questions, both asked

| | Answers | Where |
|---|---|---|
| Permission | Is this person allowed? | `AuthorizationGuard`, roles (`docs/SECURITY.md`) |
| Entitlement | Does this workspace have the capability, and is there room? | `EntitlementGuard`, plans |

Neither answers for the other. An owner holds every permission by construction and is still refused a feature their plan does not
include (`EntitlementsTest`). A client viewer on a plan that includes automation is still refused automation, as a permission
refusal with no `feature` in the body.

## The catalogue

One enum each, nothing scattered: `App\Domain\Saas\Feature` (27 features) and `App\Domain\Saas\Limit` (13 limits).

Ten features are in every plan and cannot be switched off — overview, clients, projects, campaigns (read), analytics, tracking
links, reports, tasks, approvals, notifications. They are what make the product a product; a workspace without an overview is a
sign-in screen.

## Plans

`App\Domain\Saas\PlanCatalogue`, seeded into `plans`, `plan_features`, `plan_limits` by the migration. **No prices** — pricing
is outside the domain.

| Plan | Adds |
|---|---|
| Starter | Creative operations. Small limits; no automation runs, no AI requests, no campaign launches |
| Growth | Forms, funnels, automation, advanced analytics, commerce, CRM |
| Agency | Campaign launch, platform changes, client portal, creative intelligence, attribution, agents, AI, presentations. No limits |
| Enterprise | Everything, including white label and API access. No limits |

Every workspace has a row in `workspace_subscriptions`. Existing workspaces got the default plan in the migration; new ones get
it when provisioned. The default is `SAAS_DEFAULT_PLAN` (`agency` when unset), so an installation that has not decided anything
keeps every feature it had before this PR.

## How a feature is refused

Routes carry `feature:<key>` middleware (74 routes: agents, MCP, assistant, automation, presentations, commerce, CRM read,
attribution, portal, forms, funnels, creative intelligence and operations, platform changes). A workspace without the feature
gets:

```json
403 { "code": "feature_not_in_plan", "feature": "automation", "plan": "starter", "message": "…" }
```

Scheduled work honours it too: the automation runner passes over a workspace whose plan has no automation, or whose month of
rule runs is spent. Its rules stay as they are and run again when there is room.

## Limits

| Limit | Measured from | When exhausted |
|---|---|---|
| internal_users, client_users | active memberships by seat type, the agent identity excluded | the next member is refused |
| clients, projects | live rows | the next one is refused |
| provider_connections | rows | a new connection is refused; replacing credentials is not |
| external_accounts | rows | shown |
| exports_monthly | export jobs requested since the 1st (UTC) | the next request is refused |
| automation_runs_monthly | rule executions since the 1st | the runner stops for the month |
| ai_requests_monthly | `usage_meters`, one per question, idempotent by event key | the next question is refused |
| tracking_events_monthly, form_submissions_monthly | rows since the 1st | **counted, never refused** |
| storage_bytes | attachment bytes | shown |
| campaign_launches_monthly | — (launches arrive with PRs E–G) | `used` is `null`: "not measured yet" |

Nothing is estimated. Usage is derived from the rows that already record the thing, or recorded once per event key in
`usage_events` and summed into `usage_meters`.

Visitor traffic is never refused: turning away a client's visitor or dropping their form submission would cost the client, not
the agency. The count is shown against the limit on the plan page, so the overage is visible rather than silent.

A refusal is structured:

```json
409 { "code": "quota_exceeded", "limit": "clients", "used": 5, "allowed": 5, "resets_at": null, "message": "…" }
```

**The check and the write happen under one lock** (`pg_advisory_xact_lock` per workspace and limit, `EntitlementGuard::claim`),
so two people adding the last client the plan allows cannot both succeed.

## Downgrades remove nothing

Moving a workspace to a smaller plan keeps every client, project, member and connection. The plan page marks each limit the
workspace is over and says nothing was removed; the next one is refused until there is room. A feature the plan no longer has
stops answering; nothing it made is deleted.

## Seats

`workspace_members.seat_type`: `full`, `limited`, `client`, `guest`. Full and limited count against `internal_users`; client and
guest against `client_users`. A person joining as a client viewer takes a client seat, anybody else a full seat. A seat is not a
role: changing somebody's role never changes their seat. The seat is claimed when an invitation is **accepted**, not when it is
made — a link nobody uses takes no room.

## Overrides

One workspace, one feature or limit, above its plan. `workspace_entitlement_overrides` is append-only: each row records the key,
the new value, the value it replaced, the reason, who did it and when. The newest row for a key is in force; `inherit` hands the
key back to the plan.

Until the platform console exists (PR D), plans and overrides are set by the operator from the command line. Both refuse without
an operator name and a reason, and both are written to the workspace's audit trail (`workspace.plan_changed`,
`workspace.entitlement_overridden`):

```bash
php artisan saas:plan <workspace-id> growth --operator="ops@smartcode" --reason="Signed Growth"
php artisan saas:override <workspace-id> limit clients 40 --operator="ops@smartcode" --reason="Seasonal campaign"
php artisan saas:override <workspace-id> feature automation inherit --operator="ops@smartcode" --reason="Trial ended"
```

## What the workspace sees

Administration → **Plan & usage** (`workspaces.update`), from `GET /api/workspace/plan`: the plan, every limit with used against
allowed and the reset date for monthly ones, every feature with whether it is included, and the overrides that changed either.
Read-only — a plan is changed by Smart Code, not from inside the workspace.

## Proof

`backend/tests/Feature/Saas/EntitlementsTest.php`: default plan on provisioning; plan page permission; an owner refused a feature
the plan lacks until an override grants it, and refused again on `inherit`; a permission refusal staying a permission refusal; a
client refused past the limit with the figures while existing clients stay; a downgrade that removes nothing and reports the
overage; agency and client seats counted apart; a metered event counted once; every change audited with operator and reason, and
refused without them.
