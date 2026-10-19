# Closure ledger

What is finished, what proves it, and what is waiting on somebody outside this repository.

One row per product area. A row is **closed** only when the behaviour exists, a test fails if it stops
existing, and the figures it publishes are ones somebody could defend to the client being billed. A row that
is waiting names the exact thing it waits for, because "integration pending" is not a status anybody can act
on.

Three blocker codes, and nothing else is allowed to stand in for them:

| Code | Means |
| ---- | ----- |
| `BLOCKED_EXTERNAL_CREDENTIALS` | A key, a token, a developer-programme admission or a consenting account. Everything around the call is built and exercised against response-shaped fixtures |
| `BLOCKED_EXTERNAL_APPROVAL` | A provider has to approve an application or a scope before the call is permitted at all |
| `BLOCKED_OPERATIONAL_EVIDENCE` | Built and testable, but the figure in a document would have to come from a host that does not exist yet |

## The areas

| Area | Internal implementation | Tests | Production evidence | External blocker | Status |
| ---- | ----------------------- | ----- | ------------------- | ---------------- | ------ |
| **Agency** — clients, projects, project access, tasks, client requests, creative, approvals | Complete. Project membership is the only thing that grants sight of a client's data, and the dialog that grants it exists | `tests/Feature/{Client,Project,Operations,Creative}`, `e2e/{clients,projects,tasks,requests,approvals,creative}.spec.ts` | Query budgets asserted; surfaces measured under axe | — | **CLOSED** |
| **Advertising** — campaigns, budgets, pacing, platform writes | Complete against documented shapes. Writes are attempted only where that provider's current reference was read for that operation; support is `documented` / `unverified` / `not supported`, and `unverified` is not a smaller yes | `tests/Feature/{Advertising,Budget}`, `e2e/{analytics,budgets}.spec.ts` | Sandbox fixtures, labelled `SANDBOX` on every row and screen | `BLOCKED_EXTERNAL_CREDENTIALS` for every provider; `BLOCKED_EXTERNAL_APPROVAL` for Google Ads' developer token and for write scopes | **CLOSED** except the live handshake |
| **Measurement** — sources, conversions, tracking health, containers | Complete. A property or a container is registered from discovery; a source can be removed, and the removal names what it takes with it | `tests/Feature/Measurement`, `e2e/tracking.spec.ts` | GA4 and GTM read fixtures in sandbox | `BLOCKED_EXTERNAL_CREDENTIALS` — a Google Cloud OAuth client and a consenting user | **CLOSED** except the live handshake |
| **Commerce** — stores, orders, customers, revenue | Complete. A merchant is connected to a client through discovery, read on demand and nightly, and the figures carry the authority that claimed them | `tests/Feature/Commerce`, `e2e/commerce.spec.ts` | Salla and Zid fixtures, labelled `SANDBOX` | `BLOCKED_EXTERNAL_CREDENTIALS` — a Partner app and a merchant who installs it | **CLOSED** except the live handshake |
| **Leads** — intake, pipeline, ownership, merging, CRM bridge | Complete. Where a CRM owns the stage, this product refuses to move it and says where to; a stage merely *called* "Won" is not a win | `tests/Feature/Leads`, `e2e/leads.spec.ts` | HubSpot fixtures | `BLOCKED_EXTERNAL_CREDENTIALS` — a HubSpot developer account | **CLOSED** except the live handshake |
| **Attribution** — models, coverage, evidence | Complete. A model it cannot compute is offered disabled with the reason, and coverage travels with every attributed figure | `tests/Feature/Attribution`, `e2e/attribution.spec.ts` | Sandbox data | — | **CLOSED** |
| **Operations** — the board, the queues, assignment, notifications | Complete. Four tables carry an assignment, all four behind one directory; removal releases open work and reports what it released | `tests/Feature/{Operations,Notifications,Workspace}`, `e2e/{tasks,requests,notifications,administration}.spec.ts` | Realtime is optional and the page says which mode it is in | `BLOCKED_EXTERNAL_CREDENTIALS` for a WebSocket server; polling is the honest fallback and is labelled | **CLOSED** |
| **Analytics & dashboards** | Complete, including saved views and the pattern channel for charts | `tests/Feature/{Analytics,Dashboard}`, `e2e/{analytics,dashboards}.spec.ts` | Sandbox figures throughout | — | **CLOSED** |
| **Reports** — definitions, snapshots, sharing, exports | Complete. A snapshot returns its stored payload unchanged, which is the only honest answer to "the report said this" | `tests/Feature/Reporting`, `e2e/reports.spec.ts` | Workbook and PDF exports verified in both browsers | — | **CLOSED** |
| **Client portal** | Complete. Module grants decide what a client sees; internal reports, notes, assignees and diagnostics are excluded in SQL rather than hidden in a view | `tests/Feature/Portal`, `e2e/portal.spec.ts` | — | — | **CLOSED** |
| **AI assistant** | Complete contract, built and tested against documented-shape fixtures, with the caveats stated before any answer | `tests/Feature/Intelligence`, `e2e/assistant.spec.ts` | Fixtures only | `BLOCKED_EXTERNAL_CREDENTIALS` — a model API key | **CLOSED** except the live call |
| **Presentations** | Complete. PowerPoint is a real download; the PDF comes from the browser and the page says why | `tests/Feature/Presentation`, `e2e/presentations.spec.ts` | — | — | **CLOSED** |
| **Administration** — workspace, people, roles, permissions, audit | Complete. Onboarding to first login, forced password choice, suspension, removal with release, role change, project access, and the audit trail behind all of it | `tests/Feature/{Workspace,Identity,Audit}`, `e2e/administration.spec.ts` | Login throttling and per-bucket rate limits active | — | **CLOSED** |
| **Agency UX and the sandbox agency** — role homes, campaign control, project and client workspaces, creative desk, reports and decks, administration, `demo:agency` | Complete for every surface this session owns: one composition per role, summary / detailed modes, context sidebars, background work, digests, contextual assistant, a six-step tour and a sandbox notice on every demo screen. Where a platform's write is documented the card can *ask* for a pause or resume; it never performs one | `tests/Feature/{Demo,Advertising,Portfolio,Creative,Digest,Workspace}`, `e2e/{journeys,dead-controls,responsive,accessibility-surfaces}.spec.ts` (the first two need `E2E_AGENCY=1`), `docs/DEMO.md` | Measured against sixty sandbox clients: every agency endpoint 40–230 ms warm; axe clean in light/English and dark/Arabic; no surface wider than a phone | — | **CLOSED** except the three surfaces in the next paragraph |
| **Agents & Automation** — rules, agents, routines, action history, change log | Complete at campaign level. Rules control, agents reason and propose, approvals govern, execution only where the platform's write is documented. Auto refused and unstorable; bid unsupported; capability checked on save, dry run and action | `tests/Feature/{Automation,Agents,Digest}`, `tests/Feature/Advertising/Campaign{FiguresLevel,AggregateConsumers}Test.php`, `e2e/automation.spec.ts` | Sandbox figures only | `BLOCKED_EXTERNAL_CREDENTIALS` for every platform write | **CLOSED** at campaign level; ad-group / ad level is the next expansion |

