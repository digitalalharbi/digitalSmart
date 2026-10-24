# digitalSmart — SaaS, Tenant Isolation & Campaign Launch Master Program

> **Purpose**
> This file is the authoritative execution brief for evolving `digitalSmart` into a production-ready multi-tenant SaaS Marketing Operating System while preserving the existing agency workflow and adding safe, provider-capability-driven campaign creation and launch from inside the product.

---

## How Claude Code must use this file

This document is an **execution contract**, not a one-time prompt.

At the beginning of every Claude Code session related to this program:

1. Fetch fresh `origin/main`.
2. Read:
   - `CLAUDE.md`
   - `docs/RESUME_STATE.md`
   - `docs/SAAS_CAMPAIGN_MASTER.md`
   - the current PR / branch status
3. Determine the **next unfinished bounded PR** from the delivery chain below.
4. Continue only that bounded PR.
5. Run its tests.
6. Push.
7. Open/update its PR.
8. Watch CI.
9. Fix only real failures on the current head.
10. Merge only when required checks are green.
11. Fetch fresh `origin/main`.
12. Update the progress section of this file or `docs/RESUME_STATE.md`.
13. Continue to the next bounded PR automatically.

Do **not** reinterpret the whole program from scratch on every session.

Do **not** reopen architecture already completed unless current code proves it is defective.

Do **not** stop at:
- “waiting”
- “CI running”
- “watcher running”
- “PR created”
- “checks pending”

Act on the result and continue.

---

## Session bootstrap command

Use this at the start of every Claude Code session:

```text
RESUME DIGITALSMART MASTER PROGRAM

Start from current Git state only.

1. Run:
   git fetch origin
   git log --oneline -10 origin/main
   git status

2. Read, in this order:
   - CLAUDE.md
   - docs/RESUME_STATE.md
   - docs/SAAS_CAMPAIGN_MASTER.md

3. Treat origin/main as source of truth.
4. Determine which PR in the A→H chain is the next unfinished bounded slice.
5. Continue only that slice.
6. Do not restart the whole program.
7. Do not repeat completed work.
8. If a prior PR is open, finish/fix/merge it first.
9. If CI is running, do not stop at “waiting”; act when it completes.
10. After merge, fetch fresh main and continue automatically.

Stop only for:
- real external credentials,
- provider approval,
- legal/business decision,
- destructive production action.

Otherwise continue autonomously.
```

---

# MASTER P0 PROGRAM — DIGITALSMART SAAS + TENANT ISOLATION + CAMPAIGN LAUNCH

## Mission

Evolve digitalSmart from an internal/private agency operating system into a production-ready multi-tenant SaaS Marketing Operating System while fully preserving the existing agency workflow.

The product must support:

1. Smart Code operating all clients/projects from one agency workspace.
2. Independent agencies buying digitalSmart as SaaS.
3. Brands/companies using digitalSmart for their own marketing.
4. Freelancers/small teams.
5. Client portal users.
6. Multiple users with different permissions/features.
7. One person belonging to multiple workspaces.
8. Strong tenant/client/project isolation.
9. SaaS plans, seats, quotas and feature entitlements.
10. Direct campaign creation, review, approval and launch from digitalSmart where provider APIs officially support it.
11. Safe separation between campaign creation and campaign activation.
12. Full auditability and provider capability honesty.

Do NOT replace the current architecture.

The existing hierarchy remains:

```text
Workspace
→ Client
→ Project
```

Workspace becomes the SaaS tenant and subscription boundary.

The existing provider isolation remains:

```text
Workspace
→ ProviderConnection
→ ExternalAccount
→ ProjectIntegrationBinding
```

Do not create a competing architecture.

---

# 0. Starting rules

Start only from fresh `origin/main`.

Run:

```bash
git fetch origin
git log --oneline -10 origin/main
git status
```

Read first:

- `CLAUDE.md`
- `docs/RESUME_STATE.md`
- `docs/ARCHITECTURE.md`
- `docs/SECURITY.md`
- `docs/DATA_MODEL.md`
- `docs/PROVIDER_READINESS.md`
- `docs/PROVIDER_WRITES.md`
- `docs/PORTAL.md`
- `docs/TRACKING_FUNNELS.md`
- `docs/PRODUCTION.md`
- `CHANGELOG.md`
- `docs/SAAS_CAMPAIGN_MASTER.md`

Code and `origin/main` are authoritative.

If another valid feature PR/session is currently in progress:

- DO NOT mix this program into it.
- Finish that bounded feature.
- Get CI green.
- Merge it.
- Fetch fresh main.
- Then continue this program.

