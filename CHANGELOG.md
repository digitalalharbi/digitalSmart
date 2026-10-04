# Changelog

All notable changes to digitalSmart are recorded here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added — Phase 0: foundation

**Backend**

- Laravel 12 API-only application on PHP 8.4, with the Blade/web scaffolding removed.
- Clean Architecture layers (`Domain`, `Application`, `Infrastructure`, `Interface`)
  plus a composition root, enforced by Pest architecture tests.
- PostgreSQL as the only datastore, with UUIDv7 primary keys on API-facing entities.
- Redis for cache, queue, session and broadcasting; Horizon supervising workers;
  Reverb serving WebSockets.
- `GET /api/health` probing database, cache and queue with a three-valued status
  (`ok`/`degraded`/`down`); 503 only when a dependency is down.
- Security baseline: strict CORS allow-list, Sanctum stateful SPA auth, CSRF, named
  rate limiters (`api`, `health`, `auth`), security headers, stack-disclosure removal,
  validated `X-Request-Id` correlation, secret redaction, production password policy.
- Horizon dashboard access fails closed outside local development.
- Tooling: Pest 4 on PHPUnit 12, PHPStan/Larastan level 8, Pint with strict rules,
  Scramble-generated OpenAPI at `/docs/api`.

**Frontend**

- React 19 + Vite + TypeScript SPA with strict type checking, feature-based structure
  and a lint rule preventing cross-feature deep imports.
- Design system tokens in OKLCH with light and dark themes, bridged into Tailwind v4,
  plus shadcn/ui-style primitives.
- Bilingual shell: Arabic RTL and English LTR with logical layout properties, Arabic
  typography and Latin numerals for figure reconciliation.
- Application shell with rail navigation, a focus-trapped mobile drawer, theme and
  language toggles, skip-to-content and visible focus management.
- Overview page reporting live system health; every unbuilt surface states which phase
  delivers it instead of showing fabricated data.
- Axios client with CSRF priming, locale propagation and a normalised `ApiError`;
  TanStack Query defaults that do not retry definitive 4xx answers.

**Project**

- GitHub Actions CI: backend (Pint, PHPStan, Pest against PostgreSQL and Redis),
  frontend (tsc, ESLint, Prettier, Vitest, build) and end-to-end (Playwright on
  Chromium and mobile Safari).
- `docker-compose.yml` for PostgreSQL and Redis; `Makefile` for setup, dev and verify.
- Documentation set under `docs/`, including six architecture decision records.

### Blocked

- Live ad-platform handshakes are `BLOCKED_EXTERNAL` pending credentials and provider
  approvals; see `docs/INTEGRATIONS.md`.
- Presentation identity awaits the Smart Code reference deck; the theme/token
  abstraction is built first.
