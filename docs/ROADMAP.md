# Roadmap

Phases come from `CLAUDE.md`, which is authoritative. Work proceeds in vertical slices:
every substantial feature ships migrations, domain/application code, API contract,
frontend UI, tests and documentation together. The repository stays runnable at all
times.

The product grew in October 2026 from a multi-platform advertising dashboard into the
Smart Code Marketing Operating System described in `CLAUDE.md`: measurement, tracking
governance, commerce, leads, journeys and attribution alongside the advertising work. The
phases below were renumbered to carry that scope rather than bolted on as appendices —
phases 1–5 are unchanged and delivered, and the new work slots in where its dependencies
sit.

| Phase | Scope                                                                          | Status |
| ----- | ------------------------------------------------------------------------------ | ------ |
| 0     | Repository baseline, dev stack, CI, formatting, linting, tests, env examples, architectural skeleton | **Complete** |
| 1     | Auth, workspaces, users, roles, permissions, clients, projects, audit logs      | **Complete** |
| 2     | Integrations foundation, provider connections, external accounts, project bindings, credential encryption | **Complete** |
| 3     | Campaign data model, sync jobs, normalised metrics, analytics ingestion         | **Ingestion complete**; six advertising adapters implemented against published schemas, all SANDBOX until credentials exist |
| 4     | Premium dashboard, filters, saved views, charts, campaign explorer              | **Complete** |
| 5     | Budgets, pacing, calculator, forecasting, alerts                                | **Complete** |
| 6     | **Provider registry** — one canonical catalogue of providers, categories, capabilities, auth strategies and API status, consumed by both ends | **Complete** |
| 7     | **Measurement foundation** — measurement sources, normalised event vocabulary, conversion registry, event mapping, observed events, tracking health | **Complete**; sessions and touchpoints moved to Phase 16, where identity evidence lives |
| 8     | **Google Analytics 4** — accounts, properties, data streams, metadata-driven metrics, key events as suggestions, event counts, sampling and thresholding honesty, freshness states | **Complete**; BLOCKED_EXTERNAL on an authorised OAuth client, sandbox end to end |
| 9     | **Google Tag Manager** — accounts, containers, published versions, tags, triggers; evidence-backed readiness checks that refuse to read a tag's purpose from its name | **Complete**; BLOCKED_EXTERNAL on an authorised OAuth client, sandbox end to end |
| 10    | **Tracking Center** — per client: what is measuring, what counts as success, and what needs attention, with the evidence behind every finding | **Complete** |
| 11    | **Commerce domain** — normalised stores, orders, items, products, variants, customers, refunds, shipments, webhook events; two state axes, revenue sources, attribution evidence | **Complete**; provider adapters are Phases 12–13 |
| 12    | **Salla** integration — entities, HMAC webhook verification, queued handling, reconciliation sync, two-axis status mapping | **Complete**; BLOCKED_EXTERNAL on a Partner app install, sandbox end to end |
| 13    | **Zid** integration — the same normalised domain, which Zid fits natively; Basic Auth webhooks, custom merchant statuses | **Complete**; BLOCKED_EXTERNAL on a Partner app install, sandbox end to end |
| 14    | **Store analytics and revenue sources** — orders, sales, AOV, refunds, revenue by channel/campaign/product, with a required revenue source on every request | **Complete** |
| 15    | **Lead intelligence** — Lead Center, sources, pipeline, qualification, outcome | **Backend complete**; the CRM bridge and the frontend surface are outstanding |
| 16    | **Customer journeys** — touchpoints, identity evidence, identity confidence, Journey Explorer | Planned |
| 17    | **Attribution** — first/last/paid-last/linear/position/time-decay models, each exposing coverage, data quality, confidence and revenue source | Planned |
| 18    | **Creative operations** — briefs, requests, assets, versions, specs, validation, comments, approval workflow, Kanban | Planned |
| 19    | **Operations** — tasks, approvals engine, client requests, notifications and realtime | Planned |
| 20    | **Reporting** — report builder, saved reports, exports, secure client live reports and snapshots | Planned |
| 21    | **Client portal** — per-module visibility, never leaking internal notes | Planned |
| 22    | **AI** — Ask/Analyze/Investigate/Report/Plan, evidence-backed, permission-aware, recommendation-first | Planned |
| 23    | **Presentation generator** — PPTX and PDF, Arabic RTL, Smart Code theme, real charts and tables | Planned |
| 24    | **Provider write operations** — only where the API supports them, behind capability, permission, explicit approval and audit | Planned |
| 25    | **Production hardening** — security, performance, load, queue resilience, observability, backup and restore, WCAG 2.2 AA, deployment and runbooks | Planned |