Create separate branches/worktrees per bounded PR.

Never work directly on `main`.

---

# 1. Non-negotiable product model

Formalize:

```text
Workspace = SaaS tenant
Client = workspace-owned customer/entity
Project = operational scope inside a client
```

A user identity may belong to multiple workspaces.

A Client is NOT a tenant.
A Project is NOT a tenant.

Do not create separate user records simply to represent another workspace.

The same architecture must support:

- AGENCY
- BRAND
- FREELANCER
- INTERNAL_TEAM

Workspace type may change default navigation, terminology, onboarding and recommended settings, but must NEVER weaken authorization or tenant isolation.

---

# 2. Delivery strategy

Do NOT implement this as one giant PR.

Use this bounded chain:

| PR | Scope |
|---|---|
| A | Tenant Isolation Audit + hardening + executable matrix |
| B | Memberships + multi-workspace identity + Workspace Switcher |
| C | Plans + Features + Entitlements + Quotas + Seats |
| D | Subscriptions + SaaS Control Plane + suspension states + overrides |
| E | Campaign Blueprint + campaign builder + provider capability registry |
| F | Provider translation + preflight + launch plan |
| G | Approval + queued provider creation + idempotency + read-back |
| H | Activation + SaaS gating + agency integration + final hardening |

For each:

```text
fresh origin/main
→ branch
→ implementation
→ tests
→ PR
→ CI
→ fix real failures
→ merge
→ main verification
→ next PR
```

Do not stop at “waiting”.

Act on CI results automatically.

Stop only for:
- external credentials
- provider approvals
- legal/business decisions
- destructive production actions

---

# PR A — Tenant Isolation Audit

Audit ALL business domains.

At minimum:

- Workspace
- Membership
- Users
- Clients
- Projects
- ProviderConnection
- ExternalAccount
- ProjectIntegrationBinding
- Campaigns
- Ad Groups
- Ads
- Creatives
- Budgets
- Allocations
- Tracking Links
- Forms
- Submissions
- Funnels
- Tracking Events
- Provider Dispatch
- Leads
- Lead Events
- Lead Automation
- Commerce
- Orders
- Customers
- CRM
- Journeys
- Attribution
- Tasks
- Approvals
- Client Requests
- Reports
- Shared Reports
- Snapshots
- Exports
- Presentations
- AI
- Notifications
- Agents
- Automation
- Saved Views
- Dashboards
- Files
- Attachments
- Access Requests
- Provider Writes

## Executable tenant matrix

For every relevant tenant-owned resource verify:

Workspace A CANNOT:
- list
- read
- search
- filter
- reference by known UUID
- update
- delete
- archive
- attach
- assign
- export
- share
- generate a report
- generate a presentation
- create a task against
- link into
- mutate provider state through
- read through analytics
- read through AI
- read through agents
- receive notifications about

a Workspace B resource.

Cross-tenant access should normally return `404` rather than confirming existence.

Authorization must be server-side.

Frontend hiding is never sufficient.

## Tenant ownership

Audit `workspace_id` on all business entities.

Do not blindly add `workspace_id` everywhere.

Where tenant ownership is transitively safe and enforced by foreign keys, document why.

Where scoping is performance/security critical, denormalize `workspace_id` deliberately.

Every unique constraint involving an externally meaningful identity must include the correct tenant/provider boundary.

Audit examples:
- external accounts
- campaign external IDs
- tracking resources
- forms
- report names
- saved views
- provider mappings

## Cross-tenant references

Database/application must refuse:
- Project A linking ExternalAccount B
- Tracking Link A binding Campaign B
- Lead A assigned User without access
- Report A including Project B
- File A attached to Client B
- Campaign Blueprint A referencing Creative B
- Provider write A executing against Account B

Do not rely on frontend selectors.

## Queue / Job isolation

Every tenant-aware queued job must carry enough immutable scope to prevent:
- job executed after user switches workspace
- job reading another tenant
- stale project/account binding
- provider call against wrong account

Jobs must revalidate tenant-resource relationships before dangerous external work.

## File isolation

Use workspace-scoped storage keys:

```text
workspaces/{workspace_id}/...
```

Do not expose permanent public URLs to tenant-private files.

Downloads must go through authorization or deliberately scoped signed access.

Audit creative assets, attachments, exports, presentations, report files and brand assets.

## PostgreSQL RLS ADR

Evaluate PostgreSQL Row Level Security.

Do NOT enable RLS globally without analysis.

