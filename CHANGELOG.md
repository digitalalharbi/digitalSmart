# Changelog

All notable changes to digitalSmart are recorded here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses
[Semantic Versioning](https://semver.org/).

## Phase 4 — analytics interface

### Added

- **ECharts** behind a wrapper that registers only the chart types in use, reads its
  palette from the OKLCH design tokens at render time, resizes via `ResizeObserver`,
  honours `prefers-reduced-motion`, and requires an `ariaLabel` — a canvas is
  invisible to a screen reader, so a chart without one is a hole in the page.
- Chart series tokens (`--ds-chart-1` … `--ds-chart-8`), deliberately separate from
  the platform colours so a chart of four Meta campaigns is not four shades of one
  blue, and lifted in dark mode without changing hue.
- An analytics surface: scorecard, trend chart driven by the tile a reader selects,
  spend breakdown, efficiency-against-scale scatter, and a detail table that keeps
  each entity's platform id.
- A campaign explorer drilling platform → campaign → ad group → ad, where each step
  narrows the scorecard and chart as well as the table.
- An overview screen showing four headline figures and spend by platform.
- `MetricCard`, `SourceBadge` and `SourceNotice`: a null figure renders as an em
  dash with the server's reason beside it, anything not `live` is badged, and the
  notice sits above the numbers so a reader meets the caveat first.
- Full Arabic translation of every new surface, and charts mirrored rather than
  merely translated.
- 42 new frontend tests and 8 new Playwright specs, including one asserting that a
  sandbox surface says so before showing a figure.

### Changed

- The two chart surfaces are lazily loaded. The initial bundle went from 454 kB
  gzipped to 243 kB, with the 209 kB chart chunk arriving on first visit. This also
  required keeping the page components out of `features/analytics/index.ts`: the
  overview screen importing a hook from that barrel pulled the whole engine back
  into the initial bundle.
- `formatCurrency` keeps the minor unit on every amount. It previously dropped the
  decimals above a thousand for density, which is wrong for figures reconciled
  against a platform's invoice — and made a column where 840.25 kept its cents and
  12,500.00 did not.
- The metric vocabulary moved to `shared/metrics`. `shared/ui` needs it, and a
  shared component importing from a feature inverts the dependency — caught by the
  lint rule rather than by review.
- `/analytics` and `/campaigns` nav items are gated on `analytics.view` rather than
  `projects.view_assigned`: offering a surface to a role that would receive a 403 is
  worse than not offering it.
- The demo seeder connects three providers in sandbox mode, binds them and runs the
  real sync. An earlier version of that file said it never would; the reasoning
  still holds, and what makes it safe is the labelling downstream rather than the
  absence of data — a demo workspace with no performance data cannot demonstrate or
  test the analytics layer at all.

## Phase 4 — analytics layer

### Added

- `Metric` and `Dimension` domain enums: 28 metrics with their format, precision,
  direction, dependencies and whether they may be summed across entities.
- `AnalyticsReadModel` and its SQL implementation, answering scorecard, series and
  breakdown from one query with one set of filters.
- `GET /api/analytics` and `GET /api/analytics/catalogue`.
- `analytics.view`, `analytics.export` and `saved_views.manage` permissions, and
  `access:sync` to bring existing workspaces' roles in line with the code.
- 28 analytics tests, asserted against data the real sync wrote from the SANDBOX
  fixtures rather than hand-built rows — so the aggregation cannot agree with
  itself while disagreeing with the ingestion.

### Notable refusals, each returning null with a reason

- **One level only.** A campaign total is the campaign row, never the sum of its
  ads. Both levels hold figures for the same money, and reading them together
  roughly doubles every total.
- **No summed reach.** It counts deduplicated people, so adding two campaigns'
  reach double-counts anyone who saw both. Reported per entity, refused across
  entities, with the count of entities in the message.
- **No cross-currency money.** Converting needs a rate, a date and a decision
  about which rate, none of which belongs in a read model.
- **No ratio from a zero denominator**, and no percentage change from a period
  with nothing in it. "Up from nothing" has no percentage, and +∞ or +100% is a
  number someone will quote in a meeting.

### Fixed

- `SyncAccessCatalogue` reached into Infrastructure from the Interface layer — a
  real layering violation the architecture test caught. It now goes through an
  Application port.
- Two tests used `limit(1)->update()`. PostgreSQL has no `LIMIT` on `UPDATE`, so
  that quietly rewrites the whole table or nothing; they target rows by key.

## TikTok sync

### Added

- `TikTokMapper` and `TikTokSyncAdapter`, mapping structure and the reporting
  envelope from TikTok's own reference: `budget` as a float in the account
  currency, `operation_status` versus `secondary_status`, `{dimensions, metrics}`
  rows with every value a string, and the literal `"-"` sentinel for an
  unavailable metric — which becomes null, never zero.
- 23 mapper tests and 3 cross-provider sync tests, including one proving three
  providers land in the same tables without colliding on campaign ids.

### Deliberately not added

- TikTok's click, video and revenue metric names. Its supported-metrics reference
  is only reachable from inside its own documentation SPA, so those names could
  not be confirmed. The mapper keeps a `VERIFIED_METRICS` list, leaves the
  unverifiable metrics **null**, and carries every unrecognised metric into
  `metadata.unmapped_metrics` untouched. A gap can be labelled; a plausible
  figure in the wrong column cannot be detected.

### Fixed

- TikTok's `bid_price: 0` (returned when bidding is automatic and the real target
  is `conversion_bid_price`) was being read as a bid of zero, which asserts
  something about the campaign rather than reporting an absence.

