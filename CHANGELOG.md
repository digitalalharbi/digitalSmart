# Changelog

All notable changes to digitalSmart are recorded here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses
[Semantic Versioning](https://semver.org/).

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