Produce an ADR covering:
- benefits
- risks
- Laravel connection/session context
- queue workers
- Horizon
- CLI
- migrations
- platform administration
- support/impersonation
- exports
- provider jobs
- tests
- performance
- rollout strategy

Preferred defense:

```text
Application permission
+
workspace-scoped query
+
database constraint
```

RLS may become a fourth defense only if safe.

---

# PR B — Multi-workspace identity

Formalize workspace membership.

A user identity can belong to multiple workspaces.

Do not create duplicate accounts per workspace.

Membership must include:
- workspace_id
- user_id
- status
- role assignment
- joined_at
- invited_by where relevant

Statuses:
- INVITED
- ACTIVE
- SUSPENDED
- REVOKED

## Workspace Switcher

Add first-class Workspace Switcher.

Workspace change must:
- change active tenant context
- invalidate/reload tenant server-state queries
- clear incompatible cached frontend data
- not leak prior tenant data
- not reuse project/client filters from another workspace

Audit switch action.

## Multi-workspace test

Test one identity belonging to:
- Agency Workspace
- Brand Workspace
- Personal/Freelancer Workspace

Ensure:
- same login
- different membership
- different permissions
- different clients
- different provider connections
- different features
- different subscription

No leakage.

---

# PR C — SaaS Plans & Entitlements

Create SaaS Plan domain.

Suggested:
- plans
- plan_features
- plan_limits
- workspace_subscriptions
- workspace_entitlement_overrides
- usage_meters

Billing-provider implementation is NOT required in this PR.

Billing must later attach through an adapter.

## Canonical Feature Catalogue

Create one canonical Feature catalogue.

At minimum:
- overview
- clients
- projects
- campaigns_read
- campaign_launch
- provider_writes
- analytics
- advanced_analytics
- tracking
- tracking_forms
- tracking_funnels
- reports
- client_portal
- creative_intelligence
- creative_operations
- tasks
- approvals
- notifications
- automation
- agents
- ai
- presentations
- commerce
- crm
- attribution
- white_label
- api_access

Never use scattered string literals across the product.

## Permission != Entitlement

Permission answers:
> Is this person allowed?

Entitlement answers:
> Did this workspace purchase/receive this capability?

A user may have `campaign_launch.execute` while the workspace has `campaign_launch = disabled`.

Execution must be refused.

Centralize:

```text
Authenticated User
+
Active Workspace Membership
+
Permission
+
Workspace Entitlement
+
Quota
+
Project Visibility
+
Provider Capability
+
Approval when required
```

## Plan examples

Seed/fixture plans may include:
- STARTER
- GROWTH
- AGENCY
- ENTERPRISE

Do not hard-code final pricing.

Example:

STARTER:
- analytics
- basic tracking
- reports

GROWTH:
- forms
- funnels
- automation
- advanced analytics

AGENCY:
- campaign launch
- client portal
- creative intelligence
- advanced reports
- agency overview

ENTERPRISE:
- advanced access controls
- white label
- API
- custom limits

Keep pricing outside core domain assumptions.

## Quotas

Support measurable limits:
- internal_users
- client_users
- clients
- projects
- provider_connections
- external_accounts
- tracking_events_monthly
- form_submissions_monthly
- automation_runs_monthly
- ai_requests_monthly
- exports_monthly
- storage_bytes
- campaign_launches_monthly if desired

Do not fake usage estimates.

Usage must be derived or recorded idempotently.

## Quota behavior

When exhausted:
- existing data remains safe
- no data corruption
- no silent overage
- write returns explicit structured reason

UI explains limit, current usage and reset period where applicable.

## Seats

Support:
- FULL
- LIMITED
- CLIENT
- GUEST

Seat type is a commercial/product concept, not a replacement for RBAC.

Track included, used and available seats.

## Feature Overrides

Platform admin may override feature/limit for one workspace.

Record:
- feature/limit
- previous value
- new value
- reason
- changed_by
- timestamp

---

# PR D — Subscriptions & SaaS Control Plane

Subscription lifecycle:
- TRIAL
- ACTIVE
- PAST_DUE
- GRACE
- SUSPENDED
- CANCELLED

Define behavior explicitly.

Do not delete tenant data because billing stopped.

## SaaS Control Plane

Create platform-only administration:

```text
/platform
```

Show:
- Workspaces
- Workspace type
- Plan
- Subscription state
- Seats
- Usage
- Feature overrides
- Quotas
- Provisioning status
- Provider connection summary
- System health

## Platform Operator Safety

Platform operator must not silently become tenant Owner.

