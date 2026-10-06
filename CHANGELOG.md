# Changelog

All notable changes to digitalSmart are recorded here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses
[Semantic Versioning](https://semver.org/).

## Phase 6 — the provider registry

One canonical catalogue of every platform, consumed by both ends. The registry existed in
miniature already — an enum of providers with capabilities and auth methods — and this makes
it the thing the directive asks for: the single source of truth for provider vocabulary.

### Added

- `ProviderCategory` (advertising, analytics, tag management, commerce), `ProviderApiStatus`
  (generally available, requires approval, no public API), `ProviderEntity` and
  `ProviderRateLimit`.
- Four new providers in the registry with connection adapters, honest statuses and sandbox
  accounts: **Google Analytics 4**, **Google Tag Manager**, **Salla** and **Zid**. Their
  sync adapters come in their own phases; the registry entries are what make them appear
  with a status somebody can act on.
- Every registry field is published on `GET /api/integrations/providers`, and the
  integrations page now groups providers by category — eleven cards in one grid is a list to
  scan rather than a page to read.

### Decisions worth recording

- **The registry and the connection state travel on one response.** Splitting them would
  mean every page needing a provider's *name* had to join two endpoints, which is how a
  second list of platform labels gets written — the drift this registry exists to prevent.
- **`api_status` is separate from `blocked_external`.** The second says a workspace cannot
  proceed; the first says why — a programme to be admitted to, an app to register, or no
  public API at all. Those need different actions from different people, and rendering all
  three as "not connected" sends somebody looking for a setting that cannot help them.
- **Capabilities that span categories declare no category.** Discovering accounts, reading
  reporting figures and receiving webhooks are things an ad platform, an analytics property
  and a store all do. Filing them under a convenient one would have made the registry's own
  consistency check meaningless — and that check is what stops a store claiming it can
  report campaign metrics.
- **GA4 and Tag Manager declare no write capability at all**, though both publish write
  APIs. Changing somebody's measurement setup from a reporting tool is a hazard, not a
  feature, and the registry is where that decision is visible rather than implicit in what
  we happened not to build.
- **An undocumented rate limit is null, never a guess.** Tag Manager's is published and
  surprising — 0.25 QPS and 10,000 requests a day, both per *Google Cloud project*, so every
  workspace on one deployment shares it, and exceeding it answers 403 rather than 429. Salla
  and Zid publish nothing, so they say so and back off on the provider's own throttling.
- `ProviderCategory::totalsWith()` encodes the rule the whole taxonomy is for: figures never
  total across categories, because an ad platform's claimed revenue, an analytics property's
  recorded revenue and a store's invoiced revenue are three different numbers.

### Tests

Nine registry tests, including that a provider claiming an API must have documentation and
entities to go with it, that the read-only integrations declare no writes, and that no
provider borrows another category's capability. The existing architecture test caught the
four new providers before any of this ran — it asserts every provider in the enum has an
adapter, which is what it was written for. Backend 676 passed, frontend 173.

## Phase 5 — budgets, pacing and forecasting

Completes Phase 5. The governance hierarchy from CLAUDE.md, the arithmetic a client's
money is judged by, and the two surfaces that make it usable.

### Added

- `budgets` and `budget_allocations`: an approved client budget split across platforms,
  campaigns and ad groups as a self-referencing tree. Five CHECK constraints carry the
  rules, including that a budget marked approved cannot exist without the name of whoever
  approved it.
- `budgets.view`, `budgets.manage` and `budgets.approve`. The third is separate and
  **media buyers do not have it**: drafting a plan and committing a client to a figure are
  different acts, and that separation is the only thing making "approved" mean anything.
  Client viewers have none of the three — what a client may see is decided by explicit
  sharing in Phase 7, not by a role that happens to open a screen.
- `Pacing`, pure arithmetic over four facts: the budget, the period, actual spend and
  today. Seven statuses, because "over budget" and "spending too fast" are different
  problems with different answers.
- Pacing, the alert list and forecast rates are **derived on read**, never stored. A
  stored pacing figure is wrong the moment the next sync lands, and a stale alert is worse
  than none because it teaches people to ignore the list.
- A forecast in both directions — budget → results and target → budget — from the
  workspace's own delivery.
- The immutable change log the spec asks for, written to the existing audit trail rather
  than a second history table. Approving a budget is a **security event**: committing a
  client's money has to be as findable as a credential change.

### Decisions worth recording

- **An approved budget's amount, period and currency cannot be edited.** A figure that
  can be quietly revised says nothing about what a client agreed to. Changing one means
  archiving it and approving a new one, which leaves both on the record; allocations stay
  editable, which is how a plan adapts without the commitment moving.
- **Allocations may sum to less than the budget, never more.** The unallocated remainder
  is reported at every level: it is a decision nobody has made yet, and seeing it is how
  someone remembers to make it.
- **Pacing is even by definition**, with the 10% tolerance published alongside the figure.
  A weighted pace is a forecast wearing a plan's clothes, and a reader comparing today's
  spend against "expected" has to know exactly what expected means.
- **Spend is read at campaign level only.** `daily_metrics` holds the same spend three
  times, once per level; adding them would roughly triple it, and the result would look
  entirely plausible next to a budget.
- **No industry benchmarks.** Forecast rates come from the workspace's own observed
  delivery or they do not come at all. A platform with fewer than three days of delivery
  gets a reason instead of a number; bands are the 25th–75th percentile of the account's
  own daily rate rather than a percentage chosen to look like a range; headline rates are
  weighted (total spend over total clicks) rather than an average of daily rates, which
  would let a quiet day with two clicks count as much as a busy one.
- **Every projection is labelled `estimated`**, carries its assumptions in words, names
  what it could not work out, and publishes a version string identifying the sample — so a
  figure quoted in a meeting can be reproduced, and a different version means the numbers
  have moved. The disclaimer is in the payload rather than left to the interface to
  remember.

### Refusals, each of which would otherwise produce a plausible screen

- A **draft** is not paced: a variance from a figure nobody agreed to means nothing.
- Spend that **cannot be totalled** — two currencies, a currency the budget is not in, or
  sources that cannot combine — yields no total *and no pacing*, with the reason. Pacing
  against a partial figure would understate spend, which is the one direction that
  overcharges a client without anybody noticing.
- An exchange rate is **never invented** to reconcile a budget with spend in another
  currency.
- An allocation planned before its campaign exists has nothing to compare with, and says
  so rather than showing a zero.
- A budget whose spend could not be totalled still appears in the alert list, with its
  reason. Dropping it would make the list look reassuringly short.

### The two surfaces

- A **Media Planner** holding the budget list, the draft form, approve and archive, the
  allocation editor and the pacing panel on one screen, because those are one
  conversation. A planner looking at an overspending platform wants to move money now.
- The **allocation editor** shows the remainder at every level and does not auto-fill it:
  spreading the leftover would be a media decision made on somebody's behalf and called a
  convenience. Over-allocation warns here and is refused by the server — the warning is
  the fast feedback, the refusal is the rule.
- Platform choices come from the **provider registry**, filtered to providers whose API
  can report metrics. No page keeps its own list of platform names any more; the labels
  that were briefly hard-coded here are gone.
- A **pacing chart** answering one question: is this budget going to land where it was
  meant to? Cumulative spend against the even pace, with the approved budget as a line.
  The actual series stops at the last reported day rather than running flat to today,
  because a flat cumulative line means "spent nothing since" and a missing sync means "we
  do not know".
- A **Budget Calculator** in both directions, showing each figure's band, its confidence,
  the assumptions in words and the sample version — and a reason instead of a number where
  a platform has too little delivery.
- `Choice`/`ChoiceGroup` promoted out of the dashboard widget editor into shared UI, so
  the planner, the calculator and the builder share one labelled-toggle pattern rather
  than three copies.

### Tests

20 domain tests on the pacing arithmetic alone, including that a budget nobody has set is
reported as *unknown* rather than "on track"; 58 backend feature tests covering approval,
visibility, the allocation tree, pacing against really-synced spend and the forecast's
refusals; 30 frontend tests including that a role which may plan is not offered approval,
and that an untotallable spend shows its reason rather than a zero; 9 Playwright specs.
Backend 668 passed, frontend 173, E2E 136, PHPStan level 8, Pint, ESLint and Prettier
clean.

## LinkedIn sync

Sixth and last provider with a published contract. Field names, enum values and
response shapes were read from LinkedIn's reference, which is the best documented of the
six — full field tables with complete enumerations.

### Added

- `LinkedInMapper` and `LinkedInSyncAdapter` for the versioned `/rest/` API, with 28
  mapper tests and 12 sync tests.
- `_date_objects`, a fixture-loader directive that writes a date as
  `{year, month, day}` integers. LinkedIn sends no date string anywhere in a reporting
  response, so without it either the fixture or the mapper would have to use a shape no
  live row has.

### The hazard, recorded in three places

**A LinkedIn "campaign" is an ad set.** Its hierarchy is account → campaignGroup →
campaign → creative, and the *campaign* is what carries targeting, a bid, a budget and
an objective. So a campaign group lands at our campaign level, a campaign at our ad
group level, and a creative at our ad level. Get it wrong and every roll-up in the
product is off by one level, which is why it is stated in the adapter, the mapper,
docs/INTEGRATIONS.md and the fixture README rather than left to be deduced — and why the
sync test asserts the whole chain creative → campaign → campaign group.

### Conventions handled

- Money is a **decimal string inside an object** (`{"amount": "180000.00",
  "currencyCode": "SAR"}`), and analytics money is a bare decimal string that may carry
  more than two decimals (`"19.91833"`). Rounded half-up at the minor unit, as their
  billing does.
- **Schedules are epoch milliseconds**; read as seconds a 2025 date becomes the year
  57560.
- Ids are URNs in some places and longs in others, so everything is reduced to the
  trailing id.
- **`servingStatuses` decides delivery, not `status`.** `RUNNABLE` is its only serving
  value, so an ACTIVE group that has spent its budget is reported as not delivering with
  `CAMPAIGN_GROUP_TOTAL_BUDGET_HOLD` carried through untranslated.
- `CANCELED` maps to *removed*, not *completed*: it is permanent and not
  re-activatable, and "completed" would say it ran its course.
- A creative has no format field — the ad format is on the campaign — and often no name
  either, so it is labelled by the post it sponsors.
- A creative names only its campaign, so the campaign group is resolved from the
  campaign already synced: the third provider running whose ad level needs that.
- `approximateMemberReach` is deduplicated members and only returned for ranges of 92
  days or less, which is why the insight window is 92. A parent's reach is below the sum
  of its children's, and the fixtures encode that so a test cannot pass by summing the
  wrong level.
- `conversionValueInLocalCurrency` is one total rather than a figure per type, so it is
  the row's revenue and no breakdown row carries a value. LinkedIn does publish
  conversion worth, unlike X.
- `JOB_APPLICANTS` is filed as *other*, not *leads*: a job applicant is not a sales
  lead, and hiring spend in a conversion chart a client reads as pipeline would mislead.

### Not read

The post a creative sponsors — where its headline, image and link live — and the revenue
attribution finder, which needs a CRM connected to Business Manager. LinkedIn also warns
that its reach and demographic figures differ slightly between reporting levels because
they are approximate; nothing here reconciles that away.

## X Ads sync

### Added

- `XAdsMapper` and `XAdsSyncAdapter` for X Ads API v12, with field names, metric names
  and response shapes read from X's published reference. 32 mapper tests and 15 sync
  tests.
- Fixtures in X's real stats shape, including a campaign that reports nothing at all
  and a line item whose metrics X omits entirely rather than zeroing.

### Conventions handled

X is the first provider here that is shaped differently rather than merely spelled
differently:

- **Performance is a time series.** One row holds one array per metric, aligned to the
  requested range — so a row is a range, not a day, and bucket *i* is the start plus
  *i*. The adapter contract now returns a *list* of records per row for this reason; a
  mapper reading only the first bucket would silently discard every other day.
- **The objective is on the line item**, so an X campaign carries none at all rather
  than one borrowed from a child.
- **Reach is a separate endpoint**, so every X row reports no reach rather than
  impressions standing in for people.
- **Auth is OAuth 1.0a** — the only provider here that is not OAuth 2.0, and the only
  one with no refresh token to rotate.
- A promoted tweet carries no campaign id and no name: the campaign is resolved through
  the line item, and the row is labelled by its tweet.
- A card is assembled from components (MEDIA, SWIPEABLE_MEDIA, DETAILS, BUTTON) and X
  derives `card_type` from them, so the medium, headline, button and destination are
  read from the components. A SWIPEABLE_MEDIA component is what makes a carousel.
- A metric with no data arrives as `null`. X documents null and zero as equivalent for
  it; they are not equivalent here, so the null survives.

### Fixed

- **The insight window was clamped instead of split.** Each adapter states how much a
  provider will answer in one request, and the sync shortened the range to fit. X is
  the first provider whose ceiling (7 days) is less than its attribution lookback (28),
  which meant it could never re-read its own attribution window: a purchase attributed
  ten days back would have stayed invisible and the client would have seen a worse ROAS
  than they earned. The range is now asked for in consecutive windows.
- **`CANCELLED_REQUEST` (408) and `LOCK_ACQUISITION_TIMEOUT` (423) were filed as
  unknown**, and so reported to an operator as something to fix. Both are documented X
  conditions that clear on retry, and both are now classified as transient.

### Not verified

`reasons_not_servable` and `effective_status` have no published enumerations — X's
examples show only `PAUSED_BY_ADVERTISER`/`INCOMPLETE` and `UNKNOWN`/`RUNNING`, and the
fixtures use nothing else. Only those two reason codes are interpreted; any other still
yields "not delivering" because `servable: false` says so, and the code reaches the
interface untranslated. Card types beyond the one documented example are read by their
leading token rather than matched against an invented list.

## Snapchat sync

### Added

- `SnapchatMapper` and `SnapchatSyncAdapter`, with field names, enum values and
  response shapes read from Snap's published reference. 27 mapper tests and 11
  sync tests.
- An `unwrap()` hook on the sync adapter base. Snapchat wraps every list item as
  `{sub_request_status, <entity>: {…}}` because a batch can partly succeed, so a
  failed row carries no entity and is dropped rather than mapped into a record with
  an empty id. Unwrapping is a provider concern, not a mapper one.
- The store now resolves an ad's campaign through its ad group when the provider does
  not supply one. Snapchat's ad object carries only `ad_squad_id`; without this the
  sync would silently discard that provider's entire ad level.

### Conventions handled

Snapchat is the fourth distinct set in four providers: money in **micros** (like
Google), a lifetime **spend cap** rather than a lifetime budget, a click called a
**swipe** with no separate link-click metric, reach as `uniques`, quartiles as
**counts** (unlike Google's rates), `bid_micro: 0` meaning auto-bid rather than a
zero bid, and delivery state in a `delivery_status` array because `status` is only
ACTIVE or PAUSED.

### One unverified assumption, recorded rather than hidden

Every money field Snap publishes carries a `_micro` suffix **except** the stats
metric `spend`. It is mapped as micro-currency for consistency with the rest of their
API, which is the likely reading — but it could not be confirmed, and if it is wrong
spend is understated by a factor of 10,000. The fixtures encode the same assumption,
so no test can catch it; only a live response can. Flagged prominently in
`docs/INTEGRATIONS.md` and `docs/ROADMAP.md` as the most consequential open item
across all four providers.

## Phase 4 — the dashboard builder

Completes Phase 4. The foundation commit's schema and ports now have use cases,
repositories, an API and a working builder behind them.

### Added

- Saved views: `/api/analytics/views` with full CRUD. A **preset** is stored rather
  than the dates it resolved to, so a view called "last 28 days" still means that next
  month; an explicit range is stored literally, because a closed month is a legitimate
  thing to save. The query is rebuilt into a real `AnalyticsRequest` on the way in, so
  a view saved today still parses in six months.
- Dashboards: `/api/dashboards` with CRUD plus duplicate. The widget set is replaced
  rather than merged, omitting `widgets` leaves the canvas alone, and `widgets: []`
  empties it — three distinct instructions, all supported.
- A builder UI covering add, remove, reorder, resize, retitle, chart type, metrics,
  grouping, widget-level filter overrides, audience, duplicate and save. Responsive,
  Arabic RTL, dark mode, with loading, empty, error and permission states.
- Grid arithmetic as pure functions (`layout.ts`) with 14 unit tests: every reorder
  and resize re-flows into a valid layout, so an invalid one is prevented rather than
  refused on save.
- 57 backend tests, 32 frontend tests and 11 Playwright specs.

### Decisions worth recording

- **Reordering and resizing are buttons, not drag-and-drop.** Drag-only reordering
  fails WCAG 2.2's dragging criterion, does not work with a keyboard, and is awkward on
  touch. Pointer dragging can be layered on the same model later.
- **Each widget runs its own query** rather than the dashboard running one and slicing
  it. That costs requests and buys the thing that makes a dashboard useful: widgets can
  ask different questions. TanStack Query dedupes identical ones.
- **A duplicate is always private**, whatever the original was. Inheriting a shared
  audience would publish a draft the moment it was created.
- **Visibility is applied in SQL**, not filtered from a response: a private record
  belonging to someone else is never in the result set.

### Fixed

Four real defects, found by the tests rather than by review:

- Clicking "New dashboard" then Save would **overwrite an existing dashboard**. The
  landing effect re-selected one while the draft was open, and the save target was read
  from that selection. The selection is now derived rather than synced into state by an
  effect, which makes the whole class of bug impossible rather than merely fixed — and
  the ESLint rule about setting state in an effect was pointing at exactly this.
- The page read `.description` off a dashboard narrowed only against `undefined`, so a
  query settling with `null` crashed the whole surface instead of showing an empty state.
- A save mutation resolving with nothing threw inside `onSuccess`, turning a successful
  write into an error the reader could not act on.
- **Reusing a name returned a `500` with the raw SQL in it.** Dashboard and saved-view
  names are unique per owner, enforced by an index that nothing asked about first, so
  naming a second dashboard "Weekly review" failed as an insert — and in any
  environment with debug on, the response carried the constraint name, the column
  list, the workspace and user ids and the whole statement. Both use cases now check
  the name and answer `422 name_already_used` naming the offending dashboard. The
  duplicate endpoint is the exception: it chose "(copy)" itself, so it climbs to
  "(copy 2)" rather than failing the caller for a name they never typed.

### Testing

The E2E harness ran the API on PHP's built-in server with a single worker, which
accepts one connection at a time. Two browser projects run at once and every widget
issues its own analytics query, so a sign-in could queue behind several half-second
aggregations until the browser reported the server unreachable — which reads exactly
like an application bug. `scripts/e2e.sh` now forks workers, and the dashboard spec
namespaces its records per project, because both projects share one database and one
demo account.

## Phase 4 — dashboard and saved-view foundation

Schema, domain rules and ports only. The use cases, API and UI are **not** done, so
the feature is not yet reachable from the product — see docs/ROADMAP.md.

### Added

- `saved_views`, `dashboards` and `dashboard_widgets` tables. Two CHECK constraints
  carry rules the application would otherwise have to remember: a project-scoped
  view cannot exist without a project, and a widget cannot be stored outside the
  12-column canvas.
- `Visibility`, with three states rather than a boolean — the third exists because an
  agency's real need is a dashboard one client's team can see and colleagues working
  for a different client cannot.
- `WidgetType`, which declares the shape of question each type can answer, so a
  share-of-total across 90 dates is refused rather than drawn.
- `GridPlacement`, which raises on an impossible placement rather than clamping:
  clamping moves a widget the reader deliberately placed, and an off-grid widget
  vanishes from the dashboard with nothing to say it was there.
- 12 domain tests covering those rules.

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
