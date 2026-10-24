# SaaS acceptance report

The SaaS half of the A→H program (`docs/SAAS_CAMPAIGN_MASTER.md`). Status words are `CLAUDE.md`'s: **VERIFIED** means built and
proven here by the named tests; nothing in this report is `LIVE_VERIFIED`, because there is no production server and no live
provider credential.

| Requirement | Status | Evidence |
|---|---|---|
| Workspace is the tenant; one identity in many workspaces with a role each | VERIFIED | `MultiWorkspaceIdentityTest` (B) |
| Audited workspace switch; active workspace in its own HTTP-only cookie | VERIFIED | `MultiWorkspaceIdentityTest` (race test included) |
| Memberships invited / active / suspended / revoked; revoke keeps history; re-invite reactivates | VERIFIED | `MultiWorkspaceIdentityTest`, `MemberAdministrationTest` |
| Workspace types agency / brand / freelancer / internal team, never weakening authorisation | VERIFIED | `MultiWorkspaceIdentityTest`, `PlatformTest` |
| One feature catalogue (27) and limit catalogue (13); plans with no prices | VERIFIED | `EntitlementsTest` (C) |
| Permission ≠ entitlement, enforced on the server for every caller | VERIFIED | `EntitlementsTest`: an owner is refused a feature the plan lacks |
| Quotas with locked claims, structured `409`, reset dates, no silent overage | VERIFIED | `EntitlementsTest`; visitor traffic counted, never refused, shown on the plan page |
| Usage derived or recorded idempotently | VERIFIED | `EntitlementsTest` (metered event counted once) |
| Seats full / limited / client / guest, separate from roles; invitation may name one that fits the role | VERIFIED | `EntitlementsTest`, `CampaignLaunchTest` (seat on invitation) |
| Overrides append-only with previous value, reason, operator, time | VERIFIED | `EntitlementsTest`, `PlatformTest` |
| Downgrade removes nothing; controlled over-limit state | VERIFIED | `EntitlementsTest` |
| Subscription states trial / active / past due / grace / suspended / cancelled with defined behaviour; no data deleted | VERIFIED | `PlatformTest` (D) |
| Suspension stops writes and scheduled work, never pauses platform campaigns | VERIFIED | `PlatformTest`; `RunningWorkspaces` used by every scheduler |
| Platform console `/platform`, operators set only from the console, not tenant owners, no tenant data shown | VERIFIED | `PlatformTest`, `PlatformPage.test.tsx` |
| Onboarding: request → review → approve → invitation / new workspace; never automatic from a public request | VERIFIED | `AccessRequestTest` (#60), `PlatformTest` (create workspace) |
| Workspace admin UX: members, roles, seats, plan, usage, features, audit | VERIFIED | Administration → People, Roles, Plan & usage, Audit |
| Plan changes never mutate roles; permissions never mutate subscriptions | VERIFIED | separate tables and use cases; `EntitlementsTest` |
| Billing provider-neutral (plan, subscription, seat, entitlement, usage) | VERIFIED (boundary) | no billing provider code in the domain; states change only through `ChangeSubscriptionStatus` |
| Observability: quota refusals and launch problems logged without PII | VERIFIED | `saas.quota_refused`, `campaign_launch.execution_not_verified` log lines (ids and states only) |

## Readiness, not built

- **White label** (workspace logo, report and portal identity, sender identity): the workspace record and the `white_label`
  feature exist; no branding was rewritten.
- **Public API tokens** are not issued: `api_access` is an Enterprise entitlement and nothing exposes SaaS API tokens yet.
- **Support impersonation** is not built; the rules it must follow are in `docs/PLATFORM.md`.
- **Automatic billing**: no billing provider is connected, so nothing moves a subscription between states on its own.

## External blockers

| Blocker | Status | What unblocks it |
|---|---|---|
| Production server | `AWAITING_SERVER_ACCESS` | host, SSH user, port, path, host key, and the `production` environment secrets (`docs/DEPLOYMENT.md`) |
| Billing provider | `BLOCKED_EXTERNAL` | an owner decision on a provider and an account with it |
| Prices | owner decision | plans carry no prices by design |
| Email / WhatsApp delivery (invitations, notices) | `AWAITING_CREDENTIALS` | a sender identity and provider credentials |