If support impersonation is implemented:
- explicit reason required
- short-lived
- clear persistent banner
- audit start
- audit end
- tenant/workspace named
- no secret visibility

Do not let impersonation bypass provider-write approval rules.

## SaaS Onboarding

Connect Request Access flow:

```text
Request Access
→ Review
→ Approve
→ Invitation
→ Account
→ Workspace Setup
```

When appropriate:
- create new Workspace
- or invite into existing Workspace

Do not automatically create tenant/account from a public request.

## Workspace creation

Include:
- name
- type
- locale
- timezone
- currency default if applicable
- plan/trial
- owner membership

No provider connection is automatically trusted.

## Agency Mode

Agency must NOT need one Workspace per client.

One agency workspace can own many clients, projects and provider accounts.

## Agency Control Center

Provide:
- Clients
- Projects
- Spend
- Revenue
- ROAS
- Leads
- Qualified Leads
- Appointments
- Sales
- Campaign health
- Tracking health
- Provider health
- SLA
- Open tasks
- Pending approvals
- Needs Attention

Drilldown:

```text
Agency Workspace
→ Client
→ Project
→ Provider
→ Account
→ Campaign
→ Ad Group
→ Ad
```

## Brand / Freelancer Mode

Use same architecture with simpler defaults.

Do not weaken isolation or permissions.

---

# PR E — Campaign Blueprint

Build `Campaign Blueprint`.

This is the provider-neutral source of intent before external creation.

Do not create provider campaigns while editing.

## Blueprint fields

Suggested:
- workspace
- client
- project
- name
- objective
- budget amount
- currency
- start_at
- end_at
- selected providers/accounts
- landing/tracking link
- conversion objective
- targeting intent
- geo targeting
- audience intent
- placement intent
- creative assignments
- UTM/tracking configuration
- status
- created_by
- submitted_by
- approved_by

Blueprint must never contain provider credentials.

## Blueprint states

Use:
- DRAFT
- READY_FOR_REVIEW
- APPROVED
- QUEUED
- CREATING
- PARTIAL
- CREATED_PAUSED
- VERIFIED
- ACTIVATION_PENDING
- ACTIVE
- FAILED
- CANCELLED

Do not misuse APPROVED to mean externally executed.

## Cross-platform Campaign Builder

Visual wizard:

1. Client & Project
2. Platforms & Accounts
3. Objective
4. Budget & Dates
5. Audience
6. Placement
7. Tracking & Conversion
8. Creatives
9. Review
10. Launch Plan

Must support AR RTL, EN LTR, desktop and mobile.

No giant uncontrolled form.

## Tracking Integration

Reuse:
- Tracking Links
- Forms
- Funnels
- Conversion Mapping

User may select an existing Tracking Link or create one during campaign setup, subject to permission and entitlement.

Do not duplicate UTM logic.

## Creatives

Reuse Creative Hub.

Do not create a second creative store.

## Canonical Objectives

Use internal intent:
- AWARENESS
- REACH
- TRAFFIC
- ENGAGEMENT
- VIDEO
- LEADS
- SALES
- APP_INSTALL

Provider translator decides mapping.

Do not force false equivalence.

## Provider Capability Registry

Extend capability keys:
- campaign.create
- campaign.update
- campaign.pause
- campaign.resume
- campaign.activate
- campaign.budget.update
- ad_group.create
- ad_group.update
- ad_group.pause
- ad_group.resume
- ad.create
- ad.update
- ad.pause
- ad.resume
- creative.upload
- targeting.location
- targeting.demographic
- targeting.interest
- targeting.custom_audience
- targeting.lookalike
- placement.manual
- placement.automatic
- conversion.optimize
- validation.preflight
- idempotency.native
- readback

States:
- DOCUMENTED
- UNVERIFIED
- UNSUPPORTED
- BLOCKED_EXTERNAL

UNVERIFIED is not a weaker DOCUMENTED.

## Provider Documentation Audit

Before implementing each creation adapter:
- read current official documentation
- store provider
- capability
- official documentation reference
- API/version
- last_verified_at
- notes/caveats

Audit current support for:
- Meta
- Google Ads
- TikTok
- Snapchat
- LinkedIn
- X
- OpenAI Ads / ChatGPT Ads

Do not claim campaign creation support without current official evidence.

---

# PR F — Provider Translation & Preflight

Create:

```text
Blueprint
→ ProviderCreatePlan
→ Provider-specific adapter payload
```

No provider-specific API fields in Domain Blueprint.

## Provider Create Plan

