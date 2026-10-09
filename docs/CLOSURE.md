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

## Two absences, recorded rather than implied

**There is no teams entity.** People are workspace members with a role, and grouping for access is project
membership — which is what actually decides who can see a client's data. A teams layer would be a second
grouping with no permission of its own, and the first question about it would be which of the two wins.

**There is no password reset.** It needs an email sender identity, which this repository does not have; the
honest interim is an owner setting a member's password out of band, and `docs/SECURITY.md` records the whole
reasoning rather than half-building a token nobody can deliver.

## What is not closed

Nothing internally executable. The production document's own *What is still missing* list is the remainder,
and every item on it is `BLOCKED_OPERATIONAL_EVIDENCE`: no container images or pipeline, no staging
environment, no error tracker or metrics backend, no uptime monitor polling the health endpoint, and no host
running the one cron the schedule needs. Each is a decision and an account rather than code.