Phases 6–17 are new scope. The sequence is a dependency order, not a preference: the
registry has to exist before anything new consumes provider vocabulary; measurement has to
exist before GA4 and GTM have somewhere to land; the commerce domain has to exist before
Salla or Zid can normalise into it; and attribution cannot be honest until journeys,
conversions and revenue sources are all real.

`CLAUDE.md` lists its own phases 6–11 (creative hub, reports, AI, presentations, provider
writes, hardening). Those are phases 18–25 here, with the same content: the numbers moved
to make room, the scope did not. `CLAUDE.md` stays the authoritative statement of *what*
the product is; this file is the order it gets built in.

### Vocabulary this product keeps separate

These are deliberately *not* merged anywhere, because each answers a different question and
a reader comparing them needs to know which they are looking at:

| Concept | Means |
| ------- | ----- |
| Platform-reported revenue | What an ad platform claims it drove, under its own attribution |
| GA4 revenue | What the analytics property recorded |
| Store revenue | What Salla or Zid actually invoiced |
| Attributed revenue | What this product assigned to a campaign, with a stated model and confidence |

The same applies to conversions: a Google Ads conversion, a GA4 key event, a Salla order
and a CRM-won deal are four different records of possibly the same thing, and silently
adding them is the most expensive mistake this product could make.

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

- Nothing. Pivot-style reports and CSV/XLSX/PDF export belong to Phase 20; annotations
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

### Phase 6 — the provider registry

- `Provider` answers everything about a platform: label, category, API status, auth method,
  credential keys, capabilities, entities, rate limit and documentation URL. No page or
  mapper keeps its own copy, and the labels briefly hard-coded in the allocation editor are
  gone.
- Four providers added with connection adapters and honest statuses — **GA4**, **GTM**,
  **Salla** and **Zid** — so each appears with a status somebody can act on before its sync
  adapter exists.
- Capabilities that genuinely span categories declare none, which is what keeps the
  registry's consistency check meaningful: a store cannot claim it reports campaign metrics.
- GA4 and GTM declare **no write capability**, though both publish write APIs. Changing
  somebody's measurement setup from a reporting tool is a hazard, and the registry is where
  that decision is visible.
- The integrations page groups by category, from the registry's own ordering.

### Not yet done in Phase 6

- OAuth flows for the four new providers. The registry, the adapters and the credential
  model are in place; the live handshakes are BLOCKED_EXTERNAL on app registration exactly
  as the six advertising providers are.

### Not yet done in Phase 5
- Pacing alerts as notifications. The list is computed and exposed; delivering it to
  somebody is Phase 19, where notifications and realtime live.
- Allocation at the **account** level between platform and campaign. Every allocation row
  can name an external account, but the three levels are platform, campaign and ad group —
  an agency running two Meta accounts for one client splits them as two platform rows
  today.

### Phase 7 — the measurement foundation

The layer every later phase reads. Store analytics, lead intelligence, journeys, attribution,
the AI analysis and the generated presentations all resolve conversions through this, so a
guessed meaning here would make every one of them confidently wrong in a way no chart reveals.

- **Measurement sources** — a client's GA4 property, GTM container, ad account, store, CRM,
  website form or typed figure. A source's kind must match its provider's category, checked
  against the Phase 6 registry: filing a Salla store as an analytics property would put
  invoiced revenue in the column holding what a browser reported.
- **Only a store and a CRM are revenue authorities.** Published on every source. An analytics
  property reports what a browser said a sale was worth; an ad platform reports what it
  believes it caused. Phase 14's revenue-source selection is built on this.
- **`NormalizedEvent`** — fourteen cases, each answering whether it is a conversion by nature
  and whether it is **intent only**. `whatsapp_click`, `phone_click` and `form_start` are
  marked: a click is not a conversation, and reporting a cost per click as a cost per lead is
  the commonest way an agency overstates its results.