Each provider plan states:
- campaigns to create
- ad groups/ad sets to create
- ads to create
- creatives to reference/upload
- budget
- currency
- schedule
- objective
- tracking/conversion target
- targeting
- initial delivery state
- warnings
- blocked fields

## Preflight

Verify where applicable:
- Workspace entitlement
- User permission
- Project access
- Provider connection
- External account binding
- Provider create capability
- Ad account authorization
- Billing/account readiness if readable
- Currency compatibility
- Timezone
- Budget rules if documented
- Dates
- Objective
- Conversion event
- Pixel/dataset/tag
- Landing URL
- Tracking Link
- Creative availability
- Creative specification
- Audience/targeting support
- Placement support

States:
- READY
- WARNING
- BLOCKED
- AWAITING_CREDENTIALS
- BLOCKED_EXTERNAL
- UNSUPPORTED

Every non-ready state must explain why and what fixes it.

## Validate without creating

Use provider-native validation/dry-run where officially documented.

Otherwise use deterministic local validation and safe provider validation endpoints where available.

Never create a spending campaign merely to test configuration.

## Launch Plan UI

Before approval show exact planned writes by provider:
- account
- campaign count
- ad group count
- ad count
- budget
- schedule
- objective
- conversion
- landing/tracking
- initial state

Do not add incompatible currencies into one total.

---

# PR G — Approval & Provider Creation

Campaign creation is a provider write affecting client accounts.

Require explicit approval.

## Permissions

Add:
- campaign_blueprints.view
- campaign_blueprints.create
- campaign_blueprints.update
- campaign_blueprints.submit
- campaign_launch.approve
- campaign_launch.execute
- campaign_launch.activate

Do not grant execute/activate automatically to every Media Buyer.

## Approval fingerprint

Approval freezes/fingerprints the approved Blueprint/Create Plan.

If Blueprint changes after approval:
- invalidate approval
- require new approval

## Safe default

Create new external campaign structures as PAUSED or provider-equivalent non-delivering state where supported.

Do not activate by default.

If provider cannot guarantee non-delivery:
- mark risk
- require stronger approval
- do not hide risk

## Queued creation

Flow:

```text
Approved Blueprint
→ CampaignLaunch
→ Provider Execution Record
→ Queue
→ Adapter
→ Provider
→ Result
→ Read-back
→ Audit
```

Never run cross-provider launch synchronously.

## Execution record

Store:
- workspace
- client
- project
- blueprint
- provider
- external account
- attempt number
- creation fingerprint
- idempotency reference
- request_started_at
- request_finished_at
- outcome
- provider IDs
- read-back state
- failure classification

No credentials or sensitive tokens.

## Idempotency

Creation must never duplicate campaigns because of retry.

Use:
- stable internal fingerprints
- native provider idempotency where documented
- execution record
- known provider IDs
- read-before-retry
- provider query where possible

UNKNOWN create outcome must NOT be blindly retried.

## UNKNOWN outcome

Provide recovery workflow:
- Check provider
- Reconcile
- Attach discovered object
- Retry only when non-existence is proven

## Partial launch

Example:

```text
Meta SUCCESS
Google SUCCESS
TikTok FAILED
```

Overall:

```text
PARTIAL
```

Do not automatically delete successful provider campaigns.

## Read-back verification

HTTP 200 != VERIFIED.

After create, read object back where supported.

Verify:
- external ID
- status/effective status
- budget
- currency if available
- schedule
- objective
- hierarchy

Only then mark VERIFIED.

## Internal campaign reconciliation

Do not create a duplicate campaign model.

Reconcile created provider objects with existing:
- Campaign
- AdGroup
- Ad
- Creative

The same structures must feed analytics.

## Audit

Record:
- requested_by
- approved_by
- executed_by
- Workspace
- Client
- Project
- Provider
- External Account
- Blueprint fingerprint
- Provider API/version/reference
- Result
- Provider IDs
- Source state
- Read-back result

Never log secrets.

---

# PR H — Activation & Final Integration

Creation and activation are separate.

## Activation

After `CREATED_PAUSED + VERIFIED` require:
- campaign_launch.activate permission
- workspace entitlement
- provider capability
- project/account binding
- explicit approval
- audit

Then:
- provider activation job
- read-back
- ACTIVE only after provider confirmation

## Activation approval UI

Show:
- Provider
- Account
- Campaigns
- Budget
- Schedule
- Conversion
- Tracking
- Audience summary

No accidental one-click launch.

## Existing provider writes

Integrate:
- pause
- resume
- daily budget