## Two absences, recorded rather than implied

**There is no teams entity.** People are workspace members with a role, and grouping for access is project
membership — which is what actually decides who can see a client's data. A teams layer would be a second
grouping with no permission of its own, and the first question about it would be which of the two wins.

**There is no password reset.** It needs an email sender identity, which this repository does not have; the
honest interim is an owner setting a member's password out of band, and `docs/SECURITY.md` records the whole
reasoning rather than half-building a token nobody can deliver.

## What is not closed

**Held by another working session, not by a missing decision.** The attribution family, the media planner and the
store-analytics page still carry uncommitted money-formatting work from a parallel session, and so do four lead
sub-panels. Their hundred-client pickers, the commerce analytics-first recomposition and the media-planner visuals are
waiting for that work to be committed — editing around it would collide with it.

**Period comparison is not yet one shared control.** Analytics, reports and the dashboards offer it; the control
centre chooses a window; the budget and lead pages do not compare. A single control across all of them is the next
slice.


**Held files return to the closure queue the moment the parallel work commits:** `AttributionPage`, `MediaPlannerPage`,
`StoreAnalyticsPage` and the lead panels `LeadWorkPanel`, `PipelineList`, `JourneyPanel`, `BreakdownChart`.

**Server-written English sentences appear in English in Arabic mode.** Reasons, recommendations and some notices are
composed on the server as prose. The long-term fix is structured reason codes from the API (`code` + parameters) that
the frontend localises, as it already does for labels; until then those sentences are a known localisation gap. It does
not block the demo and is not closed.

**Creative previews are shape-based, not the provider's thumbnails.** Uploads are stored attachment-only and never served
inline by design, and no provider has issued credentials to fetch real creative. Serving demo thumbnails would mean
inventing provider content or opening an inline-serving path with its own review; neither was done. Remaining product
work, once a real creative source exists.

**Keyboard access is verified for a defined set, not for everything.** `e2e/keyboard.spec.ts` walks nine surfaces with Tab
(every stop visible and ringed, no stall), opens the command palette and a campaign task dialog from the keyboard, checks
focus stays inside and returns on Escape, and moves project tabs with the arrow keys. It found and fixed two real faults:
Escape dropped focus onto the page body for every dialog opened from state, and a project could be opened only from a row
menu. Not yet covered: the Lead Kanban by keyboard, Creative Hub board drag alternatives, and the report and presentation
controls. A screen-reader pass by a person is still outstanding.

**The dashboard builder specs are not proven stable.** Under two workers, repeated runs went 22/22, 66/66 and 24/24 on a
quiet machine, but two earlier runs on a machine under heavy external load saw a saved dashboard missing from the picker
after reload. One genuine harness fault was found and fixed (names collided under `--repeat-each`); the load-related
residue was not reproduced and has no confirmed root cause. Treat as open.

**Agent layer — what is not built.** Creative, Leads, Reports and Project-operations agents (their analyses exist
as product features, not as agents); an outbound MCP gateway to the providers' own MCP servers (the direct adapters
are the only route); WhatsApp approvals; campaign creation and a cross-platform campaign builder; ad-group / ad
hierarchy and bulk actions in the Campaigns screen. See `docs/AGENTS.md` for the honest table.

**Shared period comparison** is still not one control across every area.

Nothing internally executable. The production document's own *What is still missing* list is the remainder,
and every item on it is `BLOCKED_OPERATIONAL_EVIDENCE`: no container images or pipeline, no staging
environment, no error tracker or metrics backend, no uptime monitor polling the health endpoint, and no host
running the one cron the schedule needs. Each is a decision and an account rather than code.
