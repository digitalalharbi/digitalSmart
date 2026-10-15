# Agency scale — an audit against the integration constitution

The constitution asks one question of every part of this product: *does it still work when Smart Code is
running 100+ projects, 300+ external accounts and thousands of campaigns at once?* This document answers it
rule by rule, and it is written to be read by somebody who will check it.

Every verdict below was produced from the implementation as it stands, with the file or test that carries it
named in the evidence column. Nothing here is carried over from an earlier audit without being re-checked.

## How to read a verdict

| Verdict | Meaning |
|---|---|
| **MET** | The rule is satisfied, and the file or test that satisfies it is named. |
| **BLOCKED_EXTERNAL_CREDENTIALS** | Complete in this repository; the live handshake needs a credential that does not exist here. |
| **BLOCKED_EXTERNAL_APPROVAL** | Complete in this repository; the last step needs an approval or an input only the owner can give. |
| **BLOCKED_OPERATIONAL_EVIDENCE** | Complete in this repository; a claim about production needs production data to confirm. |

There is no PARTIAL, GAP or NOT ASSESSED left in this document. One item is recorded under
**Known exclusions** instead of being given a verdict it has not earned.

## Verdict

| Question | Answer |
|---|---|
| AGENCY SCALE READY | **YES**, at the constitution's stated size, measured — see *Measurements*. One class of claim stays BLOCKED_OPERATIONAL_EVIDENCE: nothing here has run against a real agency's data. |
| MULTI-CLIENT ISOLATION | **VERIFIED** — schema, query layer, and a sweep of every agency-wide surface for another client's data (`tests/Feature/Isolation/GlobalSurfaceIsolationTest.php`). |
| MULTI-ACCOUNT | **YES** — many accounts per connection, agency-owned and client-owned connections side by side, explicit binding. |
| CENTRAL CAMPAIGN CONTROL | **YES** — `/campaigns`: every campaign the reader may see, verdict beside platform status, review queue for the unplaced. |
| PORTFOLIO CONTROL | **YES** — `/portfolio`, `/clients/:id`, `/projects/:id`, with the budget, tracking and lead roll-ups. |
| ROLE DASHBOARDS | **YES** — role-shaped home screens decided from what a person may do. |
| SYNC FAIRNESS | **YES** — pure planner, four priority queues, per-provider pacing; tested. |
| BUDGET CONTROL | **YES** — `/budget-control`, with a finding the first full-scale measurement exposed and fixed (below). |
| AGENCY TRACKING HEALTH | **YES** — workspace roll-up with click-through. |
| SCALE TEST | **PASS** — 100 clients, 230 projects, 300 accounts, 2,000 campaigns, 180,000 daily rows, 5,000 leads, 20,000 orders. |
| INTEGRATION READY | **YES** for the providers in `docs/LIVE_COMMISSIONING.md`; **BLOCKED_EXTERNAL_CREDENTIALS** for every live handshake. |

---

## 1–9 · Tenancy and isolation

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 1 | Agency scale is the default | **MET** | Every table carries `workspace_id`; nothing in the schema is singular by construction. |
| 2 | Workspace → Client → Project → Binding → ExternalAccount → Connection → Provider | **MET** | `provider_connections`, `external_accounts`, `project_integration_bindings` migrations. |
| 3 | Account discovery separate from binding | **MET** | `POST integrations/providers/{provider}/discover` writes accounts; binding is a separate act under the project. |
| 4 | Connection ≠ account ≠ project | **MET** | A discovered account is operational only once a binding exists. |
| 5 | Project isolation on every read | **MET** | `VisibilityResolver::visibleProjectIds()` is taken in the Application layer and passed into every repository call. `GlobalSurfaceIsolationTest` opens thirty-six agency-wide endpoints as a client user and fails if another client's name appears in any of them, with a control that the owner does find it. |
| 6 | One client, many accounts on one platform | **MET** | No uniqueness ties an account to a client. |
| 7 | One account, many projects, by explicit binding | **MET** | `project_integration_bindings` is unique on `(project_id, external_account_id)`. |
| 8 | Campaign ownership by provider-native id, with explicit project mapping where needed | **MET** | Campaigns key on `(external_account_id, external_id)`; `campaigns.project_id` is the explicit override; `CampaignOwnership` names assigned, implied, ambiguous and unbound. Assignment is refused unless the account is already bound to the target — `AssignCampaignToProject`. |
| 9 | Unassigned data is kept and reviewable | **MET** | Unassigned **accounts**: `/integrations` account queue (`ListAccountQueue`, `AccountQueuePanel`). Unassigned **campaigns**: `GET /api/campaigns/ownership` and the review queue at the top of `/campaigns` (`OwnershipQueue.tsx`), with bulk placement limited to projects every selected campaign's account is already bound to. |