into same capability + permission + approval + audit architecture.

## SaaS gating

Campaign Launch is a Feature Entitlement.

Backend enforces it.

Never rely only on React visibility.

## Agency Campaign Operations

Show:
- Draft Blueprints
- Waiting Approval
- Creating
- Partial
- Created Paused
- Activation Pending
- Active
- Failed

Filter by:
- Client
- Project
- Provider
- Account
- Owner
- Status

## Needs Attention

Evidence-backed issues:
- Provider credentials missing
- Account not bound
- Conversion missing
- Tracking unhealthy
- Creative invalid
- Approval pending
- Creation unknown
- Partial launch
- Read-back mismatch
- Activation failed
- Budget mismatch

---

# SaaS hardening requirements

## White-label readiness

Allow future:
- workspace logo
- workspace name
- report identity
- client portal identity
- email sender identity

Do not deeply rewrite digitalSmart branding now.

## API access readiness

Enterprise API access must be an entitlement.

Do not expose public SaaS API tokens automatically.

## Billing adapter boundary

Do not hard-code Stripe or another provider into core SaaS domain.

Core concepts remain provider-neutral:
- Plan
- Subscription
- Seat
- Entitlement
- Usage

## Production scale target

Test representative scale.

Platform layer where practical:
- 100 Workspaces

Large agency workspace:
- 100 clients
- 200+ projects
- hundreds of external accounts
- thousands of campaigns
- large tracking/event counts

Focus on:
- query scoping
- N+1
- pagination
- indexes
- dashboard aggregation
- usage metering

Do not promise arbitrary unlimited scale.

## Cache isolation

Tenant-dependent cache keys must include workspace scope and where needed client/project scope.

## Realtime isolation

Audit Reverb/Echo authorization.

No sensitive readable payloads on shared channels.

## Search isolation

Global search and command palette scope to active Workspace.

## Saved Views

Remain workspace/user scoped.

## Exports

Re-run authorization at generation time.

## Shared Reports

Shared token can only narrow existing authority, never widen it.

## AI isolation

AI context must be constructed from already-authorized tenant data.

Never trust model behavior as tenant security.

## Platform Admin vs Tenant Admin

Keep separate.

Platform Admin operates SaaS infrastructure/commercial configuration.

Workspace Owner operates their tenant.

Platform Admin should not silently see tenant business details.

## Workspace Admin UX

Provide:
- Members
- Roles
- Seats
- Plan
- Usage
- Features
- Connections
- Audit
- Workspace Settings

## Invitation flow

Invitation specifies:
- Workspace
- Seat type
- Role
- optional project/client grants

If identity exists, create membership, not duplicate user.

## Client users

Remain governed through client portal grants.

Do not give agency permissions because they use a seat.

## Project access

Workspace membership does not necessarily equal all project access.

## Plan changes

Changing Plan must not mutate RBAC roles.

Changing permissions must not mutate Subscription.

Commercial entitlement and security permission remain orthogonal.

## Downgrade safety

Do not delete existing projects, users, reports, campaigns or tracking data.

Use controlled over-limit state.

## Feature disable safety

Preserve historical data.

## Subscription vs provider campaigns

Suspending digitalSmart Workspace must NOT automatically pause external ad campaigns unless an explicit approved policy/action exists.

## Observability

Track:
- tenant failures
- quota refusals
- provider launch failures
- unknown outcomes
- partial launches
- queue failures
- provider rate limits

No secrets or PII in logs.

## Migrations

All new migrations:
- reversible where technically safe
- up → down → up tested
- do not edit deployed migration history
- indexes reviewed
- tenant constraints explicit

## Security audit

Audit:
- IDOR
- mass assignment
- cross-workspace UUID references
- role escalation
- entitlement bypass
- quota race conditions
- seat race conditions
- invitation reuse
- provider account substitution
- campaign launch replay
- idempotency reuse across tenants
- support impersonation

## Concurrency

Use transactions/locking where needed for:
- seat allocation
- quota counters
- round-robin/current pointers
- campaign launch execution claim
- idempotency
- subscription transitions

---

# UX / Browser / Accessibility

Verify:
- 375px Mobile Safari
- 375px Mobile Chromium

For:
- Workspace Switcher
- Plan/Usage
- Members/Seats
- Campaign Builder
- Preflight
- Launch Plan
- Approval
- Campaign launch status

Target:
- Chromium
- Firefox
- WebKit
- Mobile Safari
- Mobile Chromium

Preserve WCAG 2.2 AA.

