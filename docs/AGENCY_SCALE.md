# Agency scale — an audit against the integration constitution

The constitution asks one question of every part of this product: *does it still work when Smart Code is
running 100+ projects, 300+ external accounts and thousands of campaigns at once?* This document answers it
rule by rule, and it is written to be read by somebody who will check it.

## How to read a verdict

| Verdict | Meaning |
|---|---|
| **MET** | The rule is satisfied, and the file that satisfies it is named. |
| **PARTIAL** | Something real exists and falls short in a stated way. |
| **GAP** | Nothing in the codebase addresses it. |
| **NOT ASSESSED** | Not examined in this pass. Not a claim that it is missing. |

**No rule is marked MET from a reasonable assumption.** Where the evidence column is empty, the verdict is
NOT ASSESSED, however likely the answer seems. An audit that guesses is worse than a shorter one that does
not, because the guesses are indistinguishable from the findings.

## Verdict

| Question | Answer |
|---|---|
| AGENCY SCALE READY | **NO** — see §21, §22, §27, §103 |
| MULTI-CLIENT ISOLATION | **VERIFIED** — enforced in schema and in the query layer; see §5 |
| MULTI-ACCOUNT | **PARTIAL** — many accounts per connection, but one connection per provider per workspace; see §16 |
| CENTRAL CAMPAIGN CONTROL | **NOT VERIFIED** — no such surface exists; see §10 |
| INTEGRATION READY | **YES** for the providers listed in `docs/LIVE_COMMISSIONING.md` |
| SCALE TEST | **FAIL** — no scale fixture and no query budget exist to test against; see §103 |

The headline: **the tenancy model is right and the operating surfaces on top of it are not there yet.** The
hard part — the part that would have been a rewrite — is already built correctly. What is missing is mostly
additive.

---

## 1–9 · Tenancy and isolation

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 1 | Agency scale is the default | **MET** | Every table carries `workspace_id`; nothing in the schema is singular by construction. |
| 2 | Workspace → Client → Project → Binding → ExternalAccount → Connection → Provider | **MET** | `provider_connections`, `external_accounts`, `project_integration_bindings` migrations. |
| 3 | Account discovery separate from binding | **MET** | `POST integrations/providers/{provider}/discover` writes `external_accounts`; binding is a separate route under the project. |
| 4 | Connection ≠ account ≠ project | **MET** | A discovered account is operational only once a `project_integration_bindings` row exists. The migration says so in its own comment. |
| 5 | Project isolation on every read | **MET** | `VisibilityResolver::visibleProjectIds()` is taken in the Application layer and passed into every repository call; see `GetTaskBoard`. Isolation is covered by tests across portal, leads, measurement, commerce, project and operations. |
| 6 | One client, many accounts on one platform | **MET** | No uniqueness ties an account to a client; `external_accounts` is unique per `(connection, external_id)` only. |
| 7 | One account, many projects, by explicit binding | **MET** | `project_integration_bindings` is unique on `(project_id, external_account_id)`, so the same account binds to several projects. |
| 8 | Campaign ownership by provider-native id, with explicit project mapping where needed | **PARTIAL** | Campaigns key on `(external_account_id, external_id)` — native ids, never names. **But there is no campaign→project mapping.** A campaign's project is derived from its account's bindings, so an account bound to three projects (which §7 requires) leaves every one of its campaigns ambiguous. |
| 9 | Unassigned data is kept and reviewable | **PARTIAL** | Discovered accounts persist unbound, and nothing is discarded. There is no *surface* that lists unassigned accounts or campaigns for review, so in practice they are invisible rather than reviewable. |

## 10–12 · Campaign control

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 10 | Campaign Control Center | **GAP** | `CampaignsPage` is a metric drill-down (provider → campaign → ad group → ad). It has no client, project, account, owner, health, pacing or last-sync column, and no filters on any of them. It answers "what is dragging CTR down", not "which of our 2,000 campaigns needs me". |
| 11 | Normalized business campaign health, separate from provider status | **GAP** | Provider status and `raw_effective_status` are both stored and shown. No business health state is computed for a campaign. The vocabulary now exists (`shared/ui/health.model.ts`) and nothing produces it. |
| 12 | Agency-wide attention queue | **GAP** | Per-module attention exists — budget alerts, tracking issues, approval ages, request overdue, task overdue — and no surface unions them. |

