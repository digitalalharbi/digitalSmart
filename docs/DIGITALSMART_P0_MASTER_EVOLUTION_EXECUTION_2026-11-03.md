# digitalSmart — التوجيه التنفيذي الشامل لتطوير منصة إدارة الإعلانات والتسويق

**Document ID:** `DS-P0-MASTER-2026-11-03`  
**Version:** 1.0 — 2026-11-03  
**Target repository:** `digitalalharbi/digitalSmart` **ONLY**  
**Priority:** P0 for security, GA4, reporting/shares, authentication, operational integrity; P1 for advanced enhancements where explicitly named  
**Status:** REQUIREMENTS + EXECUTION DIRECTIVE — **NOT evidence of implementation**  
**Execution audience:** Claude Code / engineering agent working in the **digitalSmart** repository  
**Purpose:** adopt the most valuable *product behaviors* demonstrated or specified in CampaignsHub and «مهتم», while keeping the digitalSmart product, codebase, identities, credentials, URLs, infrastructure, and data **fully independent**.

> **OWNER'S MANDATE:** Build a professional, fast, secure, multilingual advertising operations SaaS. Prioritize GA4 and commerce analytics, interactive and reusable reports/links, reliable sign-in, automations, intelligent agents, notifications, and real usage—not documentation or CI green alone. Apply every *applicable* requirement to digitalSmart, record proof, and execute normal engineering decisions autonomously. Never claim 100% without completed acceptance evidence.

---

## 0. Source material, priority, and hard project boundary

### 0.1 Owner-supplied conceptual references

Read **both** reference documents entirely as product and acceptance guidance:

- `CampaignsHub_P0_Complete_Requirements_Execution_2026-11-03_UPDATED_EMAIL.md`
- `Mohtam_Master_P0_Product_Evolution_Execution_2026-11-03.md`
- `DIGITALSMART_FULL_HANDOFF.md` (historical handoff; Git always wins on implementation state).

**They are not instructions to edit their original products.** Never use their domains, OAuth client IDs, account IDs, cookies, API credentials, email identities, user records, production tokens, Git remotes, database objects, Redis keys, report URLs, or other tenant data in digitalSmart. Do not send any requests to their production endpoints as part of this work. **This master directive is self-contained for execution:** if either owner reference file is not physically present in the agent worktree, do not block implementation or substitute a similarly named file; use the translated acceptance criteria herein, report the missing reference for provenance, and continue every independently executable item. If the owner later supplies the reference, reconcile it additively into the same canonical matrix.

### 0.2 Binding digitalSmart references

At the beginning of work read and inspect the **actual current** repository:

`CLAUDE.md`, `README.md`, `docs/RESUME_STATE.md`, `docs/ARCHITECTURE.md`, `docs/SAAS.md`, `docs/TENANCY.md`, `docs/SECURITY.md`, `docs/GOOGLE_ANALYTICS.md`, `docs/MEASUREMENT.md`, `docs/GOOGLE_TAG_MANAGER.md`, `docs/ECOMMERCE.md`, `docs/UNIFIED_COMMERCE_MATRIX.md`, `docs/REPORTING.md`, `docs/CLIENT_REPORTS.md`, `docs/TRACKING.md`, `docs/TRACKING_FUNNELS.md`, `docs/ATTRIBUTION.md`, `docs/AGENTS.md`, `docs/INTELLIGENCE.md`, `docs/INTEGRATIONS.md`, `docs/PROVIDER_READINESS.md`, `docs/LIVE_COMMISSIONING.md`, `docs/DEPLOYMENT.md`, `docs/PRODUCTION.md`, `docs/PLATFORM.md`, `docs/ENTITLEMENTS.md`, `docs/OPERATIONS.md`, `docs/TESTING.md`, `docs/DESIGN_SYSTEM.md` and current migrations, routes, jobs, tests, PRs, main SHA and CI. If a document is missing, record that fact and proceed with repository evidence. Never use a stale status document to overwrite a newer merged feature.

### 0.3 Verified read-only GitHub baseline **at document preparation**, NOT a live promise

