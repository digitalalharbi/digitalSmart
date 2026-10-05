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
| 3     | Campaign data model, sync jobs, normalised metrics, analytics ingestion         | **Ingestion complete**, Meta adapter live; other adapters in progress |
| 4     | Premium dashboard, filters, saved views, charts, campaign explorer              | **Analytics, charts and campaign explorer complete**; saved views and widget composer outstanding |
| 5     | Budgets, pacing, calculator, forecasting, alerts                                | Planned |
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
- `AdvertisingSyncAdapter`, the sync port, with Meta, Google Ads and TikTok
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

- Sync adapters for Snapchat, X and LinkedIn. Each needs its own mapper against
  its own documented schema and its own fixture set; the registry reports
  `supports() === false` for them rather than offering a stub.
- TikTok's click, video and revenue metric names, which its documentation did
  not make readable.
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

### Not yet done in Phase 4

- Saved views and the custom dashboard widget composer.
- Conversion-action metrics (purchases, leads, installs) in the analytics layer:
  they live in `conversion_metrics` rather than `daily_metrics`, so the read model
  currently reports them as not read.
- CSV/XLSX export.
- Annotations and threshold lines on charts.