Campaign builder must support:
- keyboard
- focus
- error summaries
- step navigation
- screen-reader labels
- RTL/LTR

No status communicated by color alone.

---

# Required E2E — SaaS

Test:
- Create Workspace
- Invite existing user
- User belongs to two Workspaces
- Switch Workspace
- Verify data context changes
- Workspace A cannot access Workspace B known UUID
- Plan feature disabled blocks backend
- Feature override enables it
- Seat limit enforced
- Quota enforced
- Subscription suspension enforced
- No data deletion on downgrade

# Required E2E — Agency

Test:
- Create two Clients
- Multiple Projects
- Different External Accounts
- Client A project cannot see Client B unbound account
- Agency owner sees aggregate agency view
- Project member sees only permitted projects
- Client portal user sees only granted modules

# Required E2E — Campaign Builder

Test:
- Create Blueprint
- Choose Project
- Choose Provider Accounts
- Objective
- Budget
- Dates
- Tracking Link
- Conversion
- Creative
- Targeting
- Preflight
- Launch Plan
- Submit
- Approve

# Required E2E — Safe Campaign Create

Against mocks/sandbox fixtures:
- Approval required
- Queue executes
- Provider create called once
- Retry does not duplicate
- Unknown outcome not blindly retried
- Partial launch represented accurately
- Read-back required
- Result created paused
- Internal campaign hierarchy reconciles

# Required E2E — Activation

Created-paused campaign:
- cannot activate without permission
- cannot activate without entitlement
- cannot activate without provider capability
- cannot activate without explicit approval
- activation audited
- read-back confirms ACTIVE

---

# Provider truth

Never label a provider `LIVE_VERIFIED` without:
- real credentials
- real authorized account
- real provider round trip
- result visible in digitalSmart

Mocks = VERIFIED architecture, not LIVE_VERIFIED.

Sandbox = SANDBOX.

Missing credentials = AWAITING_CREDENTIALS.

Provider approval missing = BLOCKED_EXTERNAL.

---

# Data truth rules

Preserve:

```text
missing != zero
click != lead
WhatsApp click != conversation
attempt != contacted
qualified != sale
open opportunity != revenue
campaign metric != ad-group metric != ad metric
platform revenue != store revenue != CRM revenue != attributed revenue
allocation != direct attribution
approval != provider execution
HTTP success != verified provider change
```

---

# Campaign money safety

Any operation that can cause spend requires:
- permission
- entitlement
- project/account authority
- documented provider capability
- approval
- audit
- provider confirmation where possible

AI recommendation alone must never spend client money.

---

# Documentation

Update:
- `docs/ARCHITECTURE.md`
- `docs/SECURITY.md`
- `docs/DATA_MODEL.md`
- `docs/PRODUCT.md`
- `docs/ROADMAP.md`
- `docs/PROVIDER_READINESS.md`
- `docs/PROVIDER_WRITES.md`

Add:
- `docs/SAAS.md`
- `docs/TENANCY.md`
- `docs/ENTITLEMENTS.md`
- `docs/CAMPAIGN_LAUNCH.md`

Update:
- `docs/RESUME_STATE.md`
- `CHANGELOG.md`

`RESUME_STATE.md` must reflect actual merged state and not stale PRs.

---

# Final SaaS Acceptance

Report explicitly:

```text
Workspace is tenant: VERIFIED

Cross-workspace read isolation: VERIFIED
Cross-workspace write isolation: VERIFIED
Cross-workspace reference isolation: VERIFIED
Queue isolation: VERIFIED
Cache isolation: VERIFIED
Realtime isolation: VERIFIED
File isolation: VERIFIED
Search isolation: VERIFIED

Multi-workspace identity: VERIFIED
Workspace Switcher: VERIFIED

Agency mode: VERIFIED
Brand mode: VERIFIED
Freelancer mode: VERIFIED

Plans: VERIFIED
Feature Entitlements: VERIFIED
Limits/Quotas: VERIFIED
Seats: VERIFIED
Overrides: VERIFIED
Subscription lifecycle: VERIFIED

Platform Control Plane: VERIFIED
Tenant Admin: VERIFIED
Access Request → Workspace provisioning path: VERIFIED
```

# Final Campaign Launch Acceptance

