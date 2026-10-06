# Roadmap

Phases come from `CLAUDE.md`, which is authoritative. Work proceeds in vertical slices:
every substantial feature ships migrations, domain/application code, API contract,
frontend UI, tests and documentation together. The repository stays runnable at all
times.

| Phase | Scope                                                                          | Status |
| ----- | ------------------------------------------------------------------------------ | ------ |
| 0     | Repository baseline, dev stack, CI, formatting, linting, tests, env examples, architectural skeleton | **Complete** |
| 1     | Auth, workspaces, users, roles, permissions, clients, projects, audit logs      | **Complete** |
| 2     | Integrations foundation, provider connections, external accounts, project bindings, credential encryption | **Complete** |
| 3     | Campaign data model, sync jobs, normalised metrics, analytics ingestion         | **Ingestion complete**; six provider adapters implemented against published schemas, all SANDBOX until credentials exist |
| 4     | Premium dashboard, filters, saved views, charts, campaign explorer              | **Complete** |
| 5     | Budgets, pacing, calculator, forecasting, alerts                                | **Complete** |
| 6     | Creative hub, tasks, approvals, files, notifications/realtime                   | Planned |
| 7     | Reports, shared client portals, exports                                         | Planned |
| 8     | AI assistant/recommendations with approval gates                                | Planned |
| 9     | Presentation generator                                                          | Planned |
| 10    | Provider write operations, only where API and permissions support them          | Planned |
| 11    | Hardening, load/performance, security, accessibility, observability, backup/recovery, production readiness | Planned |

## Phase 0 — delivered

- Laravel 12 API-only backend, Clean Architecture layers (`Domain`, `Application`,
  `Infrastructure`, `Interface`) with **executable** architecture tests.
- React 19 + Vite + TypeScript (strict) SPA: TanStack Query, Zustand, React Router,
  Axios interceptors, Tailwind v4 + shadcn-style primitives.
- PostgreSQL with UUIDv7 keys; Redis for cache, queue, session and broadcasting;
  Horizon supervising workers; Reverb serving WebSockets — all verified running.
- `GET /api/health` probing database, cache and queue, with a three-valued status.
- Security baseline: strict CORS, Sanctum SPA auth, CSRF, rate limiters, security
  headers, stack-disclosure removal, validated request correlation ids.
- Design system tokens (OKLCH, light/dark), bilingual AR-RTL / EN-LTR shell, mobile
  navigation drawer, accessible focus and reduced-motion handling.
- Gate: Pint, PHPStan level 8, 33 backend tests, 27 frontend tests, 12 Playwright tests
  across Chromium and mobile Safari, all wired into GitHub Actions.

## Phase 1 — delivered so far

- Workspace → Client → Project schema with UUIDv7 keys, check constraints and
  the indexes the scoped queries rely on.
- Permission catalogue derived from a single enum, six system roles, and
  enforcement in the Application layer rather than in controllers.
- Sanctum session authentication, workspace switching, and an append-only audit
  trail that records sign-ins, failures and every client mutation.
- Clients end to end: list with search, status filters and pagination; create,
  edit, status transitions and soft delete; all permission-gated and
  workspace-scoped.
- SPA: sign-in, session gate, account and workspace menu, permission-aware
  navigation, and the clients surface in English and Arabic.

- Projects end to end, including the assignment model: a role without
  `projects.view_all` sees only the projects it is assigned to, enforced in SQL
  rather than by filtering a wider result set. Assigning someone is what grants
  them visibility; removing them revokes it.

- Workspace member administration: role changes, suspension and removal, all
  audited, with the last active owner protected so a workspace can never be left
  unadministrable.
- Audit log viewer, scoped to the workspace, filterable to security events.

Deferred to a later slice: e-mail invitations with an acceptance flow. Members
are added by provisioning today; the permission (`users.invite`) exists and is
unused rather than silently granted.

## Phase 2 — delivered

- Seven provider adapters behind one stable port, each declaring its real
  capabilities. An architecture test fails the build if an adapter claims
  something its platform cannot do.