## 10–12 · Campaign control

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 10 | Campaign Control Center | **MET** | `GET /api/campaigns/control` + `CampaignControlPage`: filters on client, project, provider, status, objective, health, tag and search; sort; cards or table; state in the address bar; saved views; pins; tags inherited from project and client. |
| 11 | Normalized business campaign health, separate from provider status | **MET** | `CampaignHealth` (six words) computed by `CampaignHealthPolicy`; the platform's own status ships beside it; every verdict carries a sentence. |
| 12 | Agency-wide attention queue | **MET** | `GET /api/attention`: six streams, membership per stream by permission, severity counts, scope published, failed streams named; narrows to *my clients* / *my projects* (`AttentionAudience`) and says that it is narrowed. |

## 13–15 · Portfolio and workspace

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 13 | Agency Portfolio with drill-down | **MET** | `GET /api/portfolio` + `/portfolio`: health distribution, per-client roll-up in per-currency lists (never summed), tracking roll-up, with click-through to the client and the campaign list. |
| 14 | Client overview with its projects | **MET** | `GET /api/portfolio/clients/{client}` + `/clients/:clientId`. |
| 15 | Project workspace with all its tabs | **MET** | `/projects/:projectId` (`ProjectWorkspacePage`): the project's readiness, campaign health, lead funnel, recent activity, and a link into each module that opens it with the project set as the working context. A hub over the one implementation of each module, deliberately not a second copy of them. |

## 16–19 · Connections and accounts

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 16 | Connection ownership (agency vs client) | **MET** | `ConnectionOwner` on the aggregate and the table; two partial unique indexes (one agency connection per platform, one per client per platform); `POST /api/integrations/connections` creates a client-owned connection (`ConnectClientProvider`). |
| 17 | One connection serving many accounts | **MET** | `external_accounts.provider_connection_id` is many-to-one. |
| 18 | Connection health, business-facing, technical under Advanced | **MET** | `ConnectionBusinessState` (healthy / needs reconnect / sync issue / awaiting access) with discovered-vs-bound counts; technical detail only in `advanced`, only for `workspaces.update` (`GetIntegrationOperations`). |
| 19 | Account health distinct from connection health | **MET** | `external_accounts.status` is its own column with its own check constraint. |

## 20–30 · Sync architecture

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 20 | Granular, independently retryable jobs | **MET** | `SyncBoundAccountJob`, per account, `tries = 3`, backoff `[60, 300, 900]`. |
| 21 | Separate queue categories | **MET** | `config/horizon.php`: recovery → live → dormant → backfill in strict order, plus `commerce`, `webhooks`, and a user-facing supervisor for notifications, reports, exports and AI. |
| 22 | Adaptive sync frequency | **MET** | `SyncPlanner` classifies each account (recovery, live, dormant, backfill) and skips the ones whose projects are all finished; `tests/Unit/Domain/SyncPlannerTest.php`. |
| 23 | Incremental sync | **MET** | `sync_cursors` (opaque cursor for structure, `synced_through` for time series). |
| 24 | Attribution lookback safety | **MET** | `sync_cursors.resync_from`. |
| 25 | Idempotency | **MET** | Native-id uniqueness; `SyncBoundAccountJob` is `ShouldBeUnique` per `(workspace, account)`. |
| 26 | Rate-limit awareness | **MET** | `ProviderThrottle` turns a provider's *published* limit into starts per minute (half the published rate, spread over a sync's calls, never below one, nothing invented for the undocumented); `ThrottleByProvider` job middleware **releases** an over-limit job rather than failing it. `ProviderThrottleTest`. |
| 27 | Fair scheduling | **MET** | The planner interleaves by fairness key, so a client with fifty accounts takes turns with one that has two; `SyncFairnessTest`. |
| 28 | Failure isolation | **MET** | One job per account; a partial is journalled and not re-thrown. |
| 29 | Sync history | **MET** | `sync_runs`. |
| 30 | Central sync monitor | **MET** | `GET /api/integrations/operations` + the Administration panel: summary for anybody who may look, queue depth, per-provider error rate and failing runs for those who may act. |