## 13–15 · Portfolio and workspace

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 13 | Agency Portfolio with drill-down | **GAP** | The overview is a four-figure scorecard plus a platform list. |
| 14 | Client overview with its projects | **GAP** | `ClientsPage` lists clients as cards with status, industry and project count. Deliberately no money — that decision stands for a *list*, but there is no client surface that does show it. |
| 15 | Project workspace with all its tabs | **PARTIAL** | Projects exist with members and bindings. Overview/Campaigns/Accounts/Budget/Tracking/Leads/Creative/Tasks/Approvals/Reports/Activity are separate global pages filtered by project, not one project workspace. |

## 16–19 · Connections and accounts

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 16 | Connection ownership (agency vs client) | **PARTIAL** — was GAP | `ConnectionOwner` (agency \| client) is on the aggregate and the table, and two partial unique indexes replace the old `unique(workspace_id, provider)`: one agency connection per platform, one per client per platform. A workspace can now hold the agency MCC and a client's own Google connection at once. **Not yet exposed:** nothing in the API creates a client-owned connection, so none can exist until the connection-level endpoints land. |
| 17 | One connection serving many accounts | **MET** | `external_accounts.provider_connection_id` is many-to-one; one MCC connection can carry 80 accounts. |
| 18 | Connection health, business-facing, technical under Advanced | **PARTIAL** | `status`, `expires_at`, `last_checked_at`, `status_message` are stored, and `provider_errors` normalises failures by category. Counts of accounts discovered vs bound are not published on the connection, and there is no business-facing Healthy / Needs Reconnect / Sync Issue / Awaiting Access vocabulary. |
| 19 | Account health distinct from connection health | **MET** | `external_accounts.status` is its own column with its own check constraint (`active`, `disabled`, `closed`, `unsettled`, `unknown`), and provider-native status is preserved alongside. |

## 20–30 · Sync architecture

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 20 | Granular, independently retryable jobs | **MET** | `SyncBoundAccountJob` is per account, `tries = 3`, backoff `[60, 300, 900]`. |
| 21 | Separate queue categories | **PARTIAL** | Two queues exist in practice: `sync` (advertising and commerce) and the default. Metrics, tracking, webhooks, reports, notifications and AI all share them. |
| 22 | Adaptive sync frequency | **GAP** | `advertising:sync` runs hourly and dispatches **every** bound, sync-enabled account with no regard to whether it is spending. A dormant account costs the same rate limit as a live one. |
| 23 | Incremental sync | **MET** | `sync_cursors` holds an opaque `cursor` for structure and `synced_through` for time series, unique per `(external_account_id, kind)`. |
| 24 | Attribution lookback safety | **MET** | `sync_cursors.resync_from` exists for exactly this, and its migration comment names the reason: conversions keep landing against past dates. |
| 25 | Idempotency | **MET** | Native-id uniqueness on `campaigns`, `external_accounts`; `SyncBoundAccountJob` is `ShouldBeUnique` per `(workspace, account)`. |
| 26 | Rate-limit awareness | **PARTIAL** | Per-job backoff and a `rate_limit` error category exist, and `provider_errors.retry_after_seconds` is captured. There is **no per-provider or per-account throttle**: 300 accounts dispatched in one tick are limited only by worker count. |
| 27 | Fair scheduling | **GAP** | Dispatch is a flat loop over `syncTargets()`. One client with 50 accounts occupies the head of the queue; everybody else waits behind it. |
| 28 | Failure isolation | **MET** | One job per account; a provider failure is caught, journalled and logged without re-throwing on partials. |
| 29 | Sync history | **MET** | `sync_runs` records provider, connection, account, kind, status, range, counts, api calls, duration, error code and retryability. |
| 30 | Central sync monitor | **GAP** | The data for it is all in `sync_runs` / `provider_errors`; no administration surface reads it. |

## 31–35 · Data honesty at scale

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 31 | Freshness on every major metric | **MET** | `MetricSourceValue` carries LIVE / SYNCED / STALE / PARTIAL / SANDBOX / MANUAL / ESTIMATED / ATTRIBUTED through to the UI, and `SourceNotice` renders it. |
| 32 | Metric normalization without forcing zeros | **MET** | `MetricValue::of(..., null, ...)` is the unavailable case and is rendered as `—`. Nothing coerces missing to zero. |
| 33 | Objective-aware primary result | **GAP** | Campaigns store a normalised `objective`, and no surface selects a primary metric from it. ROAS is shown for campaigns that measure no revenue. |
| 34 | Currency | **MET** | `EloquentAnalyticsReadModel` aggregates `string_agg(distinct currency)` and **refuses to total** when more than one currency is present, with the reason in the payload. No FX conversion exists — the constitution calls that optional. |
| 35 | Timezone | **PARTIAL** | `external_accounts.timezone` is stored. No read path yet refuses to combine daily metrics across incompatible timezones the way the currency path refuses to sum. |