- `ProviderConnection → ExternalAccount → ProjectIntegrationBinding` implemented
  end to end, with the binding as the only thing that grants a project sight of
  an ad account — permission-gated, audited, and proven isolated in both
  cross-tenant directions.
- Credentials encrypted at rest, redacted in logs and audit entries, and never
  returned by the API.
- `BLOCKED_EXTERNAL` made executable: real credentials produce an honest 503
  naming what is missing, a platform with no published API produces a 501, and
  sandbox mode exercises the whole workflow with clearly labelled fixtures and
  no invented metrics.

## Blocked

| Item                     | Blocker                                               |
| ------------------------ | ----------------------------------------------------- |
| Live provider handshakes | `BLOCKED_EXTERNAL` — credentials/approvals required. See `docs/INTEGRATIONS.md`. |
| Presentation identity    | Awaiting the Smart Code reference deck. The theme/token abstraction is built first, per `CLAUDE.md`. |

Neither blocker stops other work: contracts, mocks, UI, tests and documentation are
built around them.

## Phase 3 — delivered so far

- The advertising schema: `campaigns`, `ad_groups`, `creatives` + `creative_assets`,
  `ads`, `daily_metrics`, `conversion_metrics`, plus `sync_runs`, `sync_cursors`
  and `provider_errors`. Check constraints and unique indexes carry the rules that
  matter — including the one that makes a re-sync idempotent rather than doubling
  spend.
- A normalised vocabulary that every provider maps into: `EntityStatus`,
  `CampaignObjective`, `BudgetMode`, `ConversionActionType`, `CreativeFormat`,
  `AdEntityType`, and `Money` in integer minor units with a currency guard.
- `MetricSource` on every metric row — `live`, `synced`, `stale`, `partial`,
  `sandbox`, `manual`, `estimated` — decided once where the credentials are known.
  Aggregates inherit their weakest input, and sandbox or estimated figures will
  not combine with actuals at all.
- `AdvertisingSyncAdapter`, the sync port, with Meta, Google Ads, TikTok, Snapchat, X
  and LinkedIn
  implementations reading campaigns, ad sets/groups, ads, creatives and
  insights at campaign, ad-group and ad level, against fixtures that hold each
  provider's real response shapes. Google's field names came from the published
  protobuf definitions, which corrected a mapping a reasonable guess would have
  got wrong: v22 has no `metrics.video_views`. TikTok's metric names could not
  be read at all, so only verified ones are normalised and the rest are kept
  unmapped rather than guessed — see docs/INTEGRATIONS.md.
- An idempotent store: every write keyed on the provider's own identifier, metrics
  batched into chunked upserts sized from the row width, and ancestry denormalised
  onto the fact table so the common filters join nothing.
- `advertising:sync` and a queued, per-account-unique job with provider-aware
  backoff; a journal that records every run, its counts and its failures by
  category, so "why is yesterday missing?" is answerable from the product.

### Not yet done in Phase 3

- A sync adapter for ChatGPT Ads. The programme is not generally available and its
  contract is unknown, so the registry reports `supports() === false` and the provider
  declares no capabilities rather than offering a stub.
- TikTok's click, video and revenue metric names, which its documentation did
  not make readable.
- X's asynchronous stats endpoint, which is the only way to read more than seven days
  in one request and returns a downloadable file rather than a response body. The
  synchronous endpoint is used instead, in consecutive seven-day windows.
- X's reach endpoint (`stats/.../reach/campaigns`), which is a separate call with its
  own range limits. Until it is read, every X row reports no reach at all.
- The post a LinkedIn creative sponsors, which is where its headline, image and link
  live. Until it is read, LinkedIn creatives carry no copy and no destination.
- LinkedIn's revenue attribution finder, which needs a CRM connected to Business
  Manager.
- Verification of the unit of Snapchat's `spend` metric. Every other money field in
  their API carries a `_micro` suffix and this one does not; it is mapped as micros,
  and if that is wrong spend is understated 10,000-fold. Only a live response settles
  it — see docs/INTEGRATIONS.md.