- **The conversion registry and its mappings.** Nothing infers meaning from an event name. Four
  refusals, each preventing a number nobody could defend: a conversion nothing maps to, a raw
  name claimed twice in one scope, the same name twice in one record, a primary that is not a
  conversion. Editing replaces the mappings outright and the audit keeps both sides.
- **Freshness derived, not stored** — `live`, `synced`, `stale`, `silent`, `never_synced`, with
  `stale` and `silent` deliberately distinct: old figures and a source that is working
  perfectly while measuring nothing need different people.
- **Tracking health** — a pure diagnosis over ten issue codes, every one carrying its evidence
  and a remedy, sorted worst-first, recomputed on every read. Documented in
  [TRACKING.md](TRACKING.md), including what it refuses to infer.
- 52 tests: the domain rules as plain objects, the endpoints through HTTP.

### Not yet done in Phase 7

- **Nothing populates `observed_event_counts` in production yet.** The schema, read model,
  diagnosis and API are complete and tested; GA4 (Phase 8) and the commerce adapters
  (Phases 11–13) are what will fill it. Until then the tracking centre's honest answer for a
  real workspace is `never_synced`, which is the correct answer.
- **Sessions and touchpoints** moved to Phase 16. They are identity questions — which
  observations belong to one person — and answering them without identity evidence and a
  confidence level would be exactly the guessing this phase refuses.
- **No cross-source deduplication.** A purchase in GA4 and the same purchase in Salla are two
  observations of one sale. Keeping them apart is the precondition for Phase 14 merging them
  under a revenue source the user chooses.

### Phase 8 — Google Analytics 4

The first source that actually fills `observed_event_counts`, built against the published Admin
API v1beta and Data API v1beta contracts.

- **`MeasurementSyncAdapter`**, separate from the advertising one because the questions differ: an
  ad platform is asked about campaigns and spend, an analytics property about which raw events
  arrived. One interface for both would mean a GA4 adapter implementing `fetchCampaigns()` as a
  shrug.
- **Report rows are read through `dimensionHeaders`, never by position.** The failure this
  prevents — adding a dimension and silently storing every date as an event name — leaves the
  figures looking plausible. The fixtures list `eventName` before `date` so a positional reader
  fails the suite at once.
- **The value metric is asked for, not assumed.** `getMetadata` is read and a metric GA4 itself
  declares `TYPE_CURRENCY` with no `blockedReasons` is used. A property reporting no money is read
  for counts only — the honest outcome, where a hard-coded name would be an invented metric.
- **GA4's own completeness signals reach the figures' labels.** Sampling
  (`samplesReadCount / samplingSpaceSize`), `subjectToThresholding`, `dataTruncationReasons` and
  `dataLossFromOtherRow` each make a run `PARTIAL` rather than `SYNCED`, with a sentence per cause.
  A tool that drops these reports 412 leads whether it saw all of them or 4% of them.
- **Key events are suggestions a person confirms**, and a normalised event is proposed only for
  names Google documents — `generate_lead` included in the refusal, because it is not on either
  cited page.
- Discovery is unfiltered and flags roll-up properties, which would double-count a client measured
  through both a roll-up and its sources.
- The sync re-reads a 14-day restatement window and upserts; `last_event_at` only moves forward,
  settled in SQL.
- **A real Phase 6 defect found here:** the `provider_connections` CHECK constraint still listed
  only the six advertising platforms, so GA4, GTM, Salla and Zid appeared on the integrations page,
  offered a Connect action, passed validation and then failed at the insert. Widened from the enum.
- 60 tests across the mapper and the HTTP surface.

### Not yet done in Phase 8

- **The live transport.** Needs a Google Cloud project with both APIs enabled, an allow-listed
  OAuth redirect URI and a user who can read the property. No programme to be admitted to — the
  blocker is configuration and consent. Until then real credentials fail loudly and name what is
  missing, and no code path invents a count.
- **Scheduled syncing.** The use case is queue-ready and driven synchronously by a button today;
  putting it on a schedule belongs with the operations phase that owns job orchestration.
- **Per-project property binding.** A source can name a project and inherits to a client's
  projects otherwise, which covers the agency case; the explicit binding UI arrives with the
  Tracking Center in Phase 10.