## 36–38 · Budget governance

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 36 | Budget hierarchy with approved/allocated/spent/remaining/forecast/variance | **MET** | `budgets` tables plus `PacingFigures` carry every one of those figures, including tolerance published by the server. |
| 37 | Budget Control Center | **PARTIAL** | `GET budgets/alerts` returns pacing for everything needing attention across the workspace. The calculator page is per budget; there is no central client/project table with drill-down. |
| 38 | Global budget alerts | **MET** | `useBudgetAlertsQuery` is workspace-wide; finance does not open each project. |

## 39–43 · Analytics, search, saved views

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 39 | One analytics engine across every level | **MET** | A single read model serves provider / campaign / ad group / ad dimensions with the same metric vocabulary. |
| 40 | Agency dashboard | **GAP** | See §13. |
| 41 | Cross-client benchmarking | **PARTIAL** | Provider benchmarks with explicit confidence exist in the budget module. No industry or cross-client distribution. |
| 42 | Global search | **GAP** | Nothing matched a search for a global search surface. |
| 43 | Saved views | **PARTIAL** | A `saved_views` table exists. Private/team/workspace scoping not assessed. |

## 44–47 · People and access

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 44 | Accountable ownership through the member directory | **MET** | `OwnerDirectory` is the single source for who may hold work, and assignment refuses users without access. |
| 45 | Many employees, role × project scope | **MET** | Role permissions and `assignedProjectIds()` combine in `ActorContext`. |
| 46 | RBAC beyond role name | **MET** | `PermissionSet` + project scope, evaluated in the Application layer, never in a controller. |
| 47 | Client users see only their own | **MET** | `portal.read` is the only permission that says "this person belongs to the client"; `ClientPortalTest` covers the isolation. |

## 48–51 · Writes and bulk

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 48 | Centralized campaign operations, explicit per provider | **MET** | `ProviderCapability` enumerates `CampaignStatusMutation`, `BudgetMutation` and the rest; the UI hides what an adapter does not declare. |
| 49 | Safe write actions | **MET** | Permission + capability + approval + fingerprint + audit, with `provider_writes.execute` separate from `approvals.decide`. |
| 50 | Bulk actions | **GAP** | No bulk selection anywhere. |
| 51 | Internal tagging | **GAP** | No tag table. |

## 52–57 · Lifecycle, onboarding, readiness

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 52 | Project lifecycle | **PARTIAL** | `planning, active, paused, completed, archived`. No Onboarding or Closing. History is retained on archive. |
| 53 | Client lifecycle | **PARTIAL** | `prospect, active, paused, archived`. No Offboarded. Archiving destroys nothing. |
| 54 | Account offboarding preserves history | **MET** | Binding carries `sync_enabled`, and its migration states that pausing is not unbinding. No cascade deletes historical metrics. |
| 55 | Guided project onboarding | **GAP** | Each step exists as its own screen; nothing sequences them. |
| 56 | Project readiness check | **GAP** | Readiness exists only for GTM containers (`ContainerReadiness`). |
| 57 | Campaign readiness | **GAP** | — |

## 58–60 · Reporting

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 58 | Central reporting on normalized analytics | **NOT ASSESSED** | — |
| 59 | Scheduled reports | **GAP** | No schedule column matched in the reporting migrations. |
| 60 | Template-driven presentations | **NOT ASSESSED** | — |

## 61–65 · Cross-entity surfaces

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 61 | Leads across projects | **NOT ASSESSED** | The Lead Center exists; its filter set was not examined in this pass. |
| 62 | Commerce across stores | **MET** | Stores are connected per client through `ConnectStore`, listed workspace-wide, and bound explicitly. |
| 63 | GA4 at scale | **MET** | Property discovery is separate from binding, and `DiscoveredProperty.is_aggregate` exists so a roll-up is not double-counted. |
| 64 | GTM at scale | **MET** | Containers discovered then registered per source. |
| 65 | Agency tracking health | **PARTIAL** | `GET measurement/health` is workspace-wide with per-issue severity. There is no "92 healthy / 5 warning / 3 critical" roll-up with click-through. |

