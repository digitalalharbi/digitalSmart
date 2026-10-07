# digitalSmart

Smart Code advertising operating system for multi-platform paid media management,
project collaboration, creative operations, client reporting, budget intelligence, AI
analysis and presentation generation.

> `CLAUDE.md` is the authoritative product, architecture, design, security, testing and
> execution specification. Read it before making any change.

## Status

**Phases 0–19 complete; Phase 20 in progress.** See `docs/ROADMAP.md` for the per-phase
detail and for the external authorizations that are blocked.

Working today: sign-in, sessions and workspace switching; six system roles with
server-enforced permissions; clients and projects with assignment-based visibility; an
audit trail; six advertising adapters built against published schemas; budgets, pacing
and forecasting; a measurement layer with a conversion registry; Google Analytics 4 and
Tag Manager; a normalised commerce domain with Salla and Zid; store analytics; the Lead
Center; conversion timelines; attribution with coverage on every figure; creative
operations with recorded platform specifications; an operations board; an approvals engine
that records whether an authorised change was ever actually made; and notifications with optional
realtime. In English and
Arabic, light and dark, desktop and mobile.

**Every provider's live handshake is `BLOCKED_EXTERNAL`** on an authorization this
repository cannot grant — an OAuth client, a partner app install, a developer-program
approval. Each adapter is built against the published schema and runs end to end against
documented-schema fixtures, and every figure drawn from one is labelled `SANDBOX` on every
screen that shows it. `docs/INTEGRATIONS.md` names the exact missing credential per
provider.

| Gate                      | Result                                              |
| ------------------------- | --------------------------------------------------- |
| Backend tests (Pest)      | 1 263 passed — architecture, unit, feature          |
| Frontend tests (Vitest)   | 323 passed                                          |
| End-to-end (Playwright)   | 230 passed — Chromium + mobile Safari, signing in as real roles |
| Static analysis           | PHPStan level 8, clean; TypeScript strict, clean    |
| Style                     | Pint, ESLint and Prettier, clean                    |

## Stack

- **Backend** — Laravel 12, API-only, Clean Architecture / DDD, PHP 8.4
- **Frontend** — React 19 + Vite + TypeScript (strict), TanStack Query, Zustand,
  React Router, Tailwind CSS v4 + shadcn/ui-style primitives
- **Data** — PostgreSQL with UUIDv7 keys; JSONB only where justified
- **Async/realtime** — Redis, Laravel Queues, Horizon, Reverb + Echo
- **Quality** — Pest, Vitest + Testing Library, Playwright, PHPStan/Larastan, Pint,
  ESLint, OpenAPI, GitHub Actions

## Quick start

```bash
docker compose up -d     # PostgreSQL + Redis (or use native services)
make setup               # install dependencies, create .env, migrate
make dev                 # API :8001, Horizon, Reverb :8080, SPA :5174
```

Open <http://127.0.0.1:5174>. The Overview page reports live database, cache and queue
status from `GET /api/health`.

Full instructions, prerequisites and troubleshooting: `docs/DEVELOPMENT.md`.

## Verify

```bash
make verify     # Pint, PHPStan, Pest, tsc, ESLint, Prettier, Vitest
make e2e        # Playwright (stack must be running)
```

## Documentation

| Document                 | Contents                                               |
| ------------------------ | ------------------------------------------------------ |
| `docs/PRODUCT.md`        | What digitalSmart is, who it serves, product principles |
| `docs/ARCHITECTURE.md`   | Layering, dependency rules, request flow                |
| `docs/DATA_MODEL.md`     | Database principles, current tables, planned shape      |
| `docs/API.md`            | Conventions, auth, errors, rate limits, endpoints       |
| `docs/INTEGRATIONS.md`   | Provider adapters, isolation model, what is blocked     |
| `docs/MEASUREMENT.md`    | Measurement sources, the event vocabulary, the conversion registry |
| `docs/TRACKING.md`       | Tracking health: the issue codes, and what it refuses to infer |
| `docs/GOOGLE_ANALYTICS.md` | GA4 Admin and Data APIs, sampling honesty, key-event suggestions |
| `docs/GOOGLE_TAG_MANAGER.md` | Container readiness: what it concludes, and what it refuses to |
| `docs/ECOMMERCE.md`      | The normalised store domain, revenue sources, attribution evidence |
| `docs/SALLA.md`          | Salla: status mapping, webhook signatures, reconciliation |
| `docs/ZID.md`            | Zid: two native state axes, custom statuses, Basic Auth webhooks |
| `docs/LEADS.md`          | Leads: a click is not a lead, and every rate's denominator |
| `docs/JOURNEYS.md`       | Journeys, identity evidence, and why multi-touch is blocked |
| `docs/ATTRIBUTION.md`    | Attribution models, coverage, and the three this product refuses |
| `docs/CREATIVE.md`       | Creative operations, and why a specification is recorded knowledge |
| `docs/OPERATIONS.md`     | The work board: undated work, and when overdue means lateness |
| `docs/DATA_VISUALISATION.md` | The measured chart palette, its gates, and the mark rules |
| `docs/DESIGN_SYSTEM.md`  | Tokens, theming, typography, motion, RTL, accessibility |
| `docs/SECURITY.md`       | Controls, threat notes, secret handling                 |
| `docs/TESTING.md`        | Test layers, database strategy, conventions             |
| `docs/DEVELOPMENT.md`    | Ports, setup, running, troubleshooting                  |
| `docs/ROADMAP.md`        | Phase status and what is blocked                        |
| `docs/adr/`              | Architecture decision records                           |

## Product principles

- Premium global SaaS quality; Apple-inspired clarity and restraint, never a clone
- Adjust-inspired analytics: configurable dashboards, drill-down, comparison, report builder
- CampaignsHub-inspired isolation, integration bindings, tasks, notifications, auditability
- Arabic RTL and English LTR are both first-class and designed
- Never fabricate business data; never simulate an integration that is not connected
