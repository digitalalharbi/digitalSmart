# Architecture

digitalSmart is a two-application system in one repository:

| Path       | Stack                                   | Responsibility                               |
| ---------- | --------------------------------------- | -------------------------------------------- |
| `backend/` | Laravel 12, API-only, PHP 8.4           | Domain logic, persistence, integrations, API |
| `frontend/`| React 19 + Vite + TypeScript (strict)   | SPA client, design system, analytics UI      |

There is no Blade UI and no second UI framework. The backend serves JSON (plus Horizon's
own dashboard and the OpenAPI document); every pixel the user sees is rendered by the SPA.

## Backend layering (Clean Architecture / DDD)

Dependencies point inwards only. `Domain` knows nothing about Laravel; `Interface` knows
nothing about Eloquent.

```
app/
  Domain/          innermost. Entities, value objects, domain events, domain exceptions,
                   repository *interfaces*. No framework imports, no Eloquent, no HTTP.
  Application/     use cases (Actions), DTOs, ports (outbound interfaces), application
                   services, query objects. Orchestrates Domain. Framework-light.
  Infrastructure/  adapters: Eloquent models + repository implementations, provider
                   (ad platform) adapters, queue/cache/mail/clock implementations.
  Interface/       inbound adapters: HTTP controllers, form requests, API resources,
                   middleware, console commands, routes wiring.
  Providers/       composition root. Binds Domain/Application interfaces to
                   Infrastructure implementations in the service container.
```

`App\Interface` is a valid PHP 8.4 namespace segment and is used deliberately so the
boundary carries the name the project constitution gives it. Always reference it
fully-qualified (`App\Interface\Http\...`), never as a relative segment.

### Allowed dependency directions

| Layer          | May depend on                              |
| -------------- | ------------------------------------------ |
| Domain         | Domain only                                 |
| Application    | Domain, Application                         |
| Infrastructure | Domain, Application, framework              |
| Interface      | Domain, Application, framework              |
| Providers      | everything (wiring only)                    |

`Interface` must not depend on `Infrastructure`, and nothing may depend on `Interface`.
These rules are enforced by executable architecture tests in `tests/Architecture`,
not by convention alone.

### Rules
- Controllers are thin: validate (Form Request) → call one Action → return an API Resource.
- No business logic, no queries and no provider SDK calls in controllers.
- Domain entities are plain PHP; Eloquent models are an infrastructure detail and live in
  `Infrastructure/Persistence/Eloquent/Model`.
- Repositories are declared as interfaces in `Domain/*/Repository` and implemented in
  `Infrastructure/Persistence/Eloquent/Repository`.
- Outbound side effects (HTTP to ad platforms, storage, clock, ids) sit behind
  `Application/Shared/Port` interfaces so use cases stay testable without the network.

## Frontend layering

```
src/
  app/         composition: providers, router, layout shell. Wires everything together.
  features/    one folder per product domain; owns its pages, components, hooks, api calls.
  shared/      cross-feature: api client, ui primitives, i18n, stores, config, lib.
  styles/      design tokens and global stylesheet.
  test/        test setup and helpers.
```

`features/*` may import from `shared/*` but never from another feature's internals; shared
code never imports from a feature. Server state lives in TanStack Query; only genuinely
local client state (theme, locale, sidebar) lives in Zustand.

### One retry, and only for a request that got nothing back

`shared/api/client.ts` distinguishes two failures that look identical to a caller and are not
the same thing at all.

A request that came back **with a status** got an answer. A 500 is an answer, and it is never
retried: retrying it doubles the load on a server already failing and makes the reader wait twice
as long for the same message.

A request that came back **with no status at all** may simply have been unlucky — a dropped
mobile connection, a laptop changing network mid-request, any proxy that recycles a connection a
moment before it is used. That is sent once more after 250ms, because "the server could not be
reached" is a conclusion a single failed connection does not support.

Only requests that may be repeated without repeating their effect: GET and HEAD by default, plus
a write that opts in with `retryOnNetworkError`. Signing in is the one write in this product that
qualifies, because signing in twice signs you in once. Everything else is left alone — a request
that failed without a response may still have been received and acted on, and a duplicate record
is worse than an error message.

## Async and realtime

Redis backs cache and queues. Horizon supervises queue workers. Reverb is the WebSocket
server and Laravel Echo the client, used for notifications, sync progress and live report
updates. Long-running provider work (metrics sync, exports, presentation rendering) is
always a queued job, never an inline request.

## Request flow

```
SPA  ──Axios (withCredentials)──▶  Sanctum stateful API
                                   Interface\Http\Controller
                                        │  Form Request validates
                                        ▼
                                   Application Action (use case)
                                        │  Domain entities + repository interfaces
                                        ▼
                                   Infrastructure repository → PostgreSQL
                                        │
                                        └─ queued jobs → Redis → Horizon → provider adapters
```

## Multi-tenancy and isolation

`Workspace → Client → Project` is the isolation spine, with
`ProviderConnection → ExternalAccount → ProjectIntegrationBinding` controlling which
external ad accounts a project may read. Authorization is always enforced server-side by
policies and permission gates; the SPA hiding a control is never the security boundary.

See `docs/DATA_MODEL.md`, `docs/INTEGRATIONS.md` and `docs/SECURITY.md` for detail, and
`docs/adr/` for the reasoning behind material decisions.