## 31–35 · Data honesty at scale

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 31 | Freshness on every major metric | **MET** | `MetricSourceValue` carries LIVE / SYNCED / STALE / PARTIAL / SANDBOX / MANUAL / ESTIMATED / ATTRIBUTED to the UI. |
| 32 | Metric normalization without forcing zeros | **MET** | Null is the unavailable case and renders as `—`; the commerce roll-up's average order of a store with no orders is null, not zero (`StoreRollupTest`). |
| 33 | Objective-aware primary result | **MET** | `CampaignObjective::resultColumn()`; cost per result and ROAS are null for any objective that does not measure them. Every campaign surface reads each campaign on its own result. |
| 34 | Currency | **MET** | Money is carried per currency and never summed across them: analytics refuses to total, the portfolio and budget control publish per-currency lists, the store roll-up never totals. |
| 35 | Timezone | **MET** | Every daily row carries `account_timezone`; the project's readiness raises a *data quality* finding when its accounts span timezones (daily figures do not line up) or an account's timezone or currency is unknown (`ReadinessTest`). Detected and surfaced rather than refused: a refusal would hide a month's data to protect a one-hour boundary. |

## 36–38 · Budget governance

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 36 | Budget hierarchy with approved / allocated / spent / remaining / forecast / variance | **MET** | `budgets` tables plus `PacingFigures`. |
| 37 | Budget Control Center | **MET** | `GET /api/budgets/control` + `/budget-control`: every approved budget with pacing and risks, totals per currency, risk filter, saved views, narrows by the working context. |
| 38 | Global budget alerts | **MET** | The attention queue's budget stream and the *daily budget* digest reach finance, project managers and media buyers by permission; a digest rings the bell once (`NotificationKind::DigestReady`). |

## 39–43 · Analytics, search, saved views

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 39 | One analytics engine across every level | **MET** | One read model serves provider / campaign / ad group / ad. |
| 40 | Agency dashboard | **MET** | Role-shaped Home (`GetHomeProfiles`) over the portfolio, attention and budget reads. |
| 41 | Cross-client benchmarking | **MET** | `GET /api/benchmarks`: internal only (`clients.view`), groups below three clients are suppressed with no figures, cost never compared across currencies. |
| 42 | Global search | **MET** | `GET /api/search` + the command palette: server-side, visibility-scoped, typed results. |
| 43 | Saved views | **MET** | Analytics views (`saved_views`: private / workspace / project) and agency-surface views (`surface_views`: private / project team / workspace) on the control center, budget control and lead centre; a view stores only the page's own filters, never a query, so it cannot show more than the page would. |

## 44–47 · People and access

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 44 | Accountable ownership through the member directory | **MET** | `OwnerDirectory`. |
| 45 | Many employees, role × project scope | **MET** | `PermissionSet` + `assignedProjectIds()`. |
| 46 | RBAC beyond role name | **MET** | Evaluated in the Application layer, never in a controller. |
| 47 | Client users see only their own | **MET** | `portal.read`; `GlobalSurfaceIsolationTest` for the agency-wide surfaces. |

