# Roadmap

Phases come from `CLAUDE.md`, which is authoritative. Work proceeds in vertical slices:
every substantial feature ships migrations, domain/application code, API contract,
frontend UI, tests and documentation together. The repository stays runnable at all
times.

| Phase | Scope                                                                          | Status |
| ----- | ------------------------------------------------------------------------------ | ------ |
| 0     | Repository baseline, dev stack, CI, formatting, linting, tests, env examples, architectural skeleton | **Complete** |
| 1     | Auth, workspaces, users, roles, permissions, clients, projects, audit logs      | Next   |
| 2     | Integrations foundation, provider connections, external accounts, project bindings, credential encryption | Planned |
| 3     | Campaign data model, sync jobs, normalised metrics, analytics ingestion         | Planned |
| 4     | Premium dashboard, filters, saved views, charts, campaign explorer              | Planned |
| 5     | Budgets, pacing, calculator, forecasting, alerts                                | Planned |
| 6     | Creative hub, tasks, approvals, files, notifications/realtime                   | Planned |
| 7     | Reports, shared client portals, exports                                         | Planned |
| 8     | AI assistant/recommendations with approval gates                                | Planned |
| 9     | Presentation generator                                                          | Planned |
| 10    | Provider write operations, only where API and permissions support them          | Planned |
| 11    | Hardening, load/performance, security, accessibility, observability, backup/recovery, production readiness | Planned |

## Phase 0 — delivered

- Laravel 12 API-only backend, Clean Architecture layers (`Domain`, `Application`,
  `Infrastructure`, `Interface`) with **executable** architecture tests.
- React 19 + Vite + TypeScript (strict) SPA: TanStack Query, Zustand, React Router,
  Axios interceptors, Tailwind v4 + shadcn-style primitives.
- PostgreSQL with UUIDv7 keys; Redis for cache, queue, session and broadcasting;
  Horizon supervising workers; Reverb serving WebSockets — all verified running.
- `GET /api/health` probing database, cache and queue, with a three-valued status.
- Security baseline: strict CORS, Sanctum SPA auth, CSRF, rate limiters, security
  headers, stack-disclosure removal, validated request correlation ids.
- Design system tokens (OKLCH, light/dark), bilingual AR-RTL / EN-LTR shell, mobile
  navigation drawer, accessible focus and reduced-motion handling.
- Gate: Pint, PHPStan level 8, 33 backend tests, 27 frontend tests, 12 Playwright tests
  across Chromium and mobile Safari, all wired into GitHub Actions.

## Blocked

| Item                     | Blocker                                               |
| ------------------------ | ----------------------------------------------------- |
| Live provider handshakes | `BLOCKED_EXTERNAL` — credentials/approvals required. See `docs/INTEGRATIONS.md`. |
| Presentation identity    | Awaiting the Smart Code reference deck. The theme/token abstraction is built first, per `CLAUDE.md`. |

Neither blocker stops other work: contracts, mocks, UI, tests and documentation are
built around them.
