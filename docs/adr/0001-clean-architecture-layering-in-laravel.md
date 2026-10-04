# 1. Clean Architecture layering inside a Laravel application

- **Status:** Accepted
- **Date:** 2026-10-04

## Context

`CLAUDE.md` mandates Clean Architecture / DDD with Domain, Application, Infrastructure
and Interface/API boundaries. Laravel's default layout (`app/Http`, `app/Models`,
`app/Providers`) encodes none of that, and "we'll be disciplined" is not a boundary.

## Decision

Four layers under `app/`, with dependencies pointing inwards only:

- `App\Domain` — entities, value objects, domain events, repository interfaces. No
  framework imports.
- `App\Application` — use cases (Actions/Queries), DTOs, outbound ports.
- `App\Infrastructure` — Eloquent models and repositories, provider adapters, probes.
- `App\Interface` — controllers, form requests, API resources, middleware, console.
- `App\Providers` — the composition root, the only place allowed to see both an
  Application interface and an Infrastructure implementation.

`App\Interface` uses the spec's own name; PHP 8.4 permits `Interface` as a namespace
segment (verified) provided references are fully qualified.

Eloquent models move to `App\Infrastructure\Persistence\Eloquent\Model`. `app/Models`
is deleted: persistence is not a layer of the domain.

The rules are enforced by Pest architecture tests, not by convention.

## Consequences

- More indirection for simple endpoints; a trivial read touches four files.
- Business logic becomes testable without the framework — the unit suite boots no
  application container at all.
- Provider SDK churn cannot reach the domain.
- Factory resolution must be declared explicitly (`newFactory()`), since Laravel guesses
  factories from the `App\Models` convention.