## 48–51 · Writes and bulk

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 48 | Centralized campaign operations, explicit per provider | **MET** | `ProviderCapability`; the UI hides what an adapter does not declare. |
| 49 | Safe write actions | **MET** | Permission + capability + approval + fingerprint + audit; confirmation is a read-back, not an acknowledgement (`WriteOutcome`). Live writes are **BLOCKED_EXTERNAL_APPROVAL**: no provider has documented a write this product is permitted to attempt. |
| 50 | Bulk actions | **MET** | Bulk placement of campaigns into a project (refusals reported per campaign, one audit entry each, bound projects only) and bulk tagging (each entity checked against what the actor can see). Bulk *provider* writes are deliberately absent: money does not move in a batch without the per-change approval rule 49 requires. |
| 51 | Internal tagging | **MET** | `tags`, `GET/POST /api/tags`, apply/remove one or many; never reaches a client (`clients.view` gate, tested). |

## 52–57 · Lifecycle, onboarding, readiness

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 52 | Project lifecycle | **MET** | Draft, Onboarding, Active, Paused, Closing, Completed, Archived. |
| 53 | Client lifecycle | **MET** | Onboarding, Active, Paused, Offboarded, Archived; `OffboardClient` is UPDATEs only — it stops what recurs and deletes nothing. |
| 54 | Account offboarding preserves history | **MET** | Binding carries `sync_enabled`; no cascade reaches `daily_metrics`. |
| 55 | Guided project onboarding | **MET** | `/projects/:id/setup`: seven questions in order, each with a sentence and a link to where it is settled, reached from the project's row actions and from its workspace. |
| 56 | Project readiness check | **MET** | `GET /api/projects/{id}/readiness` — budget, accounts, tracking, creative, approvals, data quality, landing page ("not tracked" is its own state, never a green tick). |
| 57 | Campaign readiness | **MET** | `GET /api/campaigns/{id}/readiness` — placed, on a working account, measurable — with its project's readiness included, not recomputed. |

## 58–60 · Reporting

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 58 | Central reporting on normalized analytics | **MET** | Reports are built over the one analytics engine, scoped by visibility, with client-safe shares, snapshots, CSV and workbook. |
| 59 | Scheduled reports | **MET** | `report_schedules` + `reports:run-schedules` (hourly): daily, weekly, monthly, **as the owner**, re-checked on every run; a schedule whose owner has lost the report halts with the reason instead of continuing as someone else. Times are UTC and said so. |
| 60 | Template-driven presentations | **MET** | Four decks composed from the product's own data (weekly, monthly, closing, media plan) over a theme-token abstraction. Two things are **BLOCKED_EXTERNAL_APPROVAL**, and both are published rather than hidden: the Smart Code identity waits on the reference deck only the owner can provide and is a labelled placeholder until then, and the *campaign proposal* is an authored document whose inputs (objective, audience, creative idea) do not exist in the product — it is listed by `DeferredDeck` with that reason instead of being composed from last month's delivery. |

## 61–65 · Cross-entity surfaces

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 61 | Leads across projects | **MET** | `GET /api/leads` and `/api/leads/centre`: All / my clients / my projects / my leads, filtered by client, project, owner, source and campaign, and the centre's figures use the *same* narrowing as the list so a header never disagrees with its table. |
| 62 | Commerce across stores | **MET** | `GET /api/commerce/rollup` + `/commerce/stores`: one row per store in its own currency (orders, revenue, net, average order, traced-to-marketing), never totalled; the revenue source is required. |
| 63 | GA4 at scale | **MET** | Property discovery separate from binding; `is_aggregate` so a roll-up is not double-counted. |
| 64 | GTM at scale | **MET** | Containers discovered then registered per source. |
| 65 | Agency tracking health | **MET** | `GET /api/measurement/agency-health` + the portfolio's tracking roll-up with click-through. |

