# digitalSmart

Smart Code advertising operating system for multi-platform paid media management,
project collaboration, creative operations, client reporting, budget intelligence, AI
analysis and presentation generation.

> `CLAUDE.md` is the authoritative product, architecture, design, security, testing and
> execution specification. Read it before making any change.

## Status

**Phases 0 and 1 complete.** See `docs/ROADMAP.md`.

Working today: sign-in and sessions, workspaces with switching, six system roles
with server-enforced permissions, clients, projects with assignment-based
visibility, project and workspace membership, and an audit trail — in English
and Arabic, light and dark, desktop and mobile.

| Gate                      | Result                                              |
| ------------------------- | --------------------------------------------------- |
| Backend tests (Pest)      | 160 passed, 451 assertions — architecture, unit, feature |
| Frontend tests (Vitest)   | 56 passed                                           |
| End-to-end (Playwright)   | Chromium + mobile Safari, signing in as real roles  |
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
