# Unified Commerce, GA4, attribution and client reports — requirement matrix

The requirement IDs of the *Unified Commerce Intelligence* brief (2026-10-25), mapped to what the repository actually holds. Where a
row says `REUSED`, the capability exists with a test that fails if it stops existing and is **not** rebuilt; `PARTIAL` and `MISSING`
name the smallest concrete gap. Nothing here is `LIVE_VERIFIED`: no live credential, store, property or production server exists in
this installation (`docs/LIVE_COMMISSIONING.md`, `docs/DEPLOYMENT.md`).

**How this was built.** A read-only static audit of `origin/main` at `98c7f14`: source, routes, and test *names* were read; the test
suites were not re-run for the audit. A row is `REUSED` only when both code and a named test exist. Update a row in the PR that
changes it, with that PR's number and main SHA.

Status words: `REUSED` (exists, tested — leave alone) · `PARTIAL` · `MISSING` · `THIS_PR` (delivered by the PR named) · `NOT_APPLICABLE`
(nothing to build; reason given). External state uses the project vocabulary (`AWAITING_CREDENTIALS`, `BLOCKED_EXTERNAL`,
`AWAITING_SERVER_ACCESS`, `SANDBOX_ONLY`).

## Phase 0 — close the active program

| ID | Status | Evidence |
|---|---|---|
| DS-UC-000 | DONE | SaaS A→H merged (#61–#68), last main SHA `98c7f14`; final state recorded by the docs-only PR #69. No new code claims. |

## Phase 1 — security and integration lifecycle

| ID | Status | Evidence | Smallest gap |
|---|---|---|---|
| DS-UC-101 AI chat isolation | `NOT_APPLICABLE` — nothing is persisted | `Application/Intelligence/Query/Ask.php` returns an answer and keeps only a usage counter; no conversation table exists; question text lives in component state and the query cache is cleared on sign-out and workspace switch. Isolation of what is *computed* is tested: `AssistantTest` ("reports a client it cannot see as not found…", "requires a client rather than averaging…"), `AssistantPage.test.tsx`, `e2e/assistant.spec.ts`. | None. Adjacent: `agent_tool_calls.input` (MCP tool arguments) has no read API and no retention job — see the ledger. |
| DS-UC-102 lifecycle actions | PARTIAL → `THIS_PR` for disconnect | Connect, discover, accounts and bindings, store read, GA4 sync, account backfill, credential replace, disconnect all exist behind `workspaces.update` / `tracking.manage` with audit (see the PR). Disconnect now asks first and says what it leaves alone. | No unified connection detail page; no per-connection retry route; no OAuth reauthorize (live OAuth is `BLOCKED_EXTERNAL`); the per-connection credentials route has no UI. |
| DS-UC-103 safe replace / disconnect | PARTIAL | A disconnected or empty-credential connection is never read. Store reads and GA4 syncs return an honest `failed` ("disconnected"), call no adapter, stamp no attempt and keep stored data (`ConnectionLifecycleTest`, #70). The hourly advertising sync no longer lists, queues or runs accounts on a disconnected connection, so it writes no failed run or provider error per account per hour; `expired` / `error` connections are still synced (`SyncTest` "a disconnected connection", 4 tests; the other workspace's accounts are unaffected). | A run already holding credentials finishes (bounded to that one run); queued store jobs are not cancelled; no lock around replace vs sync; a backfill asked on a disconnected account answers *not found* rather than saying why; HubSpot's manual read (`SyncCrmDeals`) still fails with the provider error rather than a "disconnected" message. |
| DS-UC-104 operational UX | PARTIAL | Per-connection state, `last_checked_at`, `expires_at`, accounts discovered/bound/unassigned, and a workspace-level operations monitor exist (`IntegrationOperationsTest`). | No per-connection sync history endpoint over `sync_runs`; no last-*successful*-sync field (`stores.last_synced_at` records the last *attempt*); no remedy text beyond the state label. |

## Phase 2 — commerce intelligence (on `AnalyticsReadModel` / `EloquentStoreAnalyticsReadModel`)

| ID | Status | Evidence | Smallest gap |
|---|---|---|---|
| DS-UC-201 gross / net ledger | PARTIAL | Revenue counts only paid and partially-refunded orders; refunds, net, refund rate, pending orders and attribution coverage are published (`StoreAnalyticsTest`: "subtracts refunds…", "excludes orders that are not yet paid…"). | `discount`, `tax`, `shipping`, `subtotal` exist on `store_orders` (nullable — Salla's list omits them) but no read-model method sums them and no DTO or screen carries them; no definitions published. |
| DS-UC-202 trends and AOV | PARTIAL | `daily()` and `averageOrderValueMinor()` (null on zero orders) are tested. | Day grouping uses the placed-at date, not the store timezone, although the DTO says "the store's own day boundary"; no weekly/monthly grain; no previous-period comparison. |
| DS-UC-203 products / SKU / category | PARTIAL | `byProduct()` groups on the line name as sold ("reads products from the lines as they were sold"). | Group by `coalesce(sku, name)`; no `category` column exists. |
| DS-UC-204 geography | MISSING | City and country exist on `store_customers` only. | Ship city/region/country on `store_orders`, mapper support (Salla, Zid), `byGeography()` with small-group suppression. |
| DS-UC-205 customers / cohorts | PARTIAL | New / returning / unknown with the 20% withholding rule is tested (`StoreAnalyticsTest`, `CustomerMixTest`, `CustomerMixPanel.test.tsx`). | No named-window cohorts (a product decision, `docs/ECOMMERCE.md`). |
| DS-UC-206 cart, checkout, coupons | MISSING | `store_orders.coupon_code` is used only as weak attribution evidence. | A coupon read model is possible on the existing schema; cart / abandonment need a new table and adapter reads. |
| DS-UC-207 cost and profit | MISSING | No cost, COGS or margin column anywhere. | `cost_minor` on products / variants / order-item snapshot with effective dates; estimated profit labelled apart. |

## Phase 3 — GA4

| ID | Status | Evidence | Smallest gap |
|---|---|---|---|
| DS-UC-301 property scope and health | PARTIAL | Property registration, event mapping, freshness and tracking health exist (`GoogleAnalyticsSyncTest`, `TrackingHealthTest`). The adapter reads **fixtures only**; any non-sandbox credential is refused (`BLOCKED_EXTERNAL`). | Live OAuth and Data API transport. |
| DS-UC-302 aggregated reports | MISSING | Only `date × eventName` counts and one value metric are requested; sampling, thresholding, truncation and `(other)` handling is solid (`ReportQuality`, `GoogleAnalyticsMapperTest`). | A second report: sessions, engaged sessions, users by source/medium, landing page, device, geography, behind the same `ReportQuality`. |
| DS-UC-303 commerce journey | MISSING | `view_item`, `add_to_cart`, `begin_checkout`, `purchase` are mappable names; nothing computes a funnel. | E-commerce funnel over exact event counts only; never user-level paths, never GA4 revenue added to store revenue. |
| DS-UC-304 diagnostics | PARTIAL | Ten-plus tracking issue codes incl. GTM container checks. | UTM consistency, spike detection, quota (`returnPropertyQuota`) and 429 handling. |

## Phase 4 — attribution

| ID | Status | Evidence | Smallest gap |
|---|---|---|---|
| DS-UC-401 four authorities | PARTIAL | `RevenueSource` keeps platform, GA4, Salla, Zid, CRM, manual and attributed apart; Analytics shows "Revenue by who says so". Attribution takes one required `revenue_source` per call. | A reconciliation read: store, platform, GA4 and tracking-evidenced revenue for one period with deltas. |
| DS-UC-402 evidence graph | PARTIAL | `tracking_sessions` / `tracking_events` hold UTMs, click ids, consent and a dedupe key. `EloquentAttributionReadModel` does not read them. | Join tracking evidence to orders by click id / touchpoint. |
| DS-UC-403 configurations | MISSING | Model, window and limit are per-request parameters. | A stored per-workspace/client configuration with versioning. |
| DS-UC-404 model comparison | `REUSED` | `last_recorded`, `first_recorded`, `paid_last_click` computed; linear, position-based and time-decay refused with a 422 naming what would unblock them (`AttributionTest`, `Unit/Domain/AttributionTest`, `AttributionPage.test.tsx`, `e2e/attribution.spec.ts`). | None — they stay refused until ordered, consented touchpoints exist. |
| DS-UC-405 quality dashboard | PARTIAL | Coverage, unattributed revenue, weakest evidence, unmatched spend and ROAS refusals are tested. | Dedupe rate, missing-click-id rate, unmatched-order list; show source / currency / evidence on each ROAS cell. |

## Phase 5 — client reports (on the existing report engine)

| ID | Status | Evidence | Smallest gap |
|---|---|---|---|
| DS-UC-501 ten sections | PARTIAL | Paid advertising, store, funnel, attribution (internal only), creative, lead and tracking sections exist and are tested. | No GA4 section; no budget/pacing section; no category/geography; no server-side observations; executive overview is a preset, not a kind. |
| DS-UC-502 executive vs detailed | MISSING | Presets are client-side section lists; the definition has no intent. | A stored `intent` that changes content, with a test. |
| DS-UC-503 interactions | PARTIAL | Chart/table twin, period and comparison, shared-link filters. | `hidden` flag and per-section scope; replace-wholesale save today. |
| DS-UC-504 share lifecycle | PARTIAL | Hashed, expiring (≤ 90 days), revocable with a reason, narrow-only filters, internal ids stripped (`SharedReportTest`, `SharedFilterTest`, `SharedPayloadIdsTest`, `e2e/shared-filters.spec.ts`). | Pause / resume (reversible, never widening); one-step replace. |
| DS-UC-505 branding hierarchy | MISSING | `white_label` is an entitlement only; the report hero uses initials. | Workspace and client logo store, rendered in the report, entitlement-gated. |
| DS-UC-506 export parity | PARTIAL | CSV and XLSX from the same `GetReport` payload; PPTX deck. **No report PDF.** | A report print/PDF path and one test asserting the same figures across payload, CSV and XLSX. |
| DS-UC-507 template versioning | PARTIAL | Audit entries exist for sharing; snapshots are stored output. | Saved templates, version history, section-change audit. |

## Phase 6 — quality and readiness

| ID | Status | Evidence |
|---|---|---|
| DS-UC-601 isolation / adversarial | `REUSED` | `TenantRouteMatrixTest`, `TenantSchemaTest`, `TenantStorageTest`, `GlobalSurfaceIsolationTest`, `SharedFilterTest`, `SharedPayloadIdsTest`. Gap: no cross-format export-parity adversarial test; row-level security designed, not enabled (ADR 7). |
| DS-UC-602 production truth | HONEST | `AWAITING_SERVER_ACCESS`; a green *Deploy Production* run before a server exists is the workflow reporting that, not a deployment. |
| DS-UC-603 measured journey | `BLOCKED_EXTERNAL` | Needs a real approved source, a real store/property/account and a production server. |

## Owner-defects ledger (found by the audit; each is a documentation or test gap, none a data leak)

| # | Finding | State |
|---|---|---|
| 1 | `docs/CLOSURE.md` said report PDF exports were verified in both browsers; reports have no PDF. | Corrected in this PR. |
| 2 | `docs/ATTRIBUTION.md` / `AttributionRequirement::TouchPath` say no touch sequence exists, but `tracking_sessions` / `tracking_events` now do; they are simply not joined to orders. | Open — state it when DS-UC-402 is built. |
| 3 | `docs/TRACKING_FUNNELS.md` lists parts C and F as not started; their code and tests are merged. | Open. |
| 4 | The root `RESUME_STATE.md` is stale (an old gate count and a "closure" phase). `docs/RESUME_STATE.md` is the maintained one. | Open. |
| 5 | `agent_tool_calls.input` (MCP tool arguments) has no read API and no retention job. | Open. |
| 6 | GA4 sync (`SyncMeasurementSource`) writes no audit entry; the reconnect route's UI is absent. | Open (DS-UC-102). |
| 7 | Store analytics groups days by placed-at date while its DTO says "the store's own day boundary". | Open (DS-UC-202). |
| 8 | A disconnected connection was still read, answering "not configured" and stamping an attempt. | Fixed for store reads and GA4 (#70). |
| 9 | The hourly advertising sync queued a job per account on a disconnected connection, each ending in a failed run and a provider error. | Fixed in the PR that follows #70. |
