# Client reports and shared dashboards — requirements matrix

The owner's brief (2026-10-20): turn the existing report system into a premium, interactive client experience behind a
shared link. **Not a rebuild** — `ReportBuilder`, `ReportView`, `ReportSectionView`, `SharePanel`, `SharedReportPage`,
snapshots, expiring and revocable links, view counts and the portal already exist.

Status vocabulary as in `CLAUDE.md`. `EXISTS` means it was built before this phase and is covered by tests there;
`NOT_STARTED` means nothing yet. Nothing here is `VERIFIED` without a named test or a browser check.

| # | Requirement | Status | Evidence / what is missing |
|---|---|---|---|
| 3 | Shared link `/shared-reports/{token}`, no login, no admin chrome | EXISTS | `SharedReportPage`, `tests/Feature/Reporting` share tests, `e2e/reports.spec.ts` |
| 3 | Header: client mark, name, title, period, last updated, *Powered by Smart Code* | IMPLEMENTED_NOT_VERIFIED | Hero card with initials (no logo is stored — a logo needs an upload feature); footer *Powered by*; CI pending |
| 4 | Client-safe interactivity: period, comparison, platform / campaign filter, metric selector | NOT_STARTED | Needs a server-side filter contract on `GET /api/shared-reports/{token}` that can only narrow within the report's own scope; its own tests |
| 5 | Hero KPI cards with comparison, change, sparkline | IMPLEMENTED_NOT_VERIFIED | Value, comparison, change, and a sparkline drawn from the report's own trend section where one covers the metric (breaks at a missing day); CI pending |
| 6 | Hero trend, metric switching, previous-period overlay | PARTIAL | Trend chart exists; switching and overlay not |
| 7 | Platform comparison (ranking, rings, share donut) | PARTIAL | Ranking bars plus a share ring for additive metrics across platforms (unit-tested; no demo report has a platform breakdown to look at); ROAS rings not |
| 8 | Campaign ranking cards — top / needs attention | IMPLEMENTED_NOT_VERIFIED | Top three as the server ranked them; *needs attention* = clearly worse (25%) than the report's own ROAS/CPA/CPL, shown with that figure beside it. Only when the report has a campaign breakdown; rules and tests in `highlights.ts`. CI pending |
| 9 | Funnel | IMPLEMENTED_NOT_VERIFIED | Impressions → clicks → (add to cart → checkouts →) purchases, or → leads, or → conversions, built only from stages the report's own scorecards carry; a stage asked for and unread is drawn as not tracked; no sessions stage, because a report holds none. No demo report carries an outcome metric, so it is covered by tests. CI pending |
| 10 | Commerce report (authorities never merged) | IMPLEMENTED_NOT_VERIFIED | A `store` section kind: one named revenue authority, one client; net revenue, orders, average order value, refunds, customers, placed-not-paid. Its own payload (not an ad metric), so a store's revenue can never be confused with the platform's; a second authority is a second section; attributed revenue is refused in a client-safe report. Travels through snapshots, shared links, CSV, workbook and deck. Not yet: revenue trend, by source, store comparison, top products. CI pending |
| 11 | Lead report without PII | IMPLEMENTED_NOT_VERIFIED | A `lead` section kind, for one client: leads, qualified, qualification rate (of leads reached), won, median first contact; leads → reached → won funnel (qualified is shown beside it, because it is where a lead stands now); where leads stand; route mix; response-time buckets; campaigns from the lead table with the unattributed row kept apart; deterministic insight and pointer cards that cite both figures. Aggregates only — no field exists that could carry a name, contact point, note, owner or message. Not built, with reasons: **CPL** (spend comes from the platforms and leads from the client's records — dividing one by the other mixes two authorities, and CPL stays in the ad sections on the platforms' own lead count), **platform ranking** (a lead's platform would be inferred from a free-text `utm_source`), **lost reasons** (`disqualified_reason` is free text a person typed, so there is no category to aggregate without guessing or leaking), **appointment / opportunity stages** (the lead table has none), **Lead Generation preset** (reports have no presets yet — see the builder item). CI pending |
| 12 | Creative performance | BLOCKED_EXTERNAL | No real creative assets or thumbnails exist; placeholders must not be presented as creatives |
| 13–14 | Insight and recommendation cards | IMPLEMENTED_NOT_VERIFIED | Insights from the server's own change figures (≥5% shown, ≥15% in the wrong direction is *needs attention*) plus a best performer from ≥3 campaigns. Two pointers, each with its evidence: a platform whose ROAS is ≥25% above the report's own, and a click-through fall ≥15%. **Note:** the allocation pointer sits against `docs/INTELLIGENCE.md`'s refusal to say where budget should go; it is worded as *consider*, shows both figures, and is the owner's explicit request — say if it should go. CI pending |
| 15 | Data-quality status (Complete / Partial / Estimated / Awaiting) | PARTIAL | `SourceBadge` per section; plain-language mapping not done |
| 16 | Live vs frozen badge | EXISTS | `mode` badge on every report |
| 17 | Share cards: recipient, status, views, expiry, live/frozen; copy, revoke, replace | PARTIAL | Cards with view and expiry chips, turn-off with a reason; *Replace* (create new + turn off old) not one action yet |
| 18 | WhatsApp share (name, period, link; no figures) | IMPLEMENTED_NOT_VERIFIED | `wa.me` link beside the freshly created link; unit test asserts the message carries no figure; CI pending |
| 19 | Link preview (OpenGraph) without figures | NOT_STARTED | SPA-served page; needs a server-rendered preview route |
| 20–21 | Mobile and Arabic RTL | PARTIAL | Overflow verified for the app routes; the shared page and the new hero not yet measured |
| 22–23 | Visual builder with presets | NOT_STARTED | `ReportBuilder` is still a form |
| 24 | Reports index as a library | EXISTS | Cover cards (`ReportCover`) |
| 25 | Saved templates | NOT_STARTED | |
| 26 | Automation (weekly, monthly → snapshot → share → notify; no auto-send) | PARTIAL | Scheduled exports exist; report generation + share does not |
| 27 | Reports agent: deterministic changes, missing data | PARTIAL | Weekly briefs exist |
| 28 | Presentation mode | NOT_STARTED | Presentations module has slides; no report full-screen view |
| 29 | Print / PDF | PARTIAL | Presentations print; report print stylesheet not checked |
| 30 | Data truth rules | EXISTS | `docs/REPORTING.md`; null ≠ zero, source labels, comparability refusals |
| 31 | Link security, rate limit, scope | EXISTS | Token hash, expiry ≤ 90 days, revocation, per-address limit, client-safe only |
| 32 | Independent section loading | NOT_STARTED | One request renders the report today |
| 33 | Agency-side view analytics only | EXISTS | View count and last viewed; no behavioural tracking |

## Order of work

1. **Slice 1 (this PR):** the report header as a hero — mark, title, period and comparison chips, live/frozen, source,
   *Powered by*. Frontend only.
2. **Slice 2:** share cards, WhatsApp share, report library polish.
3. **Slice 3:** new section kinds that need only data the report already has — sparklines on the KPI cards, platform
   share donut, campaign ranking cards, insight cards.
4. **Slice 4 (backend, own tests):** filters on the shared read, server-side and scope-checked; independent section
   loading; link preview route.
5. **Later:** visual builder and presets, templates, automation, presentation mode, creative section when assets exist.