## Google Ads sync

### Added

- `GoogleAdsMapper` and `GoogleAdsSyncAdapter`, reading campaigns, ad groups, ads
  and insights from SANDBOX fixtures in protobuf-JSON shape. Field names were
  taken from the published **protobuf definitions** rather than the HTML field
  reference, which renders client-side — and that immediately corrected a mapping
  a reasonable guess would have got wrong: v22 has no `metrics.video_views`. The
  metric is `videoTrueviewViews`, and quartile completion exists only as a rate.
- 38 new tests covering micros conversion, shared budgets, `primaryStatus` as the
  real delivery signal, fractional conversions, and the metrics Google simply
  does not publish.
- `ConversionActionType::StoreVisit`, which Google reports as a category.

### Changed

- The sync adapter base class now owns the paging loop and the sandbox gate, so a
  provider contributes only its fixture names and its row mapping. Adding Google
  took a 90-line adapter instead of a 200-line one.
- `EloquentAdvertisingStore` memoises its id and parent maps for the lifetime of
  a sync, invalidating per model on write. Without it every page re-read the whole
  campaign, ad-group and creative tables; a Google sync test went from 34s to
  0.2s.
- Sandbox fixtures now page structure at 2 rows and performance at 50. Structure
  stays tiny so every sync crosses page boundaries and cursor handling is
  exercised; paging a fortnight of ad-level rows two at a time bought no coverage
  and cost every test run.
- The fixture loader supports a `_date_paths` directive, so a provider that nests
  its date (Google puts it under `segments`) gets it where a live response would
  put it.

### Documentation

- `docs/INTEGRATIONS.md` gains the Google Ads field-mapping table, including the
  metrics Google does not expose and why they stay null rather than being
  approximated.

## Phase 3 — campaign data model, sync and normalised metrics

### Added

- Advertising schema: `campaigns`, `ad_groups`, `creatives`, `creative_assets`,
  `ads`, `daily_metrics`, `conversion_metrics`, `sync_runs`, `sync_cursors`,
  `provider_errors`.
- Normalised advertising vocabulary (`EntityStatus`, `CampaignObjective`,
  `BudgetMode`, `ConversionActionType`, `CreativeFormat`, `AdEntityType`,
  `SyncKind`, `SyncStatus`, `ProviderErrorCategory`) and the `Money` /
  `MetricSet` value objects.