## 66–69 · Integrations UX and audit

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 66 | Four levels: catalog → connections → accounts → bindings | **PARTIAL** | Catalog and connections are one page (`IntegrationsPage` + `ProviderCard`); accounts and bindings are `AccountBindingPanel` under the project. The levels exist but are not presented as levels. |
| 67 | Human-readable connection labels | **PARTIAL** | `provider_connections.name` exists and is now load-bearing, since §16 allows several per platform. No surface yet presents the owner beside the label. |
| 68 | Audit of every meaningful action | **MET** | `audit_logs` with a dedicated controller and tests. |
| 69 | Simplified activity for business users | **GAP** | Audit is the only activity surface; there is no business-facing feed. |

## 70–74 · Performance

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 70 | Never load all agency entities | **PARTIAL** | Clients, projects, members and audit paginate. Campaign and metric reads aggregate in SQL. The task board caps at 50 per column and publishes `is_truncated`. Not every list was examined. |
| 71 | No N+1 in agency views | **PARTIAL** | The analytics and budget read models aggregate in one statement each. No agency-level view exists yet to violate this — which is why §103 matters before §13 is built. |
| 72 | Materialized / aggregate views | **GAP** | Every figure is computed from `daily_metrics` on request. Defensible today; it is the first thing to break at 2,000 campaigns. |
| 73 | Tenancy-aware cache | **NOT ASSESSED** | — |
| 74 | No hard-coded limits | **MET** | No max-client, max-project or max-account constant exists. |

## 75–80 · Provider contracts and backfill

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 75 | Provider capability registry | **MET** | `App\Domain\Integration\ProviderCapability` — 20 capabilities, consumed by both the use cases and the UI. |
| 76 | Internal UUIDs, provider ids stored separately | **MET** | UUIDv7 primary keys everywhere; `external_id` is a separate column throughout. |
| 77 | Raw metadata in JSONB only where justified | **MET** | `metadata jsonb` on accounts and campaigns, with the comment "never filtered or aggregated"; business fields are normalised columns. |
| 78 | Deletion safety | **MET** | Soft deletes on connections; bindings carry `sync_enabled`; no cascade reaches `daily_metrics`. |
| 79 | Controlled backfill window | **PARTIAL** | `advertising:sync --since --until` allows a window. Nothing offers 30/90/custom at connection time, and nothing prevents a wide first read across many accounts. |
| 80 | Backfill at lower priority than live sync | **GAP** | Backfill and live sync are the same job on the same queue. |

## 81–83 · Alerting

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 81 | Agency-wide alerting routed to owners | **PARTIAL** | Notifications are permission-routed and the vocabulary is published by the server. There is no alert engine that evaluates conditions across projects. |
| 82 | No notification fatigue | **PARTIAL** | A digest with waiting/unread/total exists. Nothing aggregates "8 campaigns need attention" into one notification. |
| 83 | Digests | **GAP** | No scheduled digest. |

## 84–87 · Context and navigation at scale

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 84 | Global client/project context | **GAP** | Each page keeps its own filter. |
| 85 | Fast searchable context switch in the topbar | **GAP** | — |
| 86 | Pinning | **GAP** | — |
| 87 | Recently viewed | **GAP** | — |

## 88–92 · Role experience

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 88 | Role dashboards | **GAP** | One overview for everybody; it varies only by permission. |
| 89 | PM sees campaign health without provider internals | **GAP** | Depends on §11. |
| 90 | Media buyer sees operational detail for assigned projects | **PARTIAL** | Project scope works; there is no media-buyer surface. |
| 91 | Finance analyses spend without mutation rights | **MET** | `budgets.view` / `budgets.manage` / `budgets.approve` are three permissions. |
| 92 | Human label, provider, native id secondary | **PARTIAL** | Accounts carry `name`; whether every surface leads with it was not assessed. |