- **Currency exponents.** Revenue rounds at two decimals, so JPY and KWD are wrong. A shared
  `Money` limitation, recorded in GOOGLE_ANALYTICS.md.

### Phase 9 — Google Tag Manager

Container readiness: whether what somebody built is deployed and able to fire. Built against the
Tag Manager API v2, read-only permanently — the API can publish tags, and a reporting tool that
could rewrite a client's measurement setup will eventually break one with nobody able to say what
changed.

- **`TagContainerReadAdapter`**, a third adapter shape, because a container reports configuration
  rather than events. Forcing it into the sync contract would mean an empty `fetchEventCounts()`,
  which reads as "measured nothing" rather than "not that kind of thing".
- **It reads the live version, never a workspace.** A workspace is what somebody is working on; the
  live version is what the site runs. Believing the first is the second is the commonest failure in
  this area. A container nobody published is reported as exactly that, and as the *only* finding,
  because anything else would bury it.
- **It never reads a tag's purpose from its name.** "GA4 – Purchase" is a label somebody typed; it
  may fire on a thank-you page, on every page, or never. A container whose only tag is called that
  and which fires properly produces no findings at all, and a test is named after it.
- **Built-in triggers are not accused.** Every container has undeletable All Pages, Consent
  Initialization and Initialization triggers that the API never returns in the trigger list. Nearly
  every real tag fires on All Pages, so a naive comparison reports the whole container broken — the
  false positive that would have killed the feature on first use. The threshold is set so the
  failure mode is silence, never a wrong accusation.
- **The measurement-id cross-check compares the client's registered property against what the
  published container refers to**, scanning for the id's own shape at any depth under any key — the
  parameter key depends on the tag template, so finding the id is a fact while assuming a key would
  be a guess. Reported as a warning phrased as a question, with the ids it did find as evidence.
- Readiness is its own endpoint rather than part of `/measurement/health`: health is derived from our
  own tables and always answers, and a Tag Manager outage must not empty the tracking page.
- 29 tests.

### Not yet done in Phase 9

- **The live transport**, on the same gate as Analytics.
- **Variables and folders** are read past rather than assessed. Nothing honest can be concluded from
  a variable's existence, and inventing findings to make the page look thorough is the opposite of
  the point.
- **Consent-mode configuration** is visible in a tag's `consentSettings` and deliberately not
  assessed yet: whether a setup is compliant is a legal judgement about a jurisdiction, not a
  structural fact, and this product will not imply it has made one.

### Phase 10 — the Tracking Center

The surface that makes Phases 7 to 9 usable, and the first nav item to arrive since the budget
pages.

- **Three panels in the order somebody needs them**: what needs attention, what is measuring, what
  counts as success. The issue list is first deliberately — a page opening on a tidy table of
  sources invites the conclusion that everything is fine, which is the conclusion that costs an
  agency a quarter of a client's spend.
- **Every finding shows its remedy and its evidence**, on the page rather than behind a tooltip. An
  issue list without remedies is a list of complaints, and a diagnosis without evidence is one
  people stop believing after the first false alarm.
- **Silence is a rendered state with its own words.** "Nothing needs attention" is said out loud,
  because a blank area reads as a loading failure.
- **Registering a property is discovery-driven**, never a text field: nobody can be expected to type
  `properties/412006881` correctly, and a mistyped id produces a source that syncs nothing while
  looking perfectly configured. Roll-up properties are flagged in the picker.
- **The conversion editor enforces the central rule in the interface.** Raw names are typed or taken
  from the property's key events; the meaning is always the person's choice. The suggestion list
  fills in a name and applies a meaning only where the product can cite one.
- **The intent-signal warning appears at the moment somebody chooses it**, not only in the issue list
  a week later.
- **A sync reports its caveats, not just success.** A run can succeed completely and still have read
  estimates, and the person who pressed the button is the one who needs to know before they quote
  the number.
- `observed_count` renders `0` and `—` differently, because "asked and never happened" and "nobody
  looked" are different facts.
- Save failures show **the server's own message**, which names the event name to change.
- 11 component tests and 10 end-to-end tests across both browser projects; full suite 146 passing.

### Not yet done in Phase 10

