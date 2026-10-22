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
| 4 | Client-safe interactivity: period, platform, campaign, store filters | IMPLEMENTED_NOT_VERIFIED | `GET /api/shared-reports/{token}` takes `platform`, `campaign`, `store`, `from`, `until` and narrows only: every value is looked up inside the link's own scope (derived from the report, its client, filters and resolved period — never from the request) and one not found is refused with 422 `filter_out_of_scope`; the page drops it and shows the whole report with a sentence. Campaigns and stores travel as opaque keys derived from the link's id. Live links only: a frozen link offers no filter and ignores a requested one — it never re-queries. Period filter drops the comparison (the stretch before a sub-range was never part of the link). Lead and store sections are hidden under a platform or campaign filter (they carry neither). A refinement (`refine=1`) is not a view. Not supported: project filter (no builder surface makes a multi-project report; no name source), metric selector. CI pending |
| 5 | Hero KPI cards with comparison, change, sparkline | IMPLEMENTED_NOT_VERIFIED | Value, comparison, change, and a sparkline drawn from the report's own trend section where one covers the metric (breaks at a missing day); CI pending |
| 6 | Hero trend, metric switching, previous-period overlay | PARTIAL | Trend chart exists; switching and overlay not |
| 7 | Platform comparison (ranking, rings, share donut) | PARTIAL | Ranking bars plus a share ring for additive metrics across platforms (unit-tested; no demo report has a platform breakdown to look at); ROAS rings not |
| 8 | Campaign ranking cards — top / needs attention | IMPLEMENTED_NOT_VERIFIED | Top three as the server ranked them; *needs attention* = clearly worse (25%) than the report's own ROAS/CPA/CPL, shown with that figure beside it. Only when the report has a campaign breakdown; rules and tests in `highlights.ts`. CI pending |
| 9 | Funnel | IMPLEMENTED_NOT_VERIFIED | Impressions → clicks → (add to cart → checkouts →) purchases, or → leads, or → conversions, built only from stages the report's own scorecards carry; a stage asked for and unread is drawn as not tracked; no sessions stage, because a report holds none. No demo report carries an outcome metric, so it is covered by tests. CI pending |
| 10 | Commerce report (authorities never merged) | IMPLEMENTED_NOT_VERIFIED | A `store` section for one named revenue authority and one client: headline figures, then — each hidden when it has nothing to show — net revenue and orders by day (a day without orders is a gap, never a zero), store comparison (only with two or more stores in one currency; shares of net revenue), where the orders came from (what each order carried; the not-traced share is its own slice and the ring's centre), products as sold (before refunds, which are recorded against orders), new and returning customers (counts only), refund quality (amount, orders with a refund, rate of gross sales, a warning only against an earlier rate), deterministic insight cards and evidence-citing pointers. Comparison period is read for the same authority and only when the periods are comparable. Travels through snapshots and shared links; CSV, workbook and deck still carry the headline figures only. **Not supported, with reasons:** conversion rate and commerce funnel (a store report holds no sessions or checkout counts; dividing orders by ad clicks would be a different claim under the same name), product share (line revenue is not order revenue — shipping, tax and discounts differ), refund *count* of refund events (orders with a refund is counted instead). CI pending |
| 11 | Lead report without PII | IMPLEMENTED_NOT_VERIFIED | A `lead` section kind, for one client: leads, qualified, qualification rate (of leads reached), won, median first contact; leads → reached → won funnel (qualified is shown beside it, because it is where a lead stands now); where leads stand; route mix; response-time buckets; campaigns from the lead table with the unattributed row kept apart; deterministic insight and pointer cards that cite both figures. Aggregates only — no field exists that could carry a name, contact point, note, owner or message. Not built, with reasons: **CPL** (spend comes from the platforms and leads from the client's records — dividing one by the other mixes two authorities, and CPL stays in the ad sections on the platforms' own lead count), **platform ranking** (a lead's platform would be inferred from a free-text `utm_source`), **lost reasons** (`disqualified_reason` is free text a person typed, so there is no category to aggregate without guessing or leaking), **appointment / opportunity stages** (the lead table has none), **Lead Generation preset** (now built — see the builder row). CI pending |
| 12 | Creative performance | BLOCKED_EXTERNAL | No real creative assets or thumbnails exist; placeholders must not be presented as creatives |
| 13–14 | Insight and recommendation cards | IMPLEMENTED_NOT_VERIFIED | Insights from the server's own change figures (≥5% shown, ≥15% in the wrong direction is *needs attention*) plus a best performer from ≥3 campaigns. Two pointers, each with its evidence: a platform whose ROAS is ≥25% above the report's own, and a click-through fall ≥15%. **Note:** the allocation pointer sits against `docs/INTELLIGENCE.md`'s refusal to say where budget should go; it is worded as *consider*, shows both figures, and is the owner's explicit request — say if it should go. CI pending |
| 15 | Data-quality status (Complete / Partial / Estimated / Awaiting) | PARTIAL | `SourceBadge` per section; plain-language mapping not done |
| 16 | Live vs frozen badge | EXISTS | `mode` badge on every report |
| 17 | Share cards: recipient, status, views, expiry, live/frozen; copy, revoke, replace | PARTIAL | Cards with view and expiry chips, turn-off with a reason; *Replace* (create new + turn off old) not one action yet |
| 18 | WhatsApp share (name, period, link; no figures) | IMPLEMENTED_NOT_VERIFIED | `wa.me` link beside the freshly created link; unit test asserts the message carries no figure; CI pending |
| 19 | Link preview (OpenGraph) without figures | NOT_STARTED | SPA-served page; needs a server-rendered preview route |
| 20–21 | Mobile and Arabic RTL | PARTIAL | Overflow verified for the app routes; the shared page and the new hero not yet measured |
| 22–23 | Visual builder with presets | IMPLEMENTED_NOT_VERIFIED | A new report is made in six steps — client, type, period, content, preview, who it is for (`ReportWizard`). Six preset cards (Executive, Performance, Lead generation, E-commerce, Weekly, Monthly executive) set the period, the comparison and the sections, each filtered through the server's own vocabulary so a preset cannot build what the server would refuse; Lead generation and E-commerce need a client and are disabled without one; sections can be removed or added before anything is saved. Nothing is written until the last step, which has **no default audience** — the button stays off until the report is marked for the client or the team. The previous form remains the editor for an existing report. **Not built:** the Campaign report preset (needs a campaign scope picker and the `campaign_ids` filter carried by the builder), and a data preview (the preview is the report's shape; figures exist only once it is saved). CI pending |
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


## Surface A — Analytics, on the same visual foundation

The internal Analytics page reads the one analytics endpoint and shows it with the report system's own pieces:

- **KPI strip** with a sparkline per figure, drawn from the same daily series the hero chart plots; a metric chip row points the hero chart at spend, revenue, ROAS, conversions, CPA, clicks or CTR.
- **Platforms**: a card per platform with its own figures and, where the spend is one currency, a share ring. A card is a filter.
- **Insights, funnel, campaign cards (top / needs attention) and pointers** are `ReportHighlights` over `analyticsAsReport(...)` — the same functions the client's live report uses, over the same figures in the same shape. No second implementation of any of them.
- **Budget pacing** is the Budget Control Center's own `PacingHero` from `/budgets/control`, following the working context.
- **Revenue by who says so** (one client in context only): ad platforms' claim, then Salla / Zid / Google Analytics each as their own card — never added.
- Efficiency scatter and the breakdown table are **detail**, behind one disclosure.
- Follows the working context (one client / one project / everything).

Not built: a previous-period overlay on the hero chart (the endpoint returns the comparison as a figure per metric, not as a series), the objective filter, and a content / creative panel (Slice E).

### Campaigns and platforms (Slice C)

- **Platform cards on the Campaigns page** are the Analytics page's `PlatformPanel`, over the same endpoint, period and context; a card sets the page's platform filter (a second tap clears it) and narrows the list, the health ring and the totals. The panel ignores that filter itself, so another platform can still be chosen.
- **Campaign detail**: the hero chart (`HeroTrend`, seven figures, one at a time — never two on an axis, and not drawn for a money figure with no currency) and the funnel and insights (`ReportHighlights` over `analyticsAsReport`) read the analytics endpoint narrowed to the campaign, so the page and the client's live report use one reader. Ad groups → ads and the creatives are the existing `Hierarchy`. Budget pacing is the Budget Control Center's bars for the campaign's client and project.
- **Not built, with reasons:** audience and placement breakdowns (the platforms' reporting for them is not synced, and an ad group's audience is a name, not a measurement); an attribution card on one campaign (a store records orders by the UTM campaign *name* it carried, not by this campaign's id, so a comparison with the client's store total would compare different scopes — the Attribution page compares by name, with its own coverage figure).

### The funnel (Slice D)

One primitive, `FunnelBars`, for every funnel: the report's, the Analytics page's, a campaign's and a lead section's. Stages come only from what the figures carry — impressions, clicks, then (for purchases) add to cart and checkout, then the outcome — and a stage the figures do not carry is not invented; one that was asked for and could not be read is drawn as not tracked.

- **Each stage** shows its count, its rate over the stage before, the drop-off, and the server's own change against the earlier period where one was given (coloured by the metric's own good-or-bad direction, never by direction alone).
- **A step has no rate** where either count is missing, the earlier one is zero, or the step is larger than the one before it (a different population) — never zero, never over a hundred.
- **The biggest drop** is the lowest-rate step from the third stage on in an advertising funnel — impressions to clicks is click-through rate, always the steepest and judged on its own — and from the first step in a lead funnel. It is marked only when at least two steps compete; one candidate would be the largest by default, and a highlight that cannot fail to appear is not information.
- **Not built:** a commerce funnel from sessions to purchase. The store sections hold orders, not sessions or checkout starts, and an analytics property's sessions are a different authority; stages from two authorities are not joined into one funnel.