```text
Campaign Blueprint: VERIFIED
Campaign Builder: VERIFIED

Tracking integration: VERIFIED
Creative integration: VERIFIED

Provider capability matrix: VERIFIED
Provider documentation provenance: VERIFIED

Preflight: VERIFIED
Launch Plan: VERIFIED

Creation approval: VERIFIED
Queued provider execution: VERIFIED
Creation idempotency: VERIFIED
Unknown-outcome safety: VERIFIED
Partial launch handling: VERIFIED
Read-back verification: VERIFIED

Created-paused default: VERIFIED where provider supports it

Existing Campaign/AdGroup/Ad reconciliation: VERIFIED

Activation separate from creation: VERIFIED
Activation approval: VERIFIED

Pause/Resume/Budget integration: VERIFIED

SaaS Feature gating: VERIFIED

Audit: VERIFIED
```

# Final Quality Acceptance

Backend:
- Pint GREEN
- PHPStan GREEN
- Pest GREEN

Frontend:
- TypeScript GREEN
- ESLint GREEN
- Prettier GREEN
- Vitest GREEN

E2E:
- Critical SaaS flows GREEN
- Critical Campaign Launch flows GREEN

Also:
- RTL VERIFIED
- LTR VERIFIED
- 375px VERIFIED
- Mobile Safari VERIFIED
- Mobile Chromium VERIFIED
- No tenant PII leak VERIFIED
- No provider secret leak VERIFIED
- Main CI GREEN

Open PRs:
- 0 for this program

Open Issues:
- report honestly

---

# External blockers

List separately:
- Production server
- Provider credentials
- Provider developer/app approvals
- Real provider account commissioning
- Billing gateway
- Email/WhatsApp delivery credentials
- Model API credentials
- Physical-device testing if not performed

Use correct states:
- AWAITING_SERVER_ACCESS
- AWAITING_CREDENTIALS
- BLOCKED_EXTERNAL

---

# Autonomous execution

Continue automatically:

```text
PR A
→ PR B
→ PR C
→ PR D
→ PR E
→ PR F
→ PR G
→ PR H
```

Do not stop after:
- PR created
- CI running
- waiting
- watcher running

If CI fails:
1. Inspect CURRENT head.
2. Ignore stale replaced-head failures.
3. Classify real cause.
4. Fix only real cause.
5. Push.
6. Rerun.
7. Merge only when green.

After merge:
1. Fetch fresh `origin/main`.
2. Verify main.
3. Update progress.
4. Continue next bounded PR.

Do not reopen completed architecture unnecessarily.

Do not add unrelated features.

---

# Stop condition

STOP only when:

```text
PR A merged
PR B merged
PR C merged
PR D merged
PR E merged
PR F merged
PR G merged
PR H merged
```

and:

```text
main CI GREEN
```

and:
- final SaaS acceptance written
- final Tenant Isolation acceptance written
- final Campaign Launch acceptance written
- remaining external blockers explicitly listed
- no unsupported/live capability falsely claimed

---

# Progress Tracking

Claude Code must keep this section or `docs/RESUME_STATE.md` current after each merged PR.

| Slice | Status | PR | Main SHA | Notes |
|---|---|---:|---|---|
| A — Tenant Isolation | MERGED | #61 | e0fa0d6 | Matrix + schema guard; 6 findings fixed (one high: cross-tenant project members). `docs/TENANCY.md`, ADR 7. |
| B — Multi-workspace Identity | IN_REVIEW | (this PR) | — | Revoked memberships kept; workspace type; audited switch; active workspace in its own cookie (fixes an in-flight session race); top-bar switcher. `docs/SAAS.md`. |
| C — Plans / Entitlements / Seats | IN_REVIEW | (this PR) | — | Feature + Limit catalogues, 4 plans, `feature:` on 74 routes, locked quota claims, idempotent meters, seats, append-only overrides, `saas:plan`/`saas:override`, Plan & usage page. `docs/ENTITLEMENTS.md`. |
| D — Subscription / Control Plane | NOT_STARTED | — | — | |
| E — Campaign Blueprint | NOT_STARTED | — | — | |
| F — Translation / Preflight | NOT_STARTED | — | — | |
| G — Provider Creation | NOT_STARTED | — | — | |
| H — Activation / Hardening | NOT_STARTED | — | — | |

---

# Short Resume Instruction

When this file already exists in the repository, use only:

```text
Resume the digitalSmart SaaS + Campaign Launch program from current Git state.

Read:
CLAUDE.md
docs/RESUME_STATE.md
docs/SAAS_CAMPAIGN_MASTER.md

Do not restart completed work.
Find the next unfinished PR in the A→H chain.
Finish it completely:
code → tests → PR → CI → fix → merge → verify main.

Then continue automatically to the next PR.

Do not stop at waiting.
Do not claim LIVE_VERIFIED without real provider evidence.
```