- **Container readiness is not on the page yet.** The endpoint and its assessment are complete
  (Phase 9) and the surface shows analytics sources only; adding a tag-manager panel needs a design
  decision about how a live provider call behaves beside data that always loads, and it is worth
  making that deliberately rather than bolting a spinner onto this page.
- **Project scope.** The page filters by client, which is how measurement is agreed. Per-project
  filtering is a one-line addition once a project picker exists on a surface that needs it.
- **No charts.** An event-count trend is the obvious candidate and was left out on purpose: the
  question this page answers is "is it working", and a sparkline would invite reading it as
  performance.

### Phase 11 — the commerce domain

One shape for every store platform, written **before** either provider so neither defines it. An
agency reporting on one client per platform would otherwise be comparing two different meanings of
"paid".

- **Payment and fulfilment are separate columns.** Every platform has both axes, and a single `status`
  makes "delivered but refunded" unrepresentable — an entirely ordinary thing for a shop. The
  provider's own strings travel alongside, because a mapping will be disputed.
- **Only `paid` and `partially_refunded` count as revenue.** Cash on delivery that never arrives is not
  a sale, and in this region that is a large share of orders.
- **`RevenueSource` is never silently merged.** An ad platform reports what it believes it caused, an
  analytics property what a browser said, a store what was invoiced, a CRM what somebody recorded.
  Adding two together produces a number nobody can defend and everybody quotes.
- **`AttributionEvidence` makes "never falsely assign an order to a campaign" enforceable**, with a
  confidence per level and a database CHECK that evidence must be backed by a field on the order. A
  bare `utm_source` is `partial_utm` and cannot name a campaign; `none` is a legitimate and common
  answer.
- Everything upserts; order lines are replaced; variants are not, because order items reference them.
- Customer lifetime totals are derived from stored orders rather than taken from the provider.
- The webhook journal records every arrival before any work, with `ON CONFLICT DO NOTHING` — a caught
  violation would abort the surrounding transaction, which a handler that also writes an order will be
  inside.
- 32 tests.

### Not yet done in Phase 11

- **No provider reads anything.** `CommerceSyncAdapter` is the contract Salla and Zid implement.
- **No HTTP surface.** Store analytics arrive with Phase 14, where the revenue-source selection they
  need is designed.
- **No cursors or run journalling.** Deliberately deferred to Phase 12, where a real provider's
  pagination determines their shape — bookkeeping built before the thing that needs it fits nothing.
- **Payments are folded onto the order** as `payment_method` and `gateway` rather than given their own
  table. A separate payments table earns its place when a platform reports split payments, and neither
  Salla nor Zid is known to yet.

### Phase 12 — Salla

The first store platform, read against the published Merchant API v2 and mapped into the Phase 11 shape
rather than being allowed to define it.

- **Salla has one status axis; this product has two.** Five of its twelve documented slugs state the
  payment outright and the mapper quotes the documentation for each. The other seven describe a parcel —
  and in a market where cash on delivery is normal, `shipped` means "not paid" for one order and "paid
  last week" for another. Those read Salla's documented `is_pending_payment` boolean rather than guessing
  from the slug, and fall back to `pending` when it is absent, because an agency that finds its revenue
  low goes looking while one that finds it high has already sent the report.
- **Pages are walked in sequence**, because Salla documents that a page out of order returns an empty
  response. A sync that parallelised would read nothing and report success.
- **Webhook signatures are HMAC-SHA256 over the raw body**, compared in constant time, with an
  unconfigured secret and an unknown strategy both verifying nothing. The fixture body is Arabic, so an
  implementation that re-encoded before hashing fails the suite immediately rather than in production.
- **The signature decides which workspace a delivery belongs to.** Two agencies can register the same
  shop; routing by first match would leak one client's orders into the other's workspace.
- **Salla sends no event id, and it does not matter**: the work is an upsert keyed on the order's own id,
  so a redelivery writes the same row. No identity is synthesised from a payload hash.
- **The reconciliation sync and the webhook path share one mapper**, with a test asserting they agree. A
  second mapper is how a client's revenue comes to change whenever a nightly sync overwrites what a
  webhook wrote an hour earlier.
- **A Phase 11 schema correction**: the order amount breakdown is now nullable, because Salla's list
  endpoint reports a total and nothing else and a stored zero would read as a real charge of nothing.
- 58 tests.