- Account-level insight ingestion (the contract and fixture exist; the sync reads
  campaign, ad-group and ad levels).
- Webhooks, where a provider officially supports them.

## Phase 4 — delivered so far

- A composable analytics layer: one query answers scorecard, time series and
  breakdown together, so they cannot disagree. Date presets, period and
  year-over-year comparison, and filters down to the individual ad.
- `Metric` and `Dimension` as domain enums, with each metric's format, precision,
  direction (`lower_is_better`) and dependencies declared once and served to the
  client — so CPA cannot be money on one screen and a count on another.
- Aggregation in SQL, with three refusals built in rather than bolted on:
  reading one entity level only; never summing reach across entities; never
  totalling money across currencies. Each refusal returns null **with a reason**
  the interface shows.
- Derived metrics computed from the totals, never averaged from daily ratios — the
  mean of seven daily CPCs is not the week's CPC.
- Visibility enforced before aggregation: an actor without `projects.view_all`
  has every query narrowed to the projects they are assigned to, intersected with
  whatever they asked for rather than replacing it.
- `access:sync`, which brings existing workspaces' roles in line with the code.
  Without it a deploy that adds a permission reaches new workspaces only, and
  every existing one silently lacks the feature.

### Phase 4 — the interface

- **ECharts**, with only the chart types in use registered and the whole engine in
  a lazily loaded chunk: the initial bundle is 243 kB gzipped and the 209 kB chart
  chunk arrives when a chart surface is first opened.
- An analytics surface with a scorecard, a trend chart whose metric follows the tile
  a reader selects, a spend breakdown, an efficiency-against-scale scatter, and a
  table that keeps each entity's platform id for reconciliation.
- A campaign explorer that drills platform → campaign → ad group → ad, where each
  step narrows the scorecard and the chart as well as the table. A breadcrumb that
  does not change the figures above it is decoration.
- An overview screen that answers one question and links to the surface that answers
  the follow-up, rather than competing to be a second dashboard.
- Charts mirrored for Arabic — reversed category axis, value axis on the other side,
  flipped bar radii, and the Arabic font stack handed to the canvas explicitly.
- Data honesty enforced in the components: a nullable value rendered as an em dash
  with the server's reason beside it, a source badge on anything not live, and a
  notice above the numbers rather than below them.
- The demo seeder now connects Meta, Google Ads and TikTok in sandbox mode, binds
  them to a project and runs the real sync, so the demo workspace demonstrates the
  analytics layer and the E2E suite exercises the whole path.

### Phase 4 — saved views and the dashboard builder


- `saved_views`, `dashboards` and `dashboard_widgets` tables, with CHECK constraints
  carrying the rules that matter: project-scoped visibility cannot exist without a
  project, and a widget cannot be stored outside the 12-column canvas.
