# Local development

## Ports

| Service        | URL                       | Notes                                   |
| -------------- | ------------------------- | --------------------------------------- |
| API            | http://127.0.0.1:8001     | Laravel, API-only                        |
| SPA            | http://127.0.0.1:5174     | Vite dev server, proxies `/api` to 8001  |
| Reverb (WS)    | ws://127.0.0.1:8080       | Realtime                                 |
| Horizon        | http://127.0.0.1:8001/horizon | Queue dashboard (local only by default) |
| OpenAPI        | http://127.0.0.1:8001/docs/api | Generated from the code              |
| PostgreSQL     | 127.0.0.1:5432            | databases `digitalsmart`, `digitalsmart_test` |
| Redis          | 127.0.0.1:6379            | cache, queues, sessions, broadcasting    |

8001/5174 are deliberate: 8000/5173 are commonly taken by other local stacks.

## Prerequisites

PHP 8.2+ (developed on 8.4) with `pdo_pgsql`, `mbstring`, `intl`, `bcmath`, `sodium`;
Composer 2; Node 20+ (developed on 24); PostgreSQL 16+; Redis 7+.

The Redis **PHP extension is not required** — the project uses `predis`, a pure-PHP
client, so the stack runs on a plain PHP install.

## Option A — Docker (PostgreSQL + Redis only)

```bash
docker compose up -d
```

The application itself runs on the host; only the datastores are containerised, which
keeps the edit/refresh loop fast.

## Option B — Native services (macOS)

```bash
brew services start postgresql@16
brew services start redis
createuser --createdb --pwprompt digitalsmart   # password: digitalsmart
createdb -O digitalsmart digitalsmart
createdb -O digitalsmart digitalsmart_test
```

## First run

```bash
make setup
```

Or by hand:

```bash
cd backend && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate
cd ../frontend && npm install && cp .env.example .env.local
```

## Running everything

```bash
make dev
```

That runs the API, Horizon, Reverb and the Vite dev server together. Individually:

```bash
cd backend  && php artisan serve --port=8001
cd backend  && php artisan horizon
cd backend  && php artisan reverb:start
cd frontend && npm run dev
```

Open http://127.0.0.1:5174 — the Overview page shows live database, cache and queue
status pulled from `GET /api/health`.

## Verification gate

```bash
make verify      # everything CI runs, except end-to-end
make e2e         # Playwright, needs the stack running
```

Backend only:

```bash
cd backend && composer verify     # Pint + PHPStan (level 8) + Pest
```

Frontend only:

```bash
cd frontend && npm run verify     # tsc --strict + ESLint + Vitest
```

## Troubleshooting

**`SQLSTATE[08006] connection refused`** — PostgreSQL is not running, or the
`digitalsmart` role does not exist. See Option B above.

**Health shows `queue: degraded`** — Redis is reachable but no worker is consuming.
Start `php artisan horizon`.

**`419` on a POST from the SPA** — the CSRF cookie was not primed. The API client calls
`/sanctum/csrf-cookie` automatically; check `SANCTUM_STATEFUL_DOMAINS` includes the
exact SPA host *and port*.

**Sign-in appears to succeed but the next request is unauthenticated** — the
session cookie is not being stored. Check `SESSION_DOMAIN`: it must be empty for
local development. Setting it to `localhost` stops the browser accepting cookies
for `127.0.0.1`, which looks exactly like a broken login. Set it only in deployed
environments that share cookies across subdomains.

**Vite not reachable on 127.0.0.1** — the dev server pins `host: '127.0.0.1'`; if you
change it, Playwright's `baseURL` must change too.