### Not yet done in Phase 12

- **The live transport**, on a Partner app install and the OAuth handshake.
- **Order details are not fetched per order.** The list's total is used and the breakdown left null;
  one request per order is a quota decision worth making deliberately rather than by default.
- **No scheduled syncing.** Queue-ready and driven directly today.
- **No webhook subscription management**, because creating a subscription is a write and this
  integration does not write.

### Phase 13 — Zid

The second store platform, and the test of whether the Phase 11 domain was secretly Salla-shaped.

- **Zid fits it natively.** It publishes a dedicated `payment_status` alongside its `order_status`, so
  where Salla needed a documented boolean to recover the payment axis, Zid simply states it. The
  two-axis design was a judgement call made before either provider existed; this is the evidence it was
  right.
- **A merchant can invent its own order statuses.** An unrecognised code maps to `processing` — which
  claims only that the order is in progress and, crucially, is *not settled*, so the sync keeps
  re-reading until the order reaches a state this product understands. A state we cannot interpret should
  keep us looking, not stop us.
- **`reverse_in_progress` is not `returned`**, for the same reason: `returned` is settled, and an order
  mid-reversal is still moving.
- **Zid authenticates webhooks with Basic Auth, not a signature**, and it is implemented as what it is.
  It proves the caller knows a password and says nothing about the body; the secret travels on every
  request; there is no replay protection. What makes that tolerable is the upsert, and the document says
  so plainly rather than implying parity with Salla's HMAC.
- **A Salla signature is refused on the Zid endpoint**, with a test, so the weaker mechanism's
  assumptions cannot leak into the stronger one's code.
- **A real bug Zid found**: the webhook use case had a hard-coded list of actionable topics written
  against Salla's vocabulary, and Zid's event names differ (`order.status.update` against
  `order.status.updated`). Every Zid order webhook would have been recorded, marked `ignored` and
  silently dropped, with the endpoint looking healthy throughout. Now matched by the `order.` prefix,
  because over-matching costs one journal row and under-matching costs a client's orders.
- **Marketplace orders arrive with the customer masked**, and that is handled structurally: nothing writes
  a customer from an order payload, so a placeholder has no route into the customers table.
- `order_total`, never the formatted `order_total_string`; Arabic preferred for localised names;
  `is_infinite` stock stored as null.
- 50 tests.

### Not yet done in Phase 13

- **The live transport**, on a Partner app install and the OAuth handshake.
- **No scheduled syncing**, and **no webhook subscription management** — creating a subscription is a
  write.
- **`total_order_count_per_status` is ignored.** This product derives its own counts from the orders it
  stored, so a figure a reader can reconcile is never replaced by one they cannot.

### Phase 14 — store analytics and revenue sources

Where the commerce domain becomes a figure somebody quotes to a client, so nearly all of it is about what
is excluded from a total.

- **`revenue_source` is a required parameter with no default and no "all" value.** A Salla total counts
  invoices; a GA4 total counts what a browser reported before the payment failed. A request without one is
  refused rather than answered.
- **Only `paid` and `partially_refunded` orders count.** Pending cash-on-delivery orders are reported
  beside the total, never inside it — in this market that is most of a busy week, and counting them would
  overstate every report until the courier collects or comes back.
- **Revenue is net of refunds**, with gross published beside it so a refund is visible rather than inferred
  from a smaller number.
- **Average order value is null when nothing counted.** A denominator of zero has no average, and "0.00"
  invites a comparison with last month.
- **Attribution coverage travels with every summary**, because a channel report at 30% coverage is a
  different document from one at 95%.
- **A breakdown row carries the weakest evidence it rests on**, not the strongest: a group's total is only
  as defensible as its flimsiest link.
- **`by_campaign` names a campaign only where the evidence can.** A bare `utm_source` names a channel, and
  splitting it across campaigns would be inventing the split.
- **The unattributed row is produced, not dropped.** It is usually the largest, and omitting it so the
  percentages add to a hundred overstates every row above it.
- **The summary's honesty label is the weakest of its rows**, so a total spanning a sandbox store and a
  live one is a sandbox figure.
- Two real bugs found by the tests: a `source` alias that shadowed the orders table's own `source` column
  and silently grouped by the wrong one, and a rank aggregate that returned each group's *strongest*
  evidence rather than its weakest — which would have flattered every row invisibly.