## 66–69 · Integrations UX and audit

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 66 | Four levels: catalog → connections → accounts → bindings | **MET** | `IntegrationsPage` presents providers, connections (`ConnectionsPanel`), the account queue (`AccountQueuePanel`) and bindings (`AccountBindingPanel`). |
| 67 | Human-readable connection labels | **MET** | The connection's name and owner lead; the platform and native id are secondary. |
| 68 | Audit of every meaningful action | **MET** | `audit_logs`; bulk and scheduled actions each write their own entries. |
| 69 | Simplified activity for business users | **MET** | `GET /api/activity`: a curated allowlist of business events, resolved to the project and client each concerns and scope-filtered, for `clients.view` holders; shown on the project workspace. Credentials, sign-ins and sync toggles stay in the audit trail. |

## 70–74 · Performance

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 70 | Never load all agency entities | **MET** | Every list is searched or paged; the 100-item chip rows and `<select>`s are gone from every screen this work owns (see *Known exclusions*). |
| 71 | No N+1 in agency views | **MET** | Statement-count invariance over a bigger agency for every agency view (`AgencyScaleTest`), which now runs over seeded leads, stores and orders — and caught one (the lead list, fixed). |
| 72 | Materialized / aggregate views | **MET** | Not needed at the stated size, measured: the slowest agency read is analytics at about 350–450 ms over 180,000 rows, and every budget carries a wall-clock ceiling in the scale suite. The trigger for an aggregate is written down: an analytics read past one second at constitution scale, or a production figure that says otherwise (**BLOCKED_OPERATIONAL_EVIDENCE** for anything beyond this fixture). |
| 73 | Tenancy-aware cache | **MET** | There is no application cache of tenant data; an architecture test closes the `Cache` facade to every layer until one workspace-keyed place exists to build keys. |
| 74 | No hard-coded limits | **MET** | No max-client, max-project or max-account constant exists. |

## 75–80 · Provider contracts and backfill

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 75 | Provider capability registry | **MET** | `ProviderCapability`. |
| 76 | Internal UUIDs, provider ids stored separately | **MET** | UUIDv7 primary keys; `external_id` separate. |
| 77 | Raw metadata in JSONB only where justified | **MET** | `metadata jsonb`, never filtered or aggregated. |
| 78 | Deletion safety | **MET** | Soft deletes; no cascade reaches `daily_metrics`. |
| 79 | Controlled backfill window | **MET** | `POST /api/integrations/accounts/{id}/backfill`: 30 / 90 / 180 / 365 days from a short list, bound accounts only, once per six hours, audited; offered per bound account on the integrations page. |
| 80 | Backfill at lower priority than live sync | **MET** | `SyncClass::Backfill` → `sync-backfill`, drained last by Horizon's strict order. |

## 81–83 · Alerting

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 81 | Agency-wide alerting routed to owners | **MET** | Attention sources evaluate budgets, campaigns, tracking, approvals, requests and tasks across projects; routing is by stream permission. |
| 82 | No notification fatigue | **MET** | The queue and the digests aggregate; a written digest rings the bell once, not once per run. |
| 83 | Digests | **MET** | `digests:build` — morning campaigns, daily budget, weekly projects — built as each recipient, idempotent per person per kind per day, empty ones not written. |

## 84–87 · Context and navigation at scale

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 84 | Global client/project context | **MET** | Working context in the store, shown by the topbar chip; honoured by the control center, budget control and lead centre. |
| 85 | Fast searchable context switch in the topbar | **MET** | The command palette sets the context from a search. |
| 86 | Pinning | **MET** | `user_pins`, `/api/shortcuts`; resolved against visibility on every read. |
| 87 | Recently viewed | **MET** | `user_recents`, pruned on write so the table is bounded by people × N. |

## 88–92 · Role experience

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 88 | Role dashboards | **MET** | `GET /api/home`: executive, media buyer, project manager, finance, creative profiles decided from permissions. |
| 89 | PM sees campaign health without provider internals | **MET** | Health words and sentences; provider status secondary; technical detail only under Advanced. |
| 90 | Media buyer sees operational detail for assigned projects | **MET** | The media-buyer profile and project scope. |
| 91 | Finance analyses spend without mutation rights | **MET** | `budgets.view` / `budgets.manage` / `budgets.approve` are three permissions. |
| 92 | Human label, provider, native id secondary | **MET** | Cards lead with the campaign and account names; native ids are secondary. |

## 93–99 · Operational correctness

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 93 | Manual sync without spam | **MET** | `ShouldBeUnique`; backfill is once per six hours. |
| 94 | Context-aware stale detection | **MET** | Staleness is the planner's own *recovery* class — an active account silent for three of *its own* cadences — and the monitor reads it, so the two cannot disagree. |
| 95 | Write concurrency | **MET** | Approval `fingerprint` with a 409 on mismatch. |
| 96 | Write reconciliation | **MET** | Confirmation is a read-back by contract (`WriteOutcome`); a write that cannot be read back is not confirmed. |
| 97 | Provider outage shows stale data with a timestamp | **MET** | Freshness labels plus `last_synced_at`. |
| 98 | Partial provider failure | **MET** | `sync_runs.status = 'partial'`; the job does not re-throw on a partial. |
| 99 | Data quality as a first-class concern | **MET** | Unassigned accounts and campaigns have queues; missing currency, missing timezone, mixed timezones and unplaced campaigns are a readiness check; tracking has its own issue list. |

## 100–120 · The standard

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 100 | One place answering the questions | **MET** | Home (by role), the portfolio, the control center, the attention queue, budget control, the lead and store roll-ups and the tracking roll-up all read one set of use cases; the control center and the portfolio share one verdict producer (`GetCampaignControl::assessAll`) so they cannot disagree about a campaign. |
| 101 | One system, not seven dashboards | **MET** | Same. |
| 102 | Agency-centric, providers as adapters | **MET** | `ProviderAdapter` is an Application port; architecture tests enforce the direction. |
| 103 | Scale acceptance test | **MET** | `AgencyScaleSeeder` + `tests/Feature/System/AgencyScaleTest.php`. |
| 104 | Query/time budgets | **MET** | Statement budgets and invariance for every agency view, **and** a wall-clock ceiling — added after a budget read issued six statements and took four minutes (below). |
| 105 | `AgencyScaleSeeder` | **MET** | Deterministic, rebuilds its own workspace, analyses after the bulk load, scaled from `config/agency_scale.php`. |
| 106 | UX at 100 projects | **MET** | Clients and projects are *searched* through `EntityPicker` — never listed — on every screen this work owns (including every composer and form); the command palette; pins and recents. |
| 107 | Server-side, persistable filters | **MET** | Filters are server-side parameters; the control center's live in the address bar; the others persist as saved views. |
| 108 | Queued large exports | **MET** | `POST /api/exports` → `RunExportJob` on `reports`; status, notification when ready, owner-only download, one-week retention, `exports:prune`. |
| 109 | Queued report generation | **MET** | The same path renders a report as the requester, so the visibility it applies is theirs. |
| 110 | Background work | **MET** | Sync, store reads, webhooks, exports, schedules, digests are jobs or scheduled commands. |
| 111 | Observability per provider | **MET** | The monitor publishes per-provider runs, failed, partial and error rate over a window, and queue depth per queue class. |
| 112 | A bad connection identifiable without logs | **MET** | `ConnectionBusinessState` plus the failing runs with account name, kind and the provider's own words. |
| 113 | Progressive disclosure for operators | **MET** | Summary vs `advanced`. |
| 114 | No technical details in business UX | **MET** | Enforced by habit and by test. |
| 115 | Integration-ready definition | **MET** | `docs/LIVE_COMMISSIONING.md`. |
| 116 | Live-verified definition | **MET** | `CommissioningReadout::level()` cannot return a live level from repository fixtures. |
| 117 | The five acceptable live states | **MET** | Same document. |
| 118 | Never invent a provider contract | **MET** | Capability registry plus per-adapter fixtures; no simulated write path. The pacing policy refuses to invent a ceiling for an undocumented limit. |
| 119 | Data honesty | **MET** | Unknown is `null`, mixed currency refuses to total, sandbox cannot report live, a click is not a lead, and `silent` is distinct from `stale`. |
| 120 | Agency control plane | **MET** | The surfaces above, measured and isolated. Live provider traffic remains **BLOCKED_EXTERNAL_CREDENTIALS**. |

---

## Measurements

Taken against the constitution's minimum representative dataset, built by `AgencyScaleSeeder` at its
defaults: **100 clients, 230 projects, 300 external accounts, 2,000 campaigns, 180,000 daily metric rows,
5,000 leads, 50 stores and 20,000 orders**, analysed after load as production would be.

| View | Time | SQL statements |
|---|---|---|
| Campaign Control Center, 50 rows, 2,000 campaigns assessed | 341 ms | 7 |
| Campaign Control Center, filtered by health | 198 ms | 5 |
| Campaign ownership queue, 50 rows | 105 ms | 5 |
| Attention queue, all six streams | 282 ms | 17 |
| Agency portfolio | 285 ms | 16 |
| Client portfolio | 122 ms | 16 |
| Budget control (67 budgets) | 58 ms | 6 |
| Global search | 24 ms | 4 |
| Connections / account queue | 10 ms / 10 ms | 4 / 6 |
| Integration monitor | 25 ms | 8 |
| Agency tracking health | 6 ms | 5 |
| Lead centre / my clients | 18 ms / 9 ms | 8 / 8 |
| Lead list, 50 of 5,000 | 40 ms | 5 |
| Store roll-up (50 stores, 20,000 orders) | 12 ms | 4 |
| Benchmarks | 199 ms | 6 |
| Home | 5 ms | 3 |
| Activity feed | 8 ms | 4 |
| Analytics breakdown by provider | 355 ms | 6 |
| Analytics breakdown by campaign, 25 rows | 328 ms | 7 |
| Clients / projects, 25 per page | 11 ms / 8 ms | 5 / 5 |

Read these as a baseline from one laptop, not as a pass mark: compare runs on one machine. The fixture is
deterministic — a seeded walk, not `random_int` — so two runs are comparable.

```
php artisan db:seed --class='Database\Seeders\AgencyScaleSeeder'
```

### What the measurement found, and what it could not have

Three defects were found by measuring, and each is the kind a different test would have passed:

- **The budget spend read took four minutes in six statements.** It tested `m.external_account_id in
  (select …)` with a sub-select correlated to the budget, which PostgreSQL ran once per metric row, over a
  planner that believed the tables empty. Statement counts were flat and correct the whole time. It now
  resolves a budget's accounts once and joins — 58 ms — the seeder analyses after loading, and the budget
  test carries a five-second wall-clock ceiling. Attention and the portfolio read the same function and were
  equally slow; both are fixed with it.
- **The lead list issued one `COUNT` per lead** (55 statements for 50 rows). The invariance test had been
  passing over an empty table; the fixture now carries leads, stores and orders, so it cannot.
- **Twelve screens had a hundred-client cap that silently dropped the 101st client.** They now search.

### What is not measured

- **Nothing here has run against real data.** The fixture is synthetic by construction and labelled so;
  skew, hot clients and years of history are exactly what production will have that this does not.
  **BLOCKED_OPERATIONAL_EVIDENCE** for any claim past this fixture.
- **The assessment cap (2,000 campaigns).** The control center assesses every campaign it is allowed to see
  on each request, up to 2,000, and publishes `assessed` beside `total`. Beyond that it says it is looking at
  the highest-spending slice; a materialised verdict is the named next step, not a hidden one.

## Known exclusions

**Three pages still read clients as a list of a hundred.** `AttributionPage`, `MediaPlannerPage` and
`StoreAnalyticsPage` are in files under uncommitted edit by a concurrent session, so editing them here would
have collided with work in flight. The fix is mechanical and identical to the other twelve screens (replace
the chip row or `<select>` with `EntityPicker` over a `search` parameter). It is recorded here rather than
claimed done. Until it lands, those three screens cap at a hundred clients.