## 93–99 · Operational correctness

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 93 | Manual sync without spam | **MET** | `ShouldBeUnique` per account is the lock. |
| 94 | Context-aware stale detection | **PARTIAL** | Measurement has a real freshness model (`live`, `synced`, `stale`, `silent`, `never_synced`) including the silent case. Advertising accounts have no equivalent cadence expectation. |
| 95 | Write concurrency | **MET** | Approval `fingerprint` with a 409 on mismatch. |
| 96 | Write reconciliation | **PARTIAL** | Commerce reconciles by scheduled re-read. Whether every advertising write re-reads the provider was not assessed. |
| 97 | Provider outage shows stale data with a timestamp | **MET** | Freshness labels plus `last_synced_at`; the UI never blanks on a failed refresh. |
| 98 | Partial provider failure | **MET** | `sync_runs.status = 'partial'`, `SyncOutcome.failedKind`, and the job deliberately does not re-throw on a partial. |
| 99 | Data quality as a first-class concern | **PARTIAL** | Tracking issues cover gaps and mappings. Missing currency, missing timezone, unassigned accounts and unknown campaign ownership are not detected. |

## 100–120 · The standard

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 100 | One place answering the fifteen questions | **GAP** | Each question is answerable on its own page; none are answerable together. |
| 101 | One system, not seven dashboards | **PARTIAL** | Follows from §10, §13, §100. |
| 102 | Agency-centric, providers as adapters | **MET** | `ProviderAdapter` is an Application port; the domain has no provider-shaped concepts. Architecture tests enforce the direction. |
| 103 | Scale acceptance test | **GAP** | No scale fixture. `DemoWorkspaceSeeder` is a small coherent demo and is explicitly not performance evidence. |
| 104 | Query/time budgets | **GAP** | Nothing measures query counts per view. |
| 105 | `AgencyScaleSeeder` | **GAP** | — |
| 106 | UX at 100 projects | **PARTIAL** | Pickers were not individually assessed; no autocomplete/recent/favourites pattern exists to assess them against. |
| 107 | Server-side, persistable filters | **PARTIAL** | Filters are server-side parameters. URL persistence not assessed. |
| 108 | Queued large exports | **NOT ASSESSED** | — |
| 109 | Queued report generation | **NOT ASSESSED** | — |
| 110 | Background work | **MET** | Sync, store reads and webhooks are queued jobs. |
| 111 | Observability per provider | **PARTIAL** | `provider_errors` groups by provider and category. No latency or queue-depth measure. |
| 112 | A bad connection identifiable without logs | **PARTIAL** | `status_message` and `provider_errors` hold it; no surface presents it. |
| 113 | Progressive disclosure for operators | **MET** | The commissioning readout and the notification vocabulary both keep slugs and provider codes out of business UX. |
| 114 | No technical details in business UX | **MET** | Enforced by habit and by test — permission slugs were deliberately removed from the notification panel. |
| 115 | Integration-ready definition | **MET** | `docs/LIVE_COMMISSIONING.md` holds exactly this ledger, and it does not require credentials. |
| 116 | Live-verified definition | **MET** | `CommissioningReadout::level()` cannot return a live level from repository fixtures, and a test asserts it. |
| 117 | The five acceptable live states | **MET** | Same document. |
| 118 | Never invent a provider contract | **MET** | Capability registry plus per-adapter fixtures; no simulated write path exists. |
| 119 | Data honesty | **MET** | The oldest rule in this codebase. Unknown is `null`, mixed currency refuses to total, sandbox cannot report live, a click is not a lead, and `silent` is distinct from `stale`. |
| 120 | Agency control plane | **NO, not yet** | The foundation is right. The control plane on top of it is the work below. |

---

## What this sets as the work

In the constitution's own priority order, with the audit's finding attached:

1. **Tenancy** — ~~connections need an owner and must stop being unique per provider (§16)~~ **done**; campaigns still need an explicit project mapping for the multi-project case §7 already allows (§8).
2. **Discovery and binding** — a surface for unassigned accounts and campaigns (§9), and the four levels presented as levels (§66).
3. **Campaign Control Center** (§10) with normalized campaign health (§11) and objective-aware primary results (§33).
4. **Portfolio** (§13, §14) and the project workspace (§15).
5. **Attention Center** (§12) — server-side, because at agency scale it is an aggregate, not a fan-out.
6. **Budget Control Center** (§37).
7. **Sync scalability** — queue categories (§21), adaptive cadence (§22), per-provider throttle (§26), fair scheduling (§27), backfill priority (§80).
8. **Role dashboards** (§88).
9. **Global context, search, pinning, recents** (§42, §84–87).
10. **Scale testing** — `AgencyScaleSeeder` (§105), query budgets (§104), the acceptance test (§103).

**§103 comes before §13 in practice.** Building an agency-level view without a dataset that can show it
failing is how a dashboard ships that nobody discovers is slow until the agency grows into it.