- `main`: `62e5688607fd95c95b4ff9d14a69f8a7ce60d5ad` (#72 — UTC provider instants), main CI **success**.
- #70, #71, #72 merged. #75 head `340caca6fb70b0174dd94efb3b470d1104ea3926` remained **open**, its CI **success**. #74 head `340fba69ad64174f10e84f67a30658fa5ed20b78` remained **open**, earlier CI **success** but branch **behind**.
- GitHub workflow labeled “Deploy Production” **does not prove a deployment**; production deployment job was skipped because no server was configured. State `AWAITING_SERVER_ACCESS` unless newer verified evidence supersedes it.
- Earlier Claude Code report: local sandbox owner sign-in and dashboard proven in Playwright, local Redis broadcast backlog drained with Horizon/Reverb. This is *local evidence*, not evidence of public staging, production or live provider connections.
- A–H SaaS delivery, seven agents, automation rules, report snapshots and hashed share tokens, first-party tracking, commerce records, existing mobile tab bar, notifications and digest scaffolding already exist in this repository. **Reuse and strengthen; do not clone**.
- The existing `docs/UNIFIED_COMMERCE_MATRIX.md` was written against an older main snapshot and contains stale rows for #71/#72. Reconcile it against **merged source**, including #75 when merged, rather than assuming historical `MISSING` remains missing.

**MANDATORY:** `git fetch origin` and re-evaluate all above on the execution date. Do not blindly merge an unrelated/open PR or change production just because this document names it.

### 0.4 Conflict resolution and strict scope

Precedence: **system/repository safety & legal boundaries → current main code/migrations/tests → current digitalSmart project decisions and docs → this owner's new explicit requirements → conceptual reference products**. This file adds product outcomes; it does not override an architectural or security control without a reasoned, documented migration.

- **Do not transfer code** from other repositories. Implement with independent digitalSmart source.
- **Do not transfer data/credentials**: no tokens, contacts, sample reports, authentication provider settings, Google Sheets files, webhook secrets, real lead records, tenant information or brand identities from either reference product.
- Preserve existing application identity `digitalSmart` **until an explicit final naming decision**; do not rename based on the naming brainstorm or borrow `CampaignsHub`/`Mohtam` branding. Make mail subjects and titles use the **current configured digitalSmart brand**.
- The exact CampaignsHub shared URL in the reference is **not** a digitalSmart asset and must never be mutated/visited as part of digitalSmart implementation.
- For every desirable feature, first identify digitalSmart's existing service/query/repository/frontend component, then extend it. If absent, create **one** domain-owned implementation, not a second engine.

---

## 1. Target outcome and definition of success

### 1.1 What the product must answer

A permitted agency operator must see, in one coherent workspace:

1. What was spent, where, against which client/project/platform/account/campaign/ad set/ad/creative?
2. Which campaigns generate impressions, clicks, engagements, leads, sessions, add-to-carts, checkouts and confirmed orders—each from a **clearly identified source**?
3. Which channels, UTM combinations, landing pages, tracked links and influencers show measured outcomes, and where does the evidence stop?
4. What is confirmed gross/net store revenue, refunds, discounts, shipping, tax, items, average order value, product mix and repeat customer behavior, subject to data availability?
5. Why do Google Analytics 4, ad platforms and Salla/Zid disagree, without summing duplicate revenue or claiming false attribution?
6. Can the agency prepare a concise, branded, permission-safe client report, share and **edit the same live URL**, pause/resume it, make an immutable snapshot and export it without changing the figures?
7. Can a user log in once to the correct workspace/portal and use the same app on desktop/mobile without stale sessions, navigation fragmentation or unauthorized data?
8. Can rules and agents alert, explain, recommend and safely execute *approved* actions, with unambiguous evidence and a complete action history?
9. Can notifications/digests and scheduled reports actually reach their recipients, with a truthful delivery state, deduplication and recipient preferences?
10. Can the owner administer feature visibility, workspaces, permissions, report sharing, automation and operation readiness without silent privilege bypasses?

### 1.2 Mandatory separation of evidence levels

- `NOT_STARTED`, `IN_PROGRESS`, `PARTIAL`, `IMPLEMENTED_NOT_VERIFIED`, `VERIFIED`, `BLOCKED_EXTERNAL_CREDENTIALS`, `BLOCKED_OPERATIONAL_EVIDENCE` for **new requirement acceptance**.
- Map existing digitalSmart operational terms (`SANDBOX_VERIFIED`, `AWAITING_CREDENTIALS`, `READ_ONLY_VERIFIED`, `LIVE_READ_VERIFIED`, `LIVE_WRITE_VERIFIED`, `AWAITING_SERVER_ACCESS`) into the report without silently changing their definitions.
- `VERIFIED` for real production features means **merged + deployed + authorized production browser/API/data evidence**. `IMPLEMENTED_NOT_VERIFIED` when only local/CI/staging evidence exists. `LIVE_READ_VERIFIED` requires a real provider round-trip; sandbox never qualifies.
- A feature cannot be labeled `100%` when any applicable acceptance row remains missing, partial or externally blocked. Report counts, evidence and precise blockers.

---

## 2. Traceability, audit and autonomous delivery process — DS-P0-000

### 2.1 Single source of requirement tracking

Use and extend `docs/UNIFIED_COMMERCE_MATRIX.md` for `DS-UC-*` items; attach cross-module `DS-P0-*` rows to the **existing canonical requirement/acceptance tracking approach** (or introduce one clearly linked canonical extension if none exists). **Never maintain two contradictory matrices**. Fix stale `docs/RESUME_STATE.md` and related docs only as an evidence-based change.

Each atomic criterion gets:

`ID | original requirement | reference inspiration | digitalSmart module/route | existing implementation evidence | exact gap | source of truth | security/RBAC | acceptance scenario | automated test | PR + SHA | deployed SHA | real evidence | state | remaining blocker`.

Group each high-level ID into granular children (e.g. DS-P0-GA4-01a) **without losing any relevant item** from either supplied reference. If an item is deliberately out of scope (e.g., Mohtam-specific real-estate reservation workflow), record **NOT_APPLICABLE + clear reason**; it is not quietly deleted.

### 2.2 Delivery loop

`Audit current main/PRs → confirm defect → fail-first regression test where feasible → minimal domain-level fix → security/tenancy tests → type/lint/unit/integration → browser E2E → PR → CI → merge in dependency order → staging deploy when available → live/production acceptance when authorized → update matrix → next PR.`

- One branch/worktree per independent slice; adhere to `CLAUDE.md` merge policy. Never overwrite uncommitted work or force-push shared branches.
- Do not “wait for CI” as a substitute for progress on independent slices, but never merge around required checks or branch rules.
- Keep all external decisions genuinely blocked on valid permission; do not require the owner for routine engineering, UI copy, tests or code design.
- For destructive migrations, production security or account changes, chargeable external actions, sending messages to real clients, enabling ads, paid OTP or campaigns, obtain explicit relevant authorization. No permission bypass through “autonomous” wording.
- Daily completion note: exact work, tested evidence, PR/SHA, deployed SHA if any, broken/blocked items and next executable item.

---

## 3. P0 GA4 — live connection, traffic and commerce intelligence — DS-P0-GA4

### 3.1 Integrate using existing digitalSmart measurement code

Reuse `DiscoverAnalyticsProperties`, `SyncMeasurementSource`, existing GoogleAnalytics adapter/mapper, tracking health, analytics read models and client/project bindings. Current repo documentation says the adapter supports sandbox response-shaped fixtures but **refuses real credentials**; this is a material product gap, not proof of a working GA4 live integration. Implement actual **read-only** Google Analytics Admin API/Data API transport with documented OAuth scopes after permission/credential availability.

**Required integration flow:**

1. From a workspace/client/project's **Integrations**, choose Google → OAuth start → consent → secure callback → property account discovery → choose permitted property → bind project → verify property timezone/currency and metadata → initial bounded sync → regular sync → show freshness/errors/coverage.
2. State/nonce/PKCE as appropriate, callback URI allowlisting, server-side code exchange, encrypted refresh/access tokens, refresh locking, rotation-safe storage, no token in logs; maintain provider permissions distinct from digitalSmart sign-in.
3. Validate workspace membership and project scope at every registration, discovery, API read, sync job, cache fill and disconnect. Prevent connecting a property owned by another client's workspace through tampered identifiers.
4. Support multiple properties where legitimately bound, a property change history, reconnect/reauthorize, disconnect/disable without deleting historical snapshots or metrics. Report per-property last **attempt** vs last **success**.
5. Use GA4 `/metadata` to detect available metric/dimension names and report compatibility per property/version. Never assume the schema or silently return zero for unsupported/thresholded values.
6. Handle cursor/offset paging, quota reporting, token refresh, 401/403 vs 429/5xx differences, exponential backoff with jitter, request budgets, sampling/thresholding/truncation/`(other)` metadata. Label incomplete results `PARTIAL` and surface detailed cause.
7. GA4 is **read-only**: no silent modification of Google Tag Manager, property events or key-event settings. Keep Google Ads, GA4 and GTM permissions and transports separate.
8. When real OAuth client/scopes/property aren't granted: implement, test and present `BLOCKED_EXTERNAL_CREDENTIALS` with exact required steps; do not fabricate a connection.

### 3.2 Exact KPI vocabulary and meanings

Build a central typed GA4 metric dictionary (property metadata permitting):

- **Audience/engagement:** `totalUsers`/users, `newUsers`, `activeUsers`, `sessions`, `engagedSessions`, `engagementRate`, `bounceRate`, `userEngagementDuration` and derived average engagement when denominators exist.
- **Pages and acquisition:** `screenPageViews`/page views, landing pages, page path, session default channel group, session source/medium, first-user source/medium, paid/organic/direct/referrals, campaign if GA4 supplies it.
- **Ecommerce:** view_item, add_to_cart, begin_checkout, purchase/key events, item views, purchases, cart/checkout stages, `purchaseRevenue` where allowed, items purchased/items per transaction when GA4 dimensions actually support them.
- **Calculated:** session-to-purchase conversion only with compatible denominators and a clearly defined window; AOV as revenue/orders from the **same** GA4 authority; revenue per session/user only where dimension and scope are comparable.
- **Per KPI:** raw server field, localized label, definition, numerator/denominator if derived, value and unit, precision, timezone, source, date interval, update/freshness timestamp, completeness, previous-period value, change (null if invalid), availability reason.

**Never:** conflate users with sessions; treat an event count as a unique-user funnel; infer a purchase from a WhatsApp click; sum purchases across GA4, ads and the store; display `0` where the upstream API did not report a metric.

### 3.3 GA4 dashboard and drill-down

New/extended GA4 area inside **Analytics** and optionally project-level dashboards, connected through the existing analytics read-model conventions:

- Top KPI strip with period comparison, trend, quality, freshness and currency.
- Trend charts with day/week/month grain, aligned to the property's timezone, partial-period indicators, comparison and safe gap handling.
- Source → medium → campaign/UTM → landing page → event/commerce funnel drill-down; optional device/geography breakdowns only with supported aggregated data and privacy thresholds.
- Channel/source/medium and UTM table, 10 rows by default, 10/25/50 true server-side pagination when needed, sorting, search, compact chips, sticky headings, column preferences and accessible detail drawer.
- Funnel visualization from aggregate stage counts. Explain lack of user-journey certainty where only aggregate counts are supplied.
- Explicit failure, no data, insufficient scope, unavailable metric, sampled/thresholded, stale and demo states.
- RTL/LTR, Arabic/English, mobile, Dark/Light, keyboard/touch accessibility, chart/table twin views and safe CSV/XLSX export.

### 3.4 GA4 attribution and tracking reconciliations

Use existing tracking evidence (`tracking_links`, touchpoints, UTM, click IDs and consent) and existing `AttributionReadModel`; never build an unsound parallel attribution database.

- Compare GA4 session counts, tracked-link clicks, platform clicks and landing views as **separate** measures with dropoff explanations.
- Source and medium must clearly distinguish session-scoped from first-user-scoped attribution. Preserve the real dimension name in metadata.
- Consider filters: client, project, linked account, property, campaign, platform, source/medium, UTM, landing page, date, currency, timezone and data quality.
- No unauthorized cross-client joins. No unsupported person-level modeling from anonymous aggregate data.
- Consent/retention rules and GA4 API usage conditions apply; avoid unnecessary PII, full querystrings or cross-device identity assumptions.

### 3.5 GA4 acceptance gates

- [ ] Secure live Google OAuth round trip for an authorized test property when credentials permit; verify property metadata, time zone, scopes, sync and visual KPIs.
- [ ] `401 expired token → refresh/retry once`, `403 missing property access`, `429 backoff`, `5xx transient`, partial paginated response, sampling, thresholding and unmapped metric tests.
- [ ] Malicious property/workspace/project swaps return 403/404 with no leaked numeric rows.
- [ ] Identical GA4 numbers in analytics, Live report, Snapshot, safe export and digest for identical source/time/filters.
- [ ] Arabic/English, mobile/desktop, Browser E2E; no infinite loading; non-demo figures never masquerade as live absent provider proof.

---

## 4. P0 commerce: Salla/Zid + unified source-of-truth reports — DS-P0-COM

Reuse commerce order sync, `EloquentStoreAnalyticsReadModel`, revenue ledger, store rollup, customer mix and schema; account for #71/#72 and latest #75 source state before coding.

1. **Real store authority:** paid/confirmed order count, cancelled/returned/refunded counts, gross (provider total), net after refunds, subtotal/discount/tax/shipping where **actually reported**, reconciliation coverage and discrepancies. Keep nullable components null—not zero. Document tax-inclusive/exclusive uncertainty.
2. **Time semantics:** provider-sourced instants UTC at rest; day/week/month buckets in each store's IANA timezone; cross-period comparisons respect exact local calendar boundaries, partial buckets and historical data uncertainty (no guessed backfill).
3. **Products:** product and variant/SKU, category if upstream delivers it, ordered quantity, sold amount, best/weak products, contribution cautions (item totals may not equal order totals because of discounts/shipping/tax).
4. **Customers:** new vs returning vs unknown, repeat behavior and cohorts only where identity/data accuracy and privacy thresholds allow; suppress small groups.
5. **Funnel and checkout:** separate GA4 and store observations. Cart/abandonment rates only with a real provider/measurement source, meaningful event definitions and denominators; unavailable stages explicitly marked.
6. **Coupons/influencers:** promo codes tied to actual orders and allowed campaign mapping; clicks/mentions do not prove sale or influence causality.
7. **Profit:** never claim margin/ROI without cost-of-goods, shipping, ad cost and a declared methodology. Estimated vs actual must be explicit.
8. **Comparison:** ad reported revenue vs GA4 purchase revenue vs store confirmed revenue vs tracking-evidenced revenue; independent source cards and deltas without addition/double counting.
9. **Correctness:** currency exponents, refund timing, duplicated webhooks, repeated ingests, per-client authority and period revisions; immutable event/reconciliation audit where appropriate.
10. **UX:** cards, visual sales trend, product/category tables, new/returning, refund view, source attribution coverage, charts, table pagination, drilldowns and source badges.

**Acceptance:** known sample orders and refunds reconcile deterministically at time-zone boundaries; same confirmed store totals across dashboard/report/share/export; no double revenue; authorized-store scoping; live connector proof required before `LIVE_READ_VERIFIED`.

---

## 5. P0 tracking links, UTM, short links and influencers — DS-P0-LINK

There are **two unrelated token classes** and they must not be conflated:

- **Tracking/redirect links**: existing `TrackingLink` and `/api/r/{token}` / configured `TRACKING_PUBLIC_BASE_URL`, which count eligible clicks and redirect to a stored safe destination.
- **Report share links**: existing `/shared-reports/:token` (and associated API) providing narrowly authorized report access.

### 5.1 Tracking/UTM studio

- One interface: `Client → Project → Campaign → Ad set → Ad → Creative` bindings where data exists, link creation, destination, domain, UTM source/medium/campaign/content/term, templates, short address, QR, ownership and expiry.
- Dynamic variables allowed only via validated, documented interpolation; preview the final URL and preserve both original destination and historical UTM settings for attribution integrity.
- Safe redirects: no open redirects, SSRF, private-network targets, credential-in-URL, arbitrary protocol, unsafe URL chaining, unsanitized parameter injection or mutable public destination supplied by clicker.
- Click IDs only provider-documented (`gclid`, `gbraid`, `wbraid`, `fbclid`, `ttclid`, `ScCid`, `li_fat_id`, `twclid` as applicable); capture consent, validate length/type, preserve only necessary allowed data; redact sensitive querystrings in shared reports/logs.
- Separate real clicks, suspected bots, duplicates, tests, landing views, forms, leads and confirmed store purchases. Add per-link timestamp, UTM and source freshness. A click is not a session, a lead, a call, a WhatsApp conversation or an order.
- Analytics: breakdown by UTM/campaign/platform/page/creative, trend, funnel, conversion rates with proper denominators, Link Performance, QR scans when measured. Enforce backend pagination, filters, workspaces/projects and export scope.
- Link lifecycle: create, edit allowed fields, copy, share, pause/resume, expire/revoke, duplicate, metrics, audit history. If destination changes, preserve reproducible before/after attribution instead of rewriting history.

### 5.2 Influencer measurement (if licensed by client data)

- Reuse tracking links and campaign bindings for `influencer/creator` entity linking: influencer, project/campaign, content, UTM, short links, QR, discount codes, landing pages, declared cost.
- Show attributable visits/clicks/forms and **evidenced** orders, confirmed store revenue, CPA/ROAS if basis exists, opt-in/consent and unknown or overlap buckets.
- No social/creative scraping, forbidden provider operations, invented influencer attribution, or private media copying. Distinguish organic influencer, whitelisted/boosted ad and paid ad if provider evidence exists.
- Dashboard and report sections are permissions-gated and user-configurable; do not require influencer engine for ordinary reporting.

### 5.3 Acceptance

- [ ] Valid and malicious destination/UTM/click-ID tests, redirect proof, QR output, paused/expired link 404 and no data leak.
- [ ] Link stats align with event deduplication, consent and bot policy; separate source counts and materialized summaries.
- [ ] Cross-project ID tampering and anonymous report leakage denied; all exports honor scope.
- [ ] Attribution joins to orders allowed only with stored identity/evidence, otherwise show `Unattributed / Unknown`.

---

## 6. P0 reporting and share-link lifecycle — DS-P0-REP

### 6.1 Unify existing reporting engine, NOT a new one

Extend `SaveReport`, `RenderReport`, `TakeSnapshot`, `ShareReport`, `ReadSharedReport`, `ReportDefinition`, section DTOs, report UI, and export writers. Preserve client-safe vs internal distinction and source authority. **Default internal**; sharing requires an explicitly safe report.

### 6.2 Report types and presets

- `Live`: re-renders the same authorized report definition against current confirmed data and advertises freshness.
- `Snapshot`: immutable historical rendered payload with capture timestamp, metric-definitions version, source, filters, currency, timezone and permissions at creation. Updating a report **must not silently mutate** old snapshots.
- `Executive`: fewer strategic cards, differences, risks, 3–5 evidenced insights and next recommended actions; not just a long report with fewer words.
- `Detailed`: controlled drilldown for platform/campaign/ad/creative, GA4/UTM, commerce, tracking, attribution, budgets, leads and quality diagnostics where permitted.
- Automatic smart presets by objective and data availability + full manual override. Do not create metrics without a reliable source just to fill empty sections.

### 6.3 Mandatory sections and controls

- Paid performance: spend, reach, impressions, clicks, CTR, CPC, CPA/CPL, ROAS (when valid), platform/campaign/ad set/ad/creative comparisons.
- GA4: acquisition, source/medium, UTM, landing pages, engagement, ecommerce journey, completeness.
- Store: gross/net confirmed orders/revenue, product/SKU/category, refunds, customer mix, trend, source reconciliation.
- Tracking: link clicks, QR, form, lead, funnel, events and click-ID evidence/quality.
- Attribution: authority comparison, model basis, lookback, overlap unknown, unattributed, evidence strength; model options only where real ordered/consented touchpoints exist.
- Operations: budgets, pacing, approvals, task/follow-up, integration health, notable anomalies, permitted agent findings.
- Influencers where configured, plus comments/annotations, recommendations, relevant charts, tables and sources.
- For each section, KPI, chart, table, column and sensitive field: show/hide, reorder, rename labels, generic labels for hidden entity names, Top-N, chart type, period, currency display, language, branding and level of detail **before and after creation**.
- Visibility enforcement is **on backend/query/serializer/cache/exports/shared endpoints**, not only CSS; no hidden campaign name exposed via tooltip, raw JSON, XLSX, PDF or chart label. Hiding the name must not necessarily hide an aggregate trend that user is authorized to see.

### 6.4 Report management (existing and new)

Required actions on every permitted report: `Create → Preview → Save → Open → Edit → Rename → Copy link (when securely available) → Share → Duplicate → Pause → Resume same URL → Revoke → Expire → Archive/Delete (authorized) → Change history + observed views`.

- **Edit same URL:** edit definition or visibility without minting a new share token; no widening of original link's client/project/account scope. Invalidate relevant cache atomically.
- **Pause/Resume:** reversible disablement preserving the original token and URL; pause enforced server-side for page/API/exports and cache, resume has no silent permission expansion. `Revoke` is final unless specifically and safely modeled otherwise.
- **Duplicate:** independent definition and new link under current permitted scope, original snapshot/history untouched.
- **Live/Snapshot conversion:** explicit semantics; no rewriting frozen historical snapshots or misleading an existing recipient about whether numbers refresh. Prefer an explicit new version/link when converting would violate recipients' original expectations; never silently widen capabilities.
- **Access:** enforce max expiry and revocation policy, view tracking without unnecessary visitor surveillance, rate limits and anti-enumeration, optional recipient authentication/password when configured, shared-link safe `Content-Security-Policy` and anti-indexing, no internal IDs/notes in public payload.
- **Legacy compatibility:** existing `/shared-reports/:token` URLs remain valid unless individually expired/revoked. If introducing a vanity route or custom branded domain, provide non-breaking routing and migration; do not accidentally conflict with redirect `/api/r/{token}`.

### 6.5 IMPORTANT SECURITY CONSTRAINT: copy existing report links

Current digitalSmart reports use **48 random bytes hashed with SHA-256**, and the database stores **the token hash, not the raw token**. It is *cryptographically impossible* to derive a previously issued raw share URL from the stored hash.

- **Never claim** that every old link can be re-copied from the database today, and never weaken token entropy, log raw tokens, or build an endpoint pretending to recover them.
- For an old URL already known to a user (their saved sent link), preserve it, allow in-place report edits/pause/resume, and show accurate access state. If the exact URL was not retained, explain that it cannot be recovered without prior storage; offer a safe **replacement link** with intentional rotation/revocation. Existing link still works until deliberately revoked/expired.
- For newly created links, design a **security-reviewed optional encrypted vault / authorized retrieval mechanism** if the owner insists on future “Copy existing link” after the creation flow, with authenticated RBAC, scoped audit, encryption-at-rest, limited admin access, rotation, expiry, and no plaintext logs. Document risk and choose the least-privilege approach. Never silently backfill unrecoverable secrets or apply new credentials retroactively.

### 6.6 Exports and deliveries

- CSV/XLSX/PPTX and **report PDF** must derive from **one authorized rendered report schema**; no second independent aggregation or unsupported chart metrics.
- PDF can use print-optimized HTML/headless render if it preserves evidence/sections and approved visibility; validate Arabic fonts, pagination, chart fidelity, timestamps/source badges, report identity, no clipping, and actual openable PDF. Don't label PDF complete until a real file has been read/visually checked.
- If export generated asynchronously, use signed/short-lived delivery, scope at execution time and download time, no privilege escalation between request and completion.
- Daily/weekly/monthly scheduled report generation and email with timezone and recipient preferences, idempotency and actual mail lifecycle evidence.
- Live rendering may change, but historical Snapshot/export must remain reconstructable from frozen values and metadata.

### 6.7 Acceptance matrix (must automate)

- [ ] New report + existing report: create/view/edit same URL, settings persisted, **same link token**, internal/client-safe separation.
- [ ] Pause blocks page + API + download + old cached response; resume restores same link; revocation and expiry respected.
- [ ] Duplicate preserves old report/history, respects current narrower scope.
- [ ] Snapshot values unchanged after upstream data sync and report edit; Live clearly updates.
- [ ] Source/period/currency/visibility matching across viewer, report JSON, PDF, CSV/XLSX and PPTX where supported.
- [ ] Unauthorized workspace/project/field names and internal notes never appear even when IDs, filters, querystrings or direct URLs are tampered.
- [ ] Legacy links remain valid across deploys; unrecoverable old raw token is treated truthfully, not faked.
- [ ] Signed-in desktop/mobile and anonymous client-safe share in Chromium/Firefox/WebKit with Arabic/English.

---

## 7. P0 unified sign-in, sessions and platform permissions — DS-P0-AUTH

Reuse Laravel Sanctum session authentication, `/login`, `LogIn`, workspace membership model, workspace switcher, entitlements, permissions and existing `/platform` console. Do **not** introduce a second identity provider or copy Mohtam/CampaignsHub auth databases.

### 7.1 Primary sign-in

1. One primary public login experience `/login`; no mandatory portal-choice screen. Backend determines destination by verified identity, active workspace membership, role, permissions and subscription/entitlements. If multiple authorized workspaces, use a workspace selector; if one, direct entry.
2. Continue email + password. Add/finish Google sign-in via **separate application-login OAuth client and verified configuration** if permitted; secure state/nonce/PKCE, domain allowlist as appropriate, identity matching without unsafe automatic account takeover, invite/signup rules, sign-out and MFA when required. Provider shown only when genuinely configured.
3. OTP options (email/SMS/WhatsApp when supported) may be enabled by platform admin *per purpose* (signup, login, recovery) and only through configured providers. An expensive option disabled in admin must cause **no corresponding send or billing event**, not just hide a button. Must never make all active users unable to authenticate or recover accounts.
4. Distinguish mandatory security verification/MFA from optional paid delivery: no downgrade of high-risk account recovery or admin session simply to save messaging fees. Support safe fallback; no insecure account-enumeration responses.
5. Preserve login → `/`/authorized area and allowed return URL. Legacy redirects only if real legacy routes exist; prevent open redirect, redirect loops, CSRF 419 loops, 401 oscillations and cross-tab session resurrection.
6. On workspace switch, revoke cached results/in-flight stale responses, reset project selectors, re-check membership, clear sensitive client state and prevent WebSocket subscription leakage.
7. Login fields: obvious borders, label contrast and weight, ~52–56px height where appropriate, accessible password toggle, error messages, visible primary CTA, strong focus, mobile above fold; retain brand `digitalSmart` while name undecided.
8. Support desktop/mobile, AR/EN, RTL/LTR and light/dark; use real browser E2E instead of visual screenshots alone.

### 7.2 Full admin governance without hidden bypass

- Platform owner can administer workspaces, subscription plans/entitlements, users, roles, permissions, project scopes, integrations, report features, automation, notifications, public link expiry, exports, provider write capabilities, API/webhooks and effective navigation. Use **the existing console**, not an overlapping admin engine.
- Effective access is **capability available AND entitlement AND workspace policy AND role permission AND project scope AND active status**. Return explicit denied/locked states, never infer authorization from hidden UI.
- Audit actor, target workspace, before/after, reason, timestamp, effective scope and request id. Admin support access must have explicit scope and be logged; no invisible impersonation.
- Registration approvals: full/partial, scoped feature access, duration, trial/discount where actual billing architecture supports it. Never label a payment received without verified gateway evidence; don't pretend a billing provider exists if none is configured.
- Avoid cross-tenant aggregate disclosure in totals, exports, caches, search, notification audiences, report links, agent context and audit log browse.

### 7.3 Acceptance

- [ ] Login/logout/relogin/refresh/role destination; inactive/revoked membership; multi-workspace switch and cross-tab race; tampered workspace id; remembered intended URL.
- [ ] 401/403/419/422 errors explicitly handled; CSRF cookie handshake tested against actual frontend origin; no reset of an existing `APP_KEY`.
- [ ] Google flow configured/sandbox-tested; only claim real OAuth when successful authorized round-trip.
- [ ] Per-channel OTP on/off × login/signup/recovery × each role; blocking all recovery channels refused or safe fallback proven.
- [ ] Mobile 390px/430px and desktop 1366px; accessible form and no frontend-only permission gate.

---

## 8. P0 automation, seven agents, approvals and safe provider operations — DS-P0-AUTO

**Reuse existing** `RuleEvaluator`, `RunAutomationRules`, `AgentOrchestrator`, seven `Agents/*`, `OperatingRules`, `ToolGateway`, proposal/approval model, Routines, Action history and current provider capability registry. Never clone Mohtam's sales-specific automation engine.

### 8.1 Product separation of duties

`Observed event/measurement → deterministic Rule → Finding → Agent-supported interpretation → Proposed action → Policy/risk/entitlement check → Human approval where money/provider changes → Execution via existing provider adapter → Read-back evidence → Audit + notification`.

- Rules = deterministic repeatable conditions. Agents = analysis of **permitted** evidence. Approvals = authorization. Provider writers = optional controlled action. Routines = schedule. Action history = truth.
- Existing seven workers: Analyst, Campaign, Finance, Creative, Leads, Reports, Project Operations. Review function and actual implementation; expand skills not count for marketing.
- Agent outputs must cite client/project/time/metric/value/source/freshness; no invented ROAS, inferred correlations or unsupported budget recommendations. Deterministic fallback when model key absent; model output never trusted as permission or authority.
- Proposed recommendations: delivery stopped, budget at risk, rising CPA/CPL, weak CTR/creative fatigue, GA4/store discrepancy, stale connection, tracking failure, response/SLA risk, report due, critical task, campaign-end urgency.

### 8.2 Routines, rule controls and safety

- Scope: platform → workspace → client → project → account → campaign → ad group → ad → creative; apply only effective permission and actual level-specific metrics.
- Builder supports enabled/paused, thresholds, windows, compare intervals, cooldown, minimum evidence/sample, severity, recipients, schedule and timezone, quiet hours, dry-run/simulate and conflict resolution.
- Queue isolation and priorities: reporting/notifications/agents/sync/exports separately where appropriate; enforce tenant context and idempotency key per event+rule+window+target. Retry/backoff, rate limiting and dead-letter/replay with audit.
- Budget/spend/status/ads writes require capability proof, valid consent/approval, dry-run preview, account/project binding, re-read platform state, idempotency and read-back. Default safe/paused for campaign creation as already designed; **do not launch or spend** merely because agent found a winning campaign.
- No model or credentials → deterministic local findings plus clear `AWAITING_MODEL_CREDENTIALS` / provider blocked state.
- RBAC and entitlements apply to rule creation, simulation, approval, execution, model access and history; do not let shared-link audience invoke tools.
- Show run ledger: `queued / skipped / suppressed / proposed / needs_approval / approved / executing / verified / failed` with reason, evidence, retries, actor and timestamp; no “completed” without read-back when required.

### 8.3 Examples of desired genuinely useful automations

- GA4 traffic drops while paid clicks remain stable → alert tracking/landing discrepancy, **not** auto-pause ads.
- Store confirmed orders decline vs a comparable period → highlight margin/revenue impact with source; suggest test.
- Campaign CPA crosses known budget policy → dry-run change proposal; require permission to execute.
- Meta/TikTok creative preview coverage unexpectedly falls → integration health alert and retry eligible asset reads, without leaking media tokens.
- Scheduled Monday client report → snapshot or live-link digest as configured, versioned metric definitions, send queue and delivery truth.
- Lead/funnel stage changed → relevant permitted follow-up/task/alert; no unsolicited WhatsApp/SMS without compliant consent/provider setup.

### 8.4 Acceptance

- [ ] Rule target and sample/denominator correctness; cross-entity scopes; idempotent retries; conflicting actions; per-project RBAC.
- [ ] Agents propose, do not silently write; approvals audited with read-back; revoked approval never executes.
- [ ] Scheduler disabled in local demo unless safe; simulated and authorized live routines separated.
- [ ] No third-party spend, paid message, provider mutation or real customer outreach in test scenarios.
- [ ] Visible action history, reasoning evidence and guardrail refusals in AR/EN, mobile/desktop.

---

## 9. P0 notifications, email templates, realtime and digests — DS-P0-NOTIF

Reuse notification feed/preferences, `Announce`, `DeliverNotification`, `RecipientResolver`, Reverb/Echo, existing digest settings and queue integration.

### 9.1 Notification center

- One clear center: unread/read, grouped by severity/date/category, deep link to exact authorized entity, mark read/all read, settings, quiet hours, mute/snooze/cooldown, accessible action controls and mobile-friendly bottom-sheet/full-screen experience.
- Per workspace/user/role/project/client/campaign recipient rules. Distinguish tenant-wide summaries from project-limited notifications; never include unauthorized aggregate totals in a title or preview.
- Categories: approval needed, assigned task, lead needing response, budget threshold, performance anomaly, GA4/source or GTM tracking gap, store data stale, sync error/disconnected provider, creative missing preview, campaign paused/ending, automation failures, report ready/shared/expired, service/security notice.
- Realtime nudge only to authenticated, currently authorized channels; re-check subscriptions after workspace switch and permission changes; refresh data via scoped API, never broadcast an entire private report or raw lead details.

### 9.2 Branded professional email system

- Current brand: **digitalSmart** (configurable later). Do not use CampaignsHub/Mohtam subject prefixes, company domains or contact addresses.
- One responsive HTML email layout ~560–640px on desktop and 320–390px mobile; logical text alignment (Arabic RTL/English LTR), robust table/inline styling for Gmail/Outlook/Apple Mail, safe fonts, compact title, deliberate spacing, visible CTA and plain-text part.
- System name in every relevant subject: `digitalSmart — تقريرك الأسبوعي`, `digitalSmart — تنبيه يحتاج متابعة`, `digitalSmart — دعوة إلى مساحة عمل`, localized equivalents. Only use an approved verified From/Reply-To identity actually configured for this project.
- Templates: invitations, account activation, OTP if enabled, reset/security, workflow approval, schedule/recap, integration alert, report share/update, report delivery, failed/suspended task, billing where gateway exists. Never embed provider secrets or unnecessary client financial detail.
- Preview/QA per template in AR/EN, mobile/desktop, Gmail/Outlook/AppleMail when available; image fallback and dark-mode graceful degradation. Email link must be HTTPS on a real configured domain; no production messages with local URLs.

### 9.3 Delivery evidence and schedules

- Delivery states: `queued → attempted → provider_accepted → delivered` **only if provider proves delivered**; otherwise remain `provider_accepted`; `failed / bounced / suppressed / skipped` with reasons. Do not call a queue insertion “sent”.
- Daily/weekly/monthly digest, quiet hours, notification priority and recipient settings; compute in *workspace timezone*, respect DST and quiet periods; define missed-run recovery policy and maximum duplicates.
- The same summary metrics used by dashboard/reports must drive the digest, with correct project permissions and real last-updated stamp.
- Support authorized test send only in sandbox or to explicitly permitted test recipient; do not require public SMTP credentials in repo/chat. If provider missing, label sending `BLOCKED_EXTERNAL_CREDENTIALS`, but complete template/queue/test implementation.
- Track unique event/delivery keys across job retries and mail provider callbacks; suppress duplicated alerts and stale notices; queues must be per-app namespace when Redis is shared.

### 9.4 Acceptance

- [ ] Correct recipient set; workspace/project data cannot leak via mail, WebSocket payload, title, preview or digest attachment.
- [ ] Distinguish queued/accepted/delivered/failed in DB/UI and tests; provider outage does not turn into fake success.
- [ ] Quiet hours and timezones, daily/weekly/monthly scheduling, dedup, retries and opt-outs.
- [ ] Browser + email visual snapshots AR/EN, dark/light and mobile; test with actual provider only after authorized setup.

---

## 10. P0 provider integration and media previews — DS-P0-INT

Reuse connection lifecycle and commissioned provider adapters; no API calls to reference products.

- Providers: Meta Ads, TikTok Ads, Snapchat Ads, Google Ads, X Ads, LinkedIn Ads, ChatGPT Ads where supported; GA4/GTM; Salla/Zid; HubSpot only to extent current architecture supports.
- One connection center: provider state, scopes, OAuth access/expiry, authorized accounts, project binding, latest attempted/successful sync, count of ingested campaigns/ads/creatives, freshness, errors, retry, reconnect and disconnect. No single “connected” state as proxy for complete syncing.
- Connection lifecycle must stop **future and queued reads/writes** as appropriate when disconnected; handle in-flight safely, avoid duplicate syncs, preserve historic data with accurate staleness and audit actions. Reconcile #74 with current main before changing this domain.
- On every sync, preserve platform-native campaign/ad group/ad identifiers and creative/media associations, avoid false currency conversion or unsupported metric mapping, apply provider rate limits.
- Media previews: provider → discovery/pagination → asset metadata → canonical resolver → protected thumbnail/video source → UI/report/export. Show real images/video/Carousel/Story/Reel/Catalog where the provider exposes them with permission, proper aspect ratio, expiry and playback; no HTML share page treated as an image; no false placeholder marked as real.
- For unsupported media formats, expose exact provider-side reason and true actionable state. **100% coverage means 100% of authorized, available recoverable assets**, not fabricating a preview of private/unavailable creative.
- Production read-only troubleshooting when authorized; distinguish code bug vs missing OAuth scopes vs API approval vs bad account binding vs API limit vs stale asset URL. Real provider access blocked means `BLOCKED_EXTERNAL_CREDENTIALS`, with exact per-provider action.
- Do not change Meta app, OAuth scopes, live tokens, provider ownership, business account, production permissions, or spending limits without explicit relevant approval.

**Acceptance:** provider × media format × surface matrix; real thumbnail decode/video playback where permitted, correct report creative mapping; no 401/403 leak; before/after missing-preview counts; sandbox demo is not live proof.

---

## 11. P0 UX, performance, mobile and responsive design — DS-P0-UX

### 11.1 Fast data without invented freshness

- On dashboards/GA4/store/campaigns/report/integration pages, render last confirmed **correctly scoped** data immediately where secure cache exists; revalidate in background (`stale-while-revalidate`), with visible last updated/source/sync state.
- On cold cache, render stable skeletons per module, not full-screen hanging loading; show independent results progressively. Never show workspace A's cached figures while workspace B loads.
- Replace giant vertical tables with top KPIs + clear charts + small server-paginated tables; default 10 rows; options 10/25/50; actual sort/search/filter, compact/truncated UTMs, sticky table header and detail drawer.
- Profile p50/p95 API, query latency, N+1, DB indexes and query plans, payload size, bundle size, React rerenders, frontend transitions, Redis/Horizon contention and stale cache invalidation; optimize with measured before/after comparisons on same dataset/device/network.
- Long running syncs/exports/reports in scoped queues; **avoid shared Redis key collisions** with CampaignsHub/مهتم. Show job and last-success states honestly. Do not flush all Redis, blindly start schedulers or drain unknown queue payloads.
- Suggested targets: test-condition LCP ≤2.5s, INP ≤200ms, CLS ≤0.1, with real baseline and reasoned per-API p95; never promise universal instant loading.

### 11.2 Application-quality UI

- Existing `MobileTabBar` and mobile drawer are a starting point: audit user experience rather than rebuilding needlessly. Mobile 4–5 permission-aware primary tabs; app-style full-screen or bottom-sheet “More”, workspace/project selector, notification bell, safe areas, >=44px taps, keyboard focus and accessible back behavior.
- Dashboard 1366×768 first-class, 1440×900 and 390/430 mobile; RTL/LTR and AR/EN; light/dark; no page-wide horizontal scroll, excessive whitespace, excessive cards or cramped filters.
- Micro-interactions should feel polished **without delaying actual response**: ~120–220ms controls, ~180–300ms drawers, reduce motion setting, no mouse cursor speed modification, no animation blocking real data.
- Shared report experience is presentation-first on mobile: responsive KPI tiles, compact charts, expandable rows, readable monetary values, source/quality badges, high-contrast CTA.

### 11.3 Acceptance

- [ ] Before/after performance report: page, dataset, tenant scope, p50/p95, query count/payload, LCP/INP/CLS, duplicate API requests, screenshot and trace.
- [ ] Heavy sync in background does not freeze report interactions or leak another tenant's stale data.
- [ ] Playwright Chromium/Firefox/WebKit; 1366×768, 1440×900, 390/430 mobile; AR/EN/RTL/LTR/dark/light; keyboard navigation and reduced motion.

---

## 12. P1 product polish with high business value — DS-P1

These are **after security and P0 core**, and are not an excuse to skip P0:

1. **Marketing website** with credible homepage, clear value, demo/contact CTA, integrations/solutions/pricing where commercially approved, genuine screenshots/testimonials, FAQ, privacy/terms/data deletion, SEO/OpenGraph/sitemap, analytics and lead attribution to the system's own CRM. Only show approved product contact channels; never reuse mohtam.pro or campaignshub.io identity.
2. **Report white-label** workspace/client logos, style, custom domain (only when DNS and TLS are actually configured), PDF branding and user-visible branded identity without leaking other clients' logos.
3. **Link/influencer studio** advanced campaign templates, scheduled UTM audits, QR, coupon reporting and attribution evidence quality.
4. **Campaign content intelligence** thumbnail coverage diagnostics, creative-level comparison, fatigue, preview formats and share-safe recommendations.
5. **Optional model routing** and robust usage-cost accounting for agents; redact PII before model calls, configurable retention, audit and opt-out.
6. **Saved workspace views** and executive report presets without bypassing filters or exporting unauthorized aggregates.
7. **Optional campaign launch improvements** use existing paused/approval-first path, only if provider API supports operation and safe read-back.
8. **Subscription/registration commerce** fully paid onboarding only after real billing provider and commercial/legal policy exist; preserve existing entitlement gates in meantime.

---

## 13. Security and data governance — DS-P0-SEC

**This is a non-negotiable P0 across all workstreams.**

1. Every read/write/export/report/share/notification/queue/job/agent/WebSocket channel uses authenticated **workspace + client + project + provider account** scope and effective entitlements; no global fallback due to missing filter. Project-limited roles never see other project totals.
2. Public tracking and report-share tokens are purpose-bound, random, rate limited, revocable/expiring as designed and must not share route semantics. Prevent token enumeration, guessing, and cross-client inference.
3. Default report internal; client-safe is an explicit policy decision. Source data minimized and sensitive fields redacted in all downstream renderers.
4. CSRF/CORS/session/cookie security, OAuth state protection, encrypted tokens, safe redirects, SSRF prevention, webhook signature verification and idempotency, file upload scanning/validation where applicable.
5. Queue workers re-check authorization and live connection status before sensitive work; retries or late callbacks must not resurrect revoked access.
6. Audit policy/permission/report/share/provider changes, secrets operations and agent action proposals. Never log raw tokens, OTP, platform access credentials or unnecessarily personal lead data.
7. Data retention policies cover `agent_tool_calls.input`, report-link access metadata, tracking events, export objects, email delivery logs, consent and backup restoration. Do not keep indefinite raw payloads for convenience.
8. Rate-limit high-cost/abusable actions and enforce resource budgets to prevent runaway syncs, report generation and AI agent loops.
9. Performance cache keys must include workspace, authorized project/client/account scope, permissions/entitlements version, selected source, period/timezone, currency and filters where required.
10. Add adversarial tests for denied paths across every feature introduced, including guessed UUIDs, forged workspace selection, expired link, role downgrade, tenant switch during pending requests and report export after permission revocation.

---

## 14. Operations, readiness, deploy and recovery — DS-P0-OPS

### 14.1 Readiness center

Extend current health/commissioning views into one useful operations surface per platform/workspace:

`Application auth | Google auth | GA4 properties | GTM | ad providers | Salla/Zid | OAuth scopes | last provider round trip | queue/Horizon | scheduler | Reverb | delivery SMTP/SMS | reports PDF/export | share links | agent model | backups | errors | production domain/TLS`.

Each has: `configured?`, `last success`, `last attempted`, `age`, `failure category`, `what works locally`, `what is proven live`, `owner/provider step needed`, and safe action (retry/test/disconnect) for authorized operators. Green API health alone is not proof of provider readiness.

### 14.2 Environment and deployment requirements

- Preserve current Laravel 12 API-only + React/Vite/TanStack Query + PostgreSQL + Redis/Horizon/Reverb + DDD/Clean Architecture, existing CI and tenancy boundaries.
- Production is not live merely because GitHub's Deploy workflow returns success when the deploy job was skipped. Require real staging domain/server/TLS/secrets/backup/monitoring/reverse proxy, environment-specific `APP_URL`, frontend origin, cookie scope, OAuth callback allowlist, queue/scheduler and service process management.
- Never commit production `.env`, refresh token or private key. Never regenerate existing `APP_KEY`; avoid `migrate:fresh`, `db:wipe`, `demo:agency --fresh` against an existing dataset.
- Before production migration: create and test backup/restore; evaluate backwards compatibility, one-direction data migrations and rollbacks; perform non-destructive, scoped smoke tests.
- Distinguish local dev (known historical `http://127.0.0.1:5174`, API `:8001`), staging, production, provider sandbox, real provider read and real provider write.
- Host/readiness missing → `AWAITING_SERVER_ACCESS` / `BLOCKED_OPERATIONAL_EVIDENCE` with exact external action, while local implementation and CI continue.

### 14.3 Production smoke journey

`Login → workspace selection → project selection → ad platform read → GA4 property read → Salla/Zid read → Analytics → UTM/tracking → create client-safe Live report → share URL → edit same URL → pause/resume → take Snapshot → PDF/XLSX → scheduled notice → agent suggestion → approval preview → Reverb notification → audit log`.

Do not simulate real spending, message real recipients, create actual provider campaigns, or write actual customer orders to satisfy a smoke test unless this is explicitly authorized in a safe test account.

---

## 15. Mandatory PR implementation program and dependencies

**Fix existing backlog first, with correct gate discipline**. Each row below is an executable slice, not permission to skip integration tests. Combine only tightly coupled work if doing so improves safety.

| Order | Workstream | Minimum outcome | Depends on | Gate |
|---:|---|---|---|---|
| 00 | Baseline + #75/#74 | Verify current Git; close still-open relevant PRs safely, update stale matrix; don't duplicate already merged parts | current main | All required CI green |
| 01 | Security & scope | Cross-workspace/project audit on reports/GA4/links/jobs/caches/agents; deny leaks | 00 | Negative cross-scope suite |
| 02 | Report link lifecycle | In-place edit, pause/resume, duplicate, legacy compatibility and honest token-copy UX | 01 | Live/share API + browser E2E |
| 03 | Report builder/sections | Visibility/intent/presets, report-scoped filters and metric/source definition | 02 | API/export parity |
| 04 | GA4 provider foundation | Real read-only OAuth/Data/Admin adapter, secure properties and operational status | 01 | API contract + permission tests; real credential may block live gate |
| 05 | GA4 analytics | Sources, UTM, sessions, engagement, ecommerce funnel and visualizations | 04 | Read model + sample/partial + browser E2E |
| 06 | Commerce intelligence | Verify #75; finish real gaps in products, checkout, coupons, cohorts and reconciliation | 00,05 as needed | Revenue/UTC/authority tests |
| 07 | Attribution and links | Evidence-based joins, source comparison, influencer extension if real data | 05,06 | Tracking/consent/authority tests |
| 08 | Report inclusion | GA4/store/attribution/influencer sections, snapshots, PDF and export parity | 03,05–07 | Legacy link + export parity + real PDF |
| 09 | Unified auth | Route/handoff improvements, Google sign-in if configured, OTP settings, responsive fields | 01 | browser/session/rate/MFA tests |
| 10 | Agents/automation | Better evidence, routines, approval gates, dedup/retry, schedule safety | 01,05–07 | action/risk + tenant suites |
| 11 | Notifications/email | Professional templates, real delivery ledger, digests, project isolation and realtime | 09,10 | email/channel/timezone tests |
| 12 | Provider content media | Asset resolution/preview and integration diagnostics per platform | 01 | provider-format x surface tests |
| 13 | Performance/mobile | p95 optimization, SWR, paginated tables, mobile app-level polish | iterative | before/after traces + browser matrix |
| 14 | Readiness + release | Staging/production commissioning, backup, runbook, final acceptance report | all | confirmed deploy and authorized real evidence |

**Parallel safety:** GA4 groundwork, login and email templates can be separate worktrees if they don't edit shared schema without coordination. Merge dependent slices sequentially; avoid multiple sessions modifying `main` in the same directory.

---

## 16. Requirement IDs and coverage checklist — atomic acceptance map

The agent MUST expand these into subcriteria in the canonical requirements matrix and mark each against code and evidence:

| ID | Priority | Required outcome | Key proof |
|---|---|---|---|
| DS-P0-000 | P0 | Exact traceability and current Git baseline | matrix diff + SHA |
| DS-P0-SEC-01 | P0 | Workspace/client/project scoped data everywhere | negative tests |
| DS-P0-SEC-02 | P0 | Shares, tokens, OAuth, jobs, cache, export isolated | adversarial E2E/API |
| DS-P0-GA4-01 | P0 | Read-only live Google OAuth + property binding | real authorized round trip |
| DS-P0-GA4-02 | P0 | Source/medium, channel, landing, device, UTM | Data API read + UI |
| DS-P0-GA4-03 | P0 | Users/sessions/engagement/commerce KPIs | metadata and metric contract |
| DS-P0-GA4-04 | P0 | GA4 acquisition and ecommerce visualization/funnel | browser + metric provenance |
| DS-P0-GA4-05 | P0 | GA4 quota, freshness, sampling, failures | failure-injection tests |
| DS-P0-COM-01 | P0 | Gross/net orders, refunds, components, reconciliation | order/refund fixtures |
| DS-P0-COM-02 | P0 | Store-local time and trends | timezone edge tests |
| DS-P0-COM-03 | P0 | SKU/products/category/customers/coupons | provider data coverage |
| DS-P0-COM-04 | P0 | Store/GA4/platform revenues separate | no-double-count tests |
| DS-P0-LINK-01 | P0 | UTM templates, tracking redirect, QR, statuses | redirect and security E2E |
| DS-P0-LINK-02 | P0 | Link, influencer and funnel attribution evidence | consent/order join tests |
| DS-P0-REP-01 | P0 | Report types, smart presets and granular visibility | UI + backend response |
| DS-P0-REP-02 | P0 | Edit live report keeping same share URL | token persistence E2E |
| DS-P0-REP-03 | P0 | Share pause/resume/duplicate/revoke/expire | URL and cache-denial E2E |
| DS-P0-REP-04 | P0 | Frozen historical snapshots and version history | immutability tests |
| DS-P0-REP-05 | P0 | GA4/store/source sections + charts and tables | cross-screen metric parity |
| DS-P0-REP-06 | P0 | CSV/XLSX/PPTX/PDF export visibility and values | actual file verification |
| DS-P0-REP-07 | P0 | Secure link-copy capability without token recovery lie | security review + UI test |
| DS-P0-AUTH-01 | P0 | Unified login, role/workspace destinations | browser E2E |
| DS-P0-AUTH-02 | P0 | Google sign-in and optional OTP settings | OAuth/OTP matrix |
| DS-P0-AUTH-03 | P0 | Admin governance, effective entitlements and audit | route/API tests |
| DS-P0-AUTO-01 | P0 | Existing seven agents and rule workflow extended | finding evidence tests |
| DS-P0-AUTO-02 | P0 | Explicit approval and read-back for writes | write refusal/approval tests |
| DS-P0-AUTO-03 | P0 | Scheduler/routines/cooldown/idempotency | replay/concurrency tests |
| DS-P0-NOTIF-01 | P0 | Realtime, preference-controlled notification center | E2E + tenant tests |
| DS-P0-NOTIF-02 | P0 | Email templates/subjects AR/EN responsive | client previews |
| DS-P0-NOTIF-03 | P0 | Real delivery states/daily-weekly-monthly digests | provider receipt/queue tests |
| DS-P0-INT-01 | P0 | Account connection detail and diagnostics | read-only test + status ledger |
| DS-P0-INT-02 | P0 | Actual creative preview where provider supplies media | playback/asset test |
| DS-P0-UX-01 | P0 | Fast scoped SWR, no full page flashing | measured TTFB/LCP/INP |
| DS-P0-UX-02 | P0 | Pagination, filters, responsive charts | browser table tests |
| DS-P0-UX-03 | P0 | Mobile app shell and AR/EN accessibility | 390/430 + desktop matrix |
| DS-P0-OPS-01 | P0 | Operations/readiness evidence, queue safety | health + commissioning |
| DS-P0-OPS-02 | P0 | Real staging/production deploy/rollback proof | actual server URL/sha/log |
| DS-P1-MKT | P1 | Genuine marketing/reputation and lead capture | production page + secure form |
| DS-P1-WL | P1 | Branded client reports/custom domains | DNS/TLS + view/export evidence |

All P0 elements should have explicit source-specific test cases, not just one blanket checkmark. If an upstream API cannot provide an item, document unsupported state, user-visible reason and approved alternative—not a fabricated value.

---

## 17. Final proof package, anti-shortcut rules and handover

### 17.1 Minimum quality gates

- Backend: full Pest; Pint; PHPStan; tenancy, permissions, API contract, migration compatibility, queue/idempotency, provider boundary, stored report history tests.
- Frontend: TypeScript typecheck, ESLint, unit/Vitest, production build, color/RTL/accessibility checks.
- Browser: Playwright across Chromium/Firefox/WebKit including mobile, AR/EN, workspace switching, sessions and shared links; no ignored real failures. Flakes must be isolated, fixed or correctly classified with evidence.
- Exports: open and inspect generated CSV/XLSX/PPTX/PDF against rendered report with the same scope, filters and source-quality metadata.
- Provider test: sandbox fixtures prove **code only**; real OAuth/Data API and one real authorized account/store/property are required for live acceptance.
- Production: specific deployed SHA, URL, smoke logs, metrics source, last successful sync, guarded message delivery, backup/restore proof and roll-forward/rollback plan.

### 17.2 Non-negotiable prohibitions

- No cross-product code/data/credential imports or changes to CampaignsHub/مهتم repositories.
- No promises of “100% implemented” based on writing this file, a test suite or a local demo.
- No forced `migrate:fresh`, `db:wipe`, Redis `FLUSHALL`, secret rotation, provider re-authorization or data destruction without explicit authorization.
- No broad `redis-cli` operation on a shared Redis instance; use scoped app prefix and read-only inspect before worker changes.
- No provider ad launch/budget change/spend, paid SMS/WhatsApp or production mail to actual clients without the required authority.
- No guessed attribution, artificial sales, fake active sessions, false “delivered” status, fake media preview or raw tokens stored in logs.
- No UI-only hiding of restricted data; no leaking metrics via report aggregate, notification, exports, caches or jobs.
- No rename of product or public domain before owner approves a final brand/domain.

### 17.3 Completion report format

Provide `docs/DIGITALSMART_P0_EXECUTION_REPORT.md` or a clearly linked canonical section in the existing docs containing:

```text
EXECUTION DATE (Asia/Riyadh):
REPOSITORY / BRANCH / MAIN SHA:
OPEN PRs / MERGED PRs / MERGE SHAs:
REQUIREMENTS: total / VERIFIED / IMPLEMENTED_NOT_VERIFIED / PARTIAL / BLOCKED / NOT_STARTED
SECURITY: workspace/project/export/link/cache/queue/agent negative tests
GA4: OAuth status / property / Data API / exact metrics / freshness / sample-quality / blocked steps
COMMERCE: live read state / order authority / revenue reconciliation / local-time buckets
TRACKING: redirect / UTM / QR / influencer / evidence joins
REPORTS: Live / Snapshot / same-URL edit / pause-resume / duplicate / export parity / PDF
LOGIN: password / Google / OTP settings / session reload / workspace switch
AUTOMATION & AGENTS: rules / seven agents / approvals / safe execution / logs
NOTIFICATIONS: realtime / email provider / queue / digest / delivery receipts
MEDIA: previews by provider + type + actual success/known external gaps
PERFORMANCE: before/after LCP/INP/CLS + API p95 + screenshot/trace
QA: Pest / PHPStan / Pint / Typecheck / Lint / Vitest / Build / Playwright
LOCAL: working URL + login result + process/queue state
STAGING/PRODUCTION: real URL / deployed SHA / server proof / provider read proof
BLOCKERS: requirement IDs + exact provider/account/scope/authorization/server step
NEXT EXECUTABLE WORK:
READY FOR INTERNAL DAILY USE: YES/NO (with evidence)
READY FOR REAL CLIENTS: YES/NO (with evidence)
READY FOR PUBLIC LAUNCH: YES/NO (with evidence)
```

**The status `100%` is permitted only when every applicable requirement has passed its acceptance gate in its proper environment and zero unexplained gaps remain.** External blockers do not disappear from the count merely because the code is complete.

---

## 18. One-command execution mandate for Claude Code

Use the following command **in the existing digitalSmart working environment**, with this Markdown file placed in the repository as `docs/DIGITALSMART_P0_MASTER_EVOLUTION_EXECUTION_2026-11-03.md` (or pass its absolute path):

> **Execute this digitalSmart P0 master directive completely and autonomously, not as a planning exercise. Read the entire file plus the two owner reference files and the current digitalSmart code, docs, GitHub PRs and CI; take best ideas as feature requirements only, never copy code/data/secrets from CampaignsHub or Mohtam. Start with tenant/report/credential security, close relevant open PRs, then deliver GA4 real-read foundation and analytics, Salla/Zid reconciliation, UTM/tracking links, editable same-link Live reports and immutable Snapshots, safe PDF/CSV/XLSX exports, unified login/Google/OTP controls, existing seven agents/automation approvals, professional notifications/digests/email, provider creative previews, performance/mobile UX and readiness. Work in small tested PRs, run full CI, merge by repo policy, deploy only to configured authorized environments, perform real browser/provider evidence checks, and update the canonical requirements matrix after every batch. Do not seek approval for routine engineering; do not perform destructive, financial, credential-sensitive or provider-live actions without explicit authorization. Do not claim 100%, deployed or LIVE_VERIFIED without the exact evidence; record and isolate precise external blockers, and continue all independent work until complete. Return only actual PR/SHA, tests, deployed evidence, working links, requirement counts and exact remaining blockers.**

**End of master directive.**