# P0 master directive — requirement matrix (DS-P0-MASTER-2026-11-03)

Cross-module acceptance rows of the owner's P0 directive, linked to the commerce rows in `docs/UNIFIED_COMMERCE_MATRIX.md` (that file stays
the one place `DS-UC-*` rows live; nothing here repeats them). Read against `origin/main` at `5bd92f3` (#74). The directive's two
reference documents (CampaignsHub, Mohtam) are **not present in this repository**; the directive's own acceptance criteria are used and the
missing references are reported for provenance. Nothing is copied from either product.

States: `NOT_STARTED` · `PARTIAL` · `IMPLEMENTED_NOT_VERIFIED` · `VERIFIED` · `BLOCKED_EXTERNAL_CREDENTIALS` · `BLOCKED_OPERATIONAL_EVIDENCE`.
`VERIFIED` for a production feature means merged, deployed and proven on an authorised environment. Nothing is deployed
(`AWAITING_SERVER_ACCESS`) and nothing is `LIVE_READ_VERIFIED`, so CI-proven rows are at most `PARTIAL`/`IMPLEMENTED_NOT_VERIFIED`.

| ID | State | What exists (evidence) | Exact gap | Next slice |
|---|---|---|---|---|
| DS-P0-000 traceability | PARTIAL | This file; `UNIFIED_COMMERCE_MATRIX.md` reconciled to `1e19215` | Per-row PR/SHA columns fill in as slices merge | every PR |
| DS-P0-SEC-01 scoped data | PARTIAL | `TenantRouteMatrixTest` (every id-taking route, 152 calls), `TenantSchemaTest`, `SharedFilterTest`, `SharedPayloadIdsTest` | Matrix does not yet cover caches, queued jobs, agent context, notification audiences or exports after permission revocation | 01 |
| DS-P0-SEC-02 shares/tokens/jobs/cache | PARTIAL | Hashed 48-byte share tokens, expiry ≤ 90 days, revoke with reason, throttled public route | Adversarial suite for revoked-permission export, cache-key scope, queued-job re-authorisation | 01 |
| DS-P0-GA4-01 live OAuth + property | BLOCKED_EXTERNAL_CREDENTIALS | Adapter, discovery and sync with sandbox fixtures; non-sandbox credentials refused | Real read-only Admin/Data API transport and OAuth round trip (needs a Google OAuth client and an authorised property) | 04 |
| DS-P0-GA4-02..04 sources, KPIs, funnel | NOT_STARTED | Only `date × eventName` counts and one value metric are read (`DS-UC-302`) | Sessions/users/engagement by source-medium, landing page, device; ecommerce funnel; metadata-driven metric dictionary | 05 |
| DS-P0-GA4-05 quota, freshness, sampling | PARTIAL | `ReportQuality` handles sampling, thresholding, truncation, `(other)` | 401-refresh, 403, 429 backoff and quota reporting against a transport | 04 |
| DS-P0-COM-01 gross/net/refunds | PARTIAL | Revenue ledger (#71 `009be9d`): charged, refunded, components | Discount/tax/shipping components may stay null by provider | 06 |
| DS-P0-COM-02 store-local time | PARTIAL | UTC at rest (#72 `62e5688`); store-timezone days, weekly/monthly grain, previous period (#75 `1e19215`) — CI-verified | Attribution day boundaries still use the placed-at date (`DS-UC-401`) | 06 |
| DS-P0-COM-03 SKU/category/customers/coupons | PARTIAL | Product-by-line, customer mix with withholding rule | SKU grouping, category, coupon read model | 06 |
| DS-P0-COM-04 sources never summed | PARTIAL | `RevenueSource` keeps authorities apart | A reconciliation read with deltas (`DS-UC-401`) | 06/07 |
| DS-P0-LINK-01 tracking links | PARTIAL | Links, safe redirect, QR, UTM templates, click-id whitelist, bot marking | UTM audit; link edit history preserving attribution | 07 |
| DS-P0-LINK-02 influencer / evidence joins | NOT_STARTED | Tracking sessions and events exist; attribution does not read them (`DS-UC-402`) | Join by click id/touchpoint; influencer entity | 07 |
| DS-P0-REP-01 types, presets, visibility | PARTIAL | Presets are client-side section lists; chart/table twin | Stored `intent`; per-section/field `hidden` enforced server-side (`DS-UC-502/503`) | 03 |
| DS-P0-REP-02 edit keeping same URL | PARTIAL | `PUT reports/{report}` edits the definition; share rows are separate from the definition | Not yet proven by a test that the share token and URL survive an edit; widening guard | 02 |
| DS-P0-REP-03 pause/resume/duplicate/revoke/expire | PARTIAL | Revoke and expiry exist; **no pause/resume**; duplicate not offered | Reversible pause enforced on page, API, exports and cache | 02 |
| DS-P0-REP-04 immutable snapshots | PARTIAL | Snapshots return the stored payload unchanged | Metric-definition version stored with the snapshot | 02/03 |
| DS-P0-REP-05 GA4/store sections | PARTIAL | Store, funnel, attribution (internal), creative, lead, tracking sections | No GA4 section; no budget/pacing section | 08 |
| DS-P0-REP-06 export parity | PARTIAL | CSV, XLSX, PPTX from one payload | **No report PDF**; no cross-format parity test | 08 |
| DS-P0-REP-07 link copy | PARTIAL | Raw token shown once at creation; only a hash and a 12-character hint are stored — an old URL cannot be recovered, and the product must never claim it can | UX that says so and offers a safe replacement link; optional encrypted vault is a separate security-reviewed decision | 02 |
| DS-P0-AUTH-01 unified login | PARTIAL | Single `/login`, workspace switcher, audited switch, revocable memberships; local Playwright login + refresh verified 2026-10-29 | Destination by role/entitlement; return-URL allowlist test; cross-tab race test | 09 |
| DS-P0-AUTH-02 Google sign-in, OTP | NOT_STARTED | None (password only; no sender identity) | Separate login OAuth client; OTP per purpose behind provider config | 09 (`BLOCKED_EXTERNAL_CREDENTIALS` for live) |
| DS-P0-AUTH-03 admin governance | PARTIAL | `/platform` console, plans, entitlements, overrides with reason, audit | Effective-access explainer; support-access scope | 09 |
| DS-P0-AUTO-01..03 rules, agents, approvals | PARTIAL | Seven agents, rules at three levels, approval + carry-out gates with read-back, cooldown, dry run, action history | GA4/store discrepancy findings; run ledger states; replay/dead-letter | 10 |
| DS-P0-NOTIF-01 centre + realtime | PARTIAL | Feed, severity, dedup, per-person digest settings, Reverb nudges | Quiet hours, snooze; subscription re-check after permission change test | 11 |
| DS-P0-NOTIF-02 email templates | NOT_STARTED | Delivery honestly `awaiting_credentials`; no templates | Branded AR/EN responsive template set + plain text | 11 |
| DS-P0-NOTIF-03 delivery states | PARTIAL | `notification_deliveries` states, nothing writes `sent` | `provider_accepted`/`delivered` from a real provider (`BLOCKED_EXTERNAL_CREDENTIALS`) | 11 |
| DS-P0-INT-01 connection diagnostics | PARTIAL | #70 `8bb2fba` disconnect is never read; #74 `5bd92f3` (hourly ad sync skips disconnected connections) | Last-success vs last-attempt; per-connection sync history | 12 |
| DS-P0-INT-02 creative previews | PARTIAL | Aggregate creative read with honest "no preview" | Provider asset resolution needs live credentials | 12 (`BLOCKED_EXTERNAL_CREDENTIALS`) |
| DS-P0-UX-01..03 performance, tables, mobile | PARTIAL | `MobileTabBar`, mobile gate (Chromium, Firefox, WebKit), SWR via TanStack Query | No before/after measurements; 10/25/50 server pagination not everywhere | 13 |
| DS-P0-OPS-01 readiness | PARTIAL | Health, sync monitor, `ops:production-check`, commissioning ledger; queue backlog (14,628 local broadcast jobs) diagnosed and drained 2026-10-29 | One readiness surface | 14 |
| DS-P0-OPS-02 staging/production | BLOCKED_OPERATIONAL_EVIDENCE | Pipeline built; `AWAITING_SERVER_ACCESS` | Server, SSH, secrets, domain/TLS (owner) | 14 |
| DS-P1-MKT, DS-P1-WL | NOT_STARTED | `/welcome` landing exists | After P0 | — |

Counts at `1e19215`: 38 rows — 0 `VERIFIED`, 0 `IMPLEMENTED_NOT_VERIFIED`, 28 `PARTIAL`, 3 `BLOCKED_*`, 7 `NOT_STARTED`. No row is `VERIFIED` because nothing is deployed.