- `Visibility` (private / workspace / project — the third because an agency needs a
  dashboard one client's team sees and colleagues on another client do not),
  `WidgetType` (which states the shape of question each type can answer, so a
  share-of-total over 90 dates is refused rather than drawn), and `GridPlacement`
  (which raises rather than clamps, because clamping moves a widget the reader
  placed and an off-grid widget disappears silently).
- `SavedViewRepository` and `DashboardRepository` ports, with Eloquent
  implementations that apply visibility as a `WHERE` rather than filtering
  afterwards — a private record belonging to someone else is never in the result set,
  because a leaked name or even a count is still a leak.
- Full CRUD for both, plus duplicate: `/api/analytics/views` and `/api/dashboards`.
  Only the owner may change or delete; a colleague gets a 404 rather than a 403,
  since whether someone else's dashboard exists is not something a caller learns by
  trying to edit it.
- A builder UI: add, remove, reorder, resize, retitle, choose chart type, metrics and
  grouping, set widget-level filter overrides, pick an audience, duplicate, and save
  the whole canvas. Responsive (full-width widgets below `md`), Arabic RTL, dark mode,
  and loading, empty, error and permission states throughout.
- **Reordering and resizing are buttons, not drag-and-drop.** Deliberate: drag-only
  reordering fails WCAG 2.2's dragging criterion, does not work with a keyboard, and
  is awkward on touch — which is much of where this gets read. Pointer dragging can be
  layered on the same model later without changing it.
- Grid arithmetic as pure functions with 14 unit tests, so every reorder and resize
  re-flows into a valid layout rather than being refused on save.
- 57 backend tests, 32 frontend tests, 11 Playwright specs.

### Not yet done in Phase 4

- Nothing. Pivot-style reports and CSV/XLSX/PDF export belong to Phase 7; annotations
  and threshold lines on charts are deferred to Phase 5 where pacing targets make
  them meaningful.
- Conversion-action metrics (purchases, leads, installs) in the analytics layer:
  they live in `conversion_metrics` rather than `daily_metrics`, so the read model
  reports them as not read. Deferred to Phase 5, where budget pacing needs them.

### Phase 5 — budgets, pacing and forecasting

- `budgets` and `budget_allocations`, the governance hierarchy from CLAUDE.md: an
  approved client budget split across platforms, campaigns and ad groups, with five CHECK
  constraints carrying the rules that matter — including that an approved budget cannot
  exist without the name of whoever approved it.
- **An approved budget's amount, period and currency cannot be edited.** The record
  exists to say what a client agreed to; a figure that can be quietly revised says
  nothing. Changing one means archiving it and approving a new one, which leaves both on
  the record. Allocations stay editable, which is how a plan adapts without the
  commitment moving.
- A separate `budgets.approve` permission, held by owners, administrators and project
  managers but **not** media buyers. Drafting a plan and committing a client to a figure
  are different acts, and that separation is the only thing making "approved" mean
  anything.
- `Pacing`: pure arithmetic over four facts — the budget, the period, actual spend and
  today. Even pacing by definition, with the 10% tolerance published alongside the figure
  so a reader knows what "expected" means. No projection before the period starts, because
  a zero there would read as a forecast of no spend.
- Pacing, the alert list and forecast rates are all **derived on read**, never stored, so
  they cannot disagree with each other or go stale behind a sync.
- A forecast in both directions, from **the workspace's own observed delivery and nothing
  else**. No industry benchmarks exist in this product: a platform with fewer than three
  days of delivery gets a reason instead of a number, bands are the 25th–75th percentile
  of the account's own daily rate, headline rates are weighted rather than averaged, every
  projection is labelled `estimated`, and the sample behind it is published as a version
  string so a figure quoted in a meeting can be reproduced.
- A **Media Planner**: the budget list, the draft form, approve and archive, the
  allocation tree editor, and the pacing panel on one surface — because those are one
  conversation. A planner looking at an overspending platform wants to move money now,
  and sending them to another screen to do it is how allocations go stale.
- The allocation editor shows the remainder at every level and **does not auto-fill it**.
  A control that spread the leftover across children would be making a media decision on
  somebody's behalf and calling it a convenience.
- The platforms it offers come from the **provider registry**, filtered to those whose API
  can report metrics: an allocation that can never be compared with spend is a number in a
  spreadsheet rather than a budget. No page keeps its own list of platform names.
- A **pacing chart** answering one question — is this budget going to land where it was
  meant to? Cumulative spend against the even pace, with the approved budget as a line.
  The actual series **stops at the last day a provider reported** rather than being carried
  flat to today, because a flat cumulative line means "spent nothing since" and a missing
  sync means "we do not know".
- A **Budget Calculator** in both directions, showing each figure's band, its confidence,
  the assumptions in words, the sample version, and the gaps — a platform with too little
  delivery shows a reason instead of a number.
- 20 domain tests, 58 backend feature tests, 30 frontend tests, 9 Playwright specs.

### Not yet done in Phase 5
- Pacing alerts as notifications. The list is computed and exposed; delivering it to
  somebody is Phase 6, where notifications and realtime live.
- Allocation at the **account** level between platform and campaign. Every allocation row
  can name an external account, but the three levels are platform, campaign and ad group —
  an agency running two Meta accounts for one client splits them as two platform rows
  today.