- `MetricSource` on every metric row, with aggregate rules: a total inherits its
  weakest input's label, and sandbox or estimated figures never combine with
  actuals.
- `AdvertisingSyncAdapter` port and `MetaSyncAdapter`, reading campaigns, ad sets,
  ads, creatives and insights from SANDBOX fixtures shaped like real Graph API
  responses. `MetaMapper` handles Meta's minor-unit budget strings, decimal spend
  strings, `effective_status`, the ODAX objective rename, and nested `actions`
  categories that must not be summed.
- Idempotent `AdvertisingStore`, a `SyncJournal` recording every run and failure,
  the `SyncExternalAccount` / `SyncBoundAccount` use cases, a per-account-unique
  queued job with provider-aware backoff, and the `advertising:sync` command.
- 51 new tests: Meta normalisation, sandbox fixture loading and pagination,
  advertising domain rules, and an end-to-end sync covering isolation, hierarchy
  linking, null-versus-zero, source labelling, idempotency and the journal.

### Changed

- `Money::toDecimal()` now returns an exact decimal **string** built with integer
  arithmetic, and the lossy float conversion moved to `toFloat()`. A class whose
  reason to exist is that floats cannot hold 0.1 should not hand one back at the
  last step.
- Sandbox-ness moved from the adapter to `Credentials::isSandbox()`: whether a
  connection is a sandbox decides how every number it produces is labelled, which
  is a business rule rather than an adapter detail. A connection counts as a
  sandbox only when *every* required credential is the token.
- `conversion_metrics.attribution_window` is not nullable and defaults to
  `'default'`. PostgreSQL treats `NULL`s as distinct in a unique index, so a
  nullable column there made every re-sync insert a second row — conversions
  doubled on each re-read. Caught by the idempotency test.
- Test memory limit raised to 512M: the architecture tests parse every source
  file, so the suite's need grows with the codebase.

### Documentation

- `docs/DATA_MODEL.md`, `docs/INTEGRATIONS.md` and `docs/ROADMAP.md` updated,
  including an explicit record of why sandbox performance data now exists after
  an earlier decision against it, and what makes it safe.
- `backend/database/fixtures/providers/README.md` documents the fixtures'
  provenance and the single way they depart from a verbatim API response.


## [Unreleased]

### Added — Phase 0: foundation

**Backend**

- Laravel 12 API-only application on PHP 8.4, with the Blade/web scaffolding removed.
- Clean Architecture layers (`Domain`, `Application`, `Infrastructure`, `Interface`)
  plus a composition root, enforced by Pest architecture tests.
- PostgreSQL as the only datastore, with UUIDv7 primary keys on API-facing entities.
- Redis for cache, queue, session and broadcasting; Horizon supervising workers;
  Reverb serving WebSockets.
- `GET /api/health` probing database, cache and queue with a three-valued status
  (`ok`/`degraded`/`down`); 503 only when a dependency is down.
- Security baseline: strict CORS allow-list, Sanctum stateful SPA auth, CSRF, named
  rate limiters (`api`, `health`, `auth`), security headers, stack-disclosure removal,
  validated `X-Request-Id` correlation, secret redaction, production password policy.
- Horizon dashboard access fails closed outside local development.
- Tooling: Pest 4 on PHPUnit 12, PHPStan/Larastan level 8, Pint with strict rules,
  Scramble-generated OpenAPI at `/docs/api`.

**Frontend**

- React 19 + Vite + TypeScript SPA with strict type checking, feature-based structure
  and a lint rule preventing cross-feature deep imports.
- Design system tokens in OKLCH with light and dark themes, bridged into Tailwind v4,
  plus shadcn/ui-style primitives.
- Bilingual shell: Arabic RTL and English LTR with logical layout properties, Arabic
  typography and Latin numerals for figure reconciliation.
- Application shell with rail navigation, a focus-trapped mobile drawer, theme and
  language toggles, skip-to-content and visible focus management.
- Overview page reporting live system health; every unbuilt surface states which phase
  delivers it instead of showing fabricated data.