### Creatives (Slice E)

On a campaign's page, **Creatives**: media-first cards in four views — Top, Rising, Fatiguing, Needs replacement — and a comparison of two to four, drawn as bars with the winner marked, plus spend and results by creative format as rings.

- **Rules, not a model.** A creative is judged once it has at least 1,000 impressions (the Creative agent's own floor); below that it is *not enough data* and in no list. Fatiguing is a click-through change of −25 % or worse against the week before (the same line the agent and the hierarchy tree use); rising is +25 % or better; *needs replacement* is a fatiguing creative that is also under the campaign's median click-through rate. Top is ordered by results, then click-through, then spend. Each card carries the figures its label rests on.
- **Shares only of what adds up.** The format rings are spend and results; no format-level ratio is computed.
- **Thumbnails** are the creative's own when the platform supplied one, and a format icon when it did not — the demo has no artwork, and the card does not pretend otherwise.
- **Same data as the tree.** The ads and creatives come from the hierarchy read (`useCampaignHierarchy`, one key, shared with the tree); their figures from the analytics endpoint by ad, over the shared period.
- **Not built, with reasons:** organic content performance (reach, engagement and conversions for content that is not an ad are not synced, and nothing is invented); audience and placement cuts; a workspace-wide creative view (no aggregate read of creatives across campaigns exists yet); a creative section in the client's shared report (the report system has no creative section kind, and without real assets it would be a grid of placeholders).

### Presentation mode (Slice F)

Any report — the agency's own view or a shared link — can be opened as a talk: **Present** in the report's header, or `?present=1` on the link. It is the same report in the same section views, shown full screen one story at a time: a cover, the headline figures as large tiles (written short, the whole figure in its title), how they moved, the rankings, the campaigns, the funnel, a store or leads, what stands out, what to look at, and a closing data-status screen (source, live or frozen, rendered at, the caveats). A screen exists only if the report can tell that story — no funnel screen without the stages, no screen of "nothing recorded".

- **Keyboard and touch follow the page's direction.** Forward is the right arrow in English and the left arrow in Arabic; Page Down / Space also go forward, Home and End jump; a swipe goes the way a page of that language turns. Escape leaves and focus returns to what opened it. `F` toggles full screen. The page behind does not scroll; a live region announces "Slide n of N: title".
- **Filters carry in.** A filtered shared link presents the filtered report; the deck re-reads nothing.
- **Not built, with reasons:** a creative screen with a large preview and its metric panel (the report system has no creative section, and without real assets it would be a placeholder; creative intelligence lives on a campaign's page), speaker notes, and exporting the deck from this view (the PPTX export is the Presentations module's).

## Creative data foundation — `GET /api/analytics/creatives`

One aggregate read of how creatives performed, on the same engine as every other figure (`AnalyticsReadModel` over
`daily_metrics`). Nothing here computes a metric of its own.

| Rule | How |
|---|---|
| Asset ≠ ad instance | `grain=asset` groups ad rows by the creative they run (`ads.creative_id`); `grain=ad` keeps each ad. Names and captions are never used to merge. An ad with no known creative is absent from asset grain, present at ad grain. |
| Same money as the ad rows | Level is pinned to `ad` rows, so a campaign's spend is never added to its ads'. |
| Scope | Workspace, visible projects (assignment), client, project, platform, ad account, campaign, date range (≤ 400 days). |
| Bounded | Cohort = top 500 by spend; pages of ≤ 100 with `offset`/`has_more`; trends for the page's keys in one grouped query; no per-creative provider call at render. |
| Media | `CreativeMedia` — real https or app-hosted images only. Fixture hosts (`.invalid`, `.test`, …) are `state: fixture` with no URL; credential-bearing URLs and `http:` are dropped; no URL ⇒ `state: none`. The UI draws a neutral placeholder, never invented artwork. |
| Missing ≠ 0 | Unreported figures are `null` with the layer's `unavailable_reason`. Frequency is `null` where the platform reports no reach. |
| Verdicts | `CreativeAssessor`, deterministic, no model. No verdict below 1,000 impressions in either period; *fatiguing* needs CTR −25 % **and** a corroborating signal (frequency up, cost per result up, conversion rate down); a CTR fall alone is returned as evidence, unclassified. *Rising*, *top* and *weak* need a cohort of ≥ 4. Every verdict ships its evidence. |
| No internal ids | No provider/external ids, account ids or tokens in a row; a name that merely embeds the provider's id ("Ad 4011…") is stripped to "Ad". |

Organic content analytics remains `NOT_SUPPORTED`.

### Creatives page (`/creatives`, Content & Creative area)

Media-first cards from the aggregate read above: Top / Rising / Fatiguing / Needs replacement views with counts, filters
(client, project, platform, format, one campaign via `?campaign=`, the shared period), a format mix and a platform mix
(by spend when the shown creatives share one currency, otherwise by count — never a cross-currency share), and a
2–4 creative comparison (one bar chart per figure plus each creative's CTR spark). A tile draws only an address the
server vouches for, with no referrer; otherwise it says "Sample data — no preview" or "No preview from the platform".
Organic content analytics remains `NOT_SUPPORTED`.

### Creative report section (`kind: creative`)

A report block with five views (`creative_view`): **top** (the layer's top-rated creatives), **performance** (largest by
spend, a table), **format_mix** (money by format, or a count when the creatives are paid in two currencies),
**comparison** (the four largest side by side) and **refresh** (wearing out or weak). Each view is a read of the same
creative layer as the Creatives page (`CreativeIntelligenceReader`), inside the report's own client / platform /
campaign scope — so, unlike store and lead sections, it **follows a visitor's platform and campaign narrowing** on a
shared link.

- No identifier leaves: rows carry a position, a name, the platform's name, a format, a vetted preview reference, the
  figures, a daily CTR spark, a plain verdict and its evidence — never a creative, ad, campaign or account id.
- A frozen snapshot freezes the section like any other.
- CSV, XLSX and the PPTX deck carry the creatives as a table (spend, impressions, CTR, results, cost per result).
- Needs a migration (`creative_view` column, widened kind check); reversible.
- Presets: *Performance* now ends with top and refresh creatives.

### Presentation mode — creative screens

A creative section's **top** and **refresh** views give each creative a screen of its own: the picture (or the honest
"no preview" tile) over roughly two thirds of a desktop screen and the whole width first on a phone, with name,
platform, campaign, spend, CTR, CPA, results, and ROAS / frequency only where valid, a CTR spark, and the plain verdict
with its CTR change. A video is its poster only. The refresh story runs tiring creatives before failed ones. Performance,
format mix and comparison views are one screen each, drawn by the same components as the report page. Screens are built
from the payload the page received, so a narrowed or frozen shared link presents exactly what it shows; the existing
arrows, swipe, Escape and fullscreen apply.

### Creative report, visual upgrade (PR C)

Two more views — **gallery** (the largest creatives as media cards, with the scope's own totals and daily series from
the analytics layer beside them) and **platform_mix** (each platform's creatives: a share ring, count, spend, results,
CTR, CPA; money withheld across two currencies). The report page gains a **creative hero** when a gallery section is
present: total creatives, spend, results, average CTR, average CPA (only where the layer published them), the leading
creative by ROAS (else CTR), at most four insights (best CTR, highest ROAS, fatigue with its CTR change, most results by
platform) and at most three recommendations (refresh with evidence; scale the ROAS leader with a cost to stand on). The
gallery's trend switches one figure at a time — spend, CTR, CPA, results, ROAS, frequency — never two on one axis. A
*Creative performance* preset composes all of it. Lifecycle states beyond the five verdicts (New, Learning, Scaling)
and content analysis beyond format (hook/theme, creator, UGC vs branded) need metadata the platforms do not sync and
are `NOT_SUPPORTED` rather than inferred.

### Creative filters and the presentation sequence (PR D)

A shared link can be narrowed by **creative** (`creative=` opaque keys the link handed out, offered only when the
report shows creatives and only when there is more than one) and by **format** (`format=` the format's own slug, from
the report's creatives); both only narrow, both are looked up inside the link's scope, and a value outside it is
refused with the rest of the filters. Presentation mode orders the creative story — summary (figures and insights), the
gallery, the top creatives one per screen, the comparison, the ones to refresh, the format and platform mixes, then the
recommendations on a screen of their own — whatever order the sections were saved in.