- 29 tests.

### Phase 14 — the store revenue page

- **The revenue-source selector is the first control on the page**, always visible, and what it is set to
  is repeated in words on the total itself. The page refuses to make that choice quietly.
- **Three different sentences for three kinds of claim**: an invoiced figure is "the closest thing to what
  the client actually earned", a reported one "a measurement of sales, not a record of them", and a
  modelled one carries an explicit instruction not to present it to a client as actual.
- **Pending orders are an amber banner beside the totals**, never folded into them, naming the amount and
  why it is excluded.
- **Attribution coverage sits beside the breakdowns** and turns amber below 70%, because it changes what
  every row below it can be read as.
- **Each breakdown row shows the weakest evidence it rests on with its confidence**, and a coupon-backed
  row is styled as a warning rather than as neutral.
- **The unattributed row is labelled and kept**, because a reader who cannot see it reads every other row
  as a larger share of the business than it is.
- Summary figures are built as `MetricValue`s so the shared card handles null-as-absent and the source
  badge, rather than reimplementing either differently.
- The chart draws refunds as a second series rather than netting them away invisibly: a flat month caused
  by returns is a different business problem from a month where nobody bought anything.
- 11 component tests and 4 end-to-end tests; full suite 154 passing.

### Not yet done in Phase 14
- **Mixed currencies are not converted.** A client selling in two currencies has no single total, and
  summing minor units across them would be wrong by whatever the exchange rate is. The scope reports the
  first store's currency and a caller is expected to narrow; a proper answer needs a rates source and a
  decision about which day's rate, which is its own piece of work.
- **No new versus returning customer split.** The data is there — `store_customers.first_order_at` — and
  the definition is a product decision (returning within what window?) rather than a query.

### Phase 15 — lead intelligence

Built around one sentence: **a lead is a person who did something, and a click is not a lead.**

- **`LeadSourceKind` has no member for a click, a tap or a form view.** Every member is a completed action — a
  form submitted, a message received, a call answered, an order placed — so there is no value to pass that would
  turn a counted signal into a lead. The rule is enforced by absence rather than by a check somebody could
  remove.
- **Signals appear beside the leads and are never summed with them**, and no ratio between the two columns is
  published. A tap and a lead do not share a denominator: the same person taps three times, or taps and then
  phones instead. The gap is information; a percentage would be a claim.
- **Every rate's denominator is the defensible one.** Wins divide by *closed* leads, because an open lead is not
  a loss and a pipeline a fortnight old is mostly open. Qualification divides by leads somebody *reached*,
  because a lead nobody has phoned has not failed to qualify. Each returns null rather than zero when empty.
- **`contacted` is an attempt, not contact.** Three voicemails are not contact, and `first_contacted_at` is set
  when somebody got through — which is what makes `hours_to_first_contact` the figure that tells a bad campaign
  from a slow sales team.
- **A disqualification must say why**, in the use case and in a database CHECK. A disqualified pile with no
  reasons is the least useful thing an agency can hand back.
- **A Saudi phone number written four ways is one lead.** Deduplicated within a day, and treated as a new
  enquiry beyond it; an anonymous enquiry is always new, because refusing the second would lose one that
  happened.
- **A follow-up can improve an attribution, never weaken it** — and only wholly, because a half-merged
  attribution is a link nobody can verify.
- **A media buyer may read the pipeline and not qualify a lead.** Judging the quality of what you bought is not
  a judgement to hand to the buyer.
- One attribution vocabulary shared with the orders, so a cross-surface report holds both figures to one
  standard of proof.
- 47 tests.

### Not yet done in Phase 15

- **No CRM bridge.** The source kind exists and nothing populates it. A CRM integration needs a named provider,
  its OAuth, and a decision about which system owns a stage when both have one — the last part is a product
  question.
- **No automatic lead from an order.** The column is there and the link is not made, because "every customer is
  a lead" is true for some clients and nonsense for others.
- **No merge of two leads a human knows are one person.** The event type is reserved; the operation needs a UI
  and a decision about whose history survives.
- **No lead scoring.** A score is a model, and a model presented as a fact is what this product refuses. It
  belongs with the AI phase, labelled as modelled.
- **No frontend surface.**