- Axios client with CSRF priming, locale propagation and a normalised `ApiError`;
  TanStack Query defaults that do not retry definitive 4xx answers.

**Project**

- GitHub Actions CI: backend (Pint, PHPStan, Pest against PostgreSQL and Redis),
  frontend (tsc, ESLint, Prettier, Vitest, build) and end-to-end (Playwright on
  Chromium and mobile Safari).
- `docker-compose.yml` for PostgreSQL and Redis; `Makefile` for setup, dev and verify.
- Documentation set under `docs/`, including six architecture decision records.

### Added — Phase 1 (in progress): identity, workspaces, clients

**Backend**

- Workspace → Client → Project schema with UUIDv7 keys, status check
  constraints and the indexes scoped queries depend on.
- Permission catalogue derived from one enum; six system roles with explicit,
  non-hierarchical permission sets; enforcement in the Application layer behind
  an AuthorizationGuard rather than in controllers or route middleware.
- Sanctum session authentication, workspace switching re-verified against live
  membership on every request, and configurable rate limits with secure
  defaults.
- Clients end to end: search, status filters, pagination, create, edit, domain
  status transitions and soft delete.
- Append-only audit trail covering sign-ins, failed attempts and every client
  mutation, with secrets redacted from recorded diffs.

**Frontend**

- Sign-in page, session gate, and a 401 handler that returns the person to sign
  in from anywhere rather than leaving a shell of failing requests.
- Account menu with workspace switching; switching discards every query cached
  for the previous workspace.
- Permission-aware navigation, and a clients surface with real loading, empty,
  filtered-empty, forbidden and error states, in English and Arabic.

**Projects**

- Full lifecycle with the assignment model: visibility restricted in SQL for
  roles without `projects.view_all`, assignment as the grant that reveals a
  project, and removal as the revocation.
- Domain status transitions with spend permission recorded on every change; a
  project cannot be moved to another client.
- SPA surface that distinguishes "you are not assigned to anything yet" from
  "this workspace has no projects", because they call for different actions.

**Administration**

- Workspace member administration — role changes, suspension, reinstatement and
  removal — with the last active owner protected against demotion, suspension
  and removal.
- Audit log viewer, workspace-scoped, filterable to security events, showing who
  acted and from where.

**Fixed**

- `SESSION_DOMAIN` was set to `localhost`, so no cookie was issued for
  `127.0.0.1` and sign-in silently failed to persist. Found by exercising the
  real browser flow.
- The actor context was not cleared when no active workspace could be resolved,
  so a member suspended or removed mid-session could continue acting on
  permissions they no longer held. The guard now denies an unbound context
  outright.

### Added — Phase 2: integrations foundation

- Seven provider adapters (Meta, Google Ads, TikTok, Snapchat, X, LinkedIn,
  ChatGPT Ads) behind one stable port, each declaring the capabilities it
  actually implements. An architecture test fails the build if an adapter
  claims something its platform cannot do.
- `ProviderConnection → ExternalAccount → ProjectIntegrationBinding`: the
  binding is the only thing that lets a project read an ad account. Connecting
  a platform and discovering its accounts expose nothing by themselves.
- Credentials encrypted with AES-256-GCM before storage, redacted when dumped,
  hidden from serialisation, and never returned by the API — which reports only
  which keys are held.
- `BLOCKED_EXTERNAL` made executable: real credentials produce a 503 naming
  what is missing and stating that the gap is the provider's approval rather
  than the operator's mistake; a platform with no published API produces a 501
  and declares no capabilities at all.
- Sandbox mode exercises the full workflow against clearly labelled `SANDBOX`
  fixtures — account structure only, never a fabricated metric.
- Integrations surface showing every platform's real status, what each can do,
  which credentials are still needed, and per-project access grants, in English
  and Arabic.

### Blocked

- Live ad-platform handshakes are `BLOCKED_EXTERNAL` pending credentials and provider
  approvals; see `docs/INTEGRATIONS.md`.
- Presentation identity awaits the Smart Code reference deck; the theme/token
  abstraction is built first.
