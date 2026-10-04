# Testing

Nothing merges with a failing check, and no test is weakened to make CI green. If a
test is wrong, fix the test on its merits; if the code is wrong, fix the code.

## Layers

| Suite          | Tool                      | Location                | What it guards                        |
| -------------- | ------------------------- | ----------------------- | ------------------------------------- |
| Architecture   | Pest arch                 | `backend/tests/Architecture` | Layer boundaries, immutability, no debug helpers |
| Unit           | Pest                      | `backend/tests/Unit`    | Domain and Application logic, no framework |
| Feature        | Pest + real PostgreSQL    | `backend/tests/Feature` | HTTP contract, authorization, persistence |
| Component      | Vitest + Testing Library  | `frontend/src/**/*.test.tsx` | Rendering, states, accessibility roles |
| End-to-end     | Playwright                | `frontend/e2e`          | Real browser flows across the stack   |

## Running

```bash
# Backend: style, static analysis and tests
cd backend && composer verify

# Frontend: types, lint and tests
cd frontend && npm run verify

# End-to-end: builds the SPA, starts an isolated stack, runs Playwright
make e2e

# Everything CI runs
make verify
```

## End-to-end strategy

`make e2e` (via `scripts/e2e.sh`) brings up a complete, isolated stack: its own
`digitalsmart_e2e` database, migrated and seeded fresh, an API on 8011 and the
**production build** of the SPA on 5178. Three reasons:

- The suite signs in and creates records. Pointing it at the development
  database would steadily fill a developer's workspace with test data.
- It tests the built bundle — what actually ships — not the dev server's
  on-demand transforms, which are slower and not representative.
- Separate ports mean it does not fight a dev stack you already have running.

The suite signs in as the real seeded roles (owner, manager, buyer, creative,
client viewer) rather than one superuser, so a permission regression fails here
as well as in the backend tests.

## Database strategy

Feature tests run against **real PostgreSQL** (`digitalsmart_test`), never SQLite. The
schema depends on UUID keys, JSONB, check constraints and PostgreSQL-specific index
behaviour that SQLite does not reproduce — a green SQLite suite would be a false
negative. `RefreshDatabase` wraps each test in a transaction, so the development
database is untouched and tests do not leak state into one another.

## Conventions

- Unit tests must not touch the container, the database or the network. If a test needs
  the framework to run, it belongs in `Feature`.
- Test behaviour, not implementation: assert on the response, the stored row or the
  rendered output, not on internal calls.
- Query by accessible role and name in component tests (`getByRole('button', { name })`).
  This keeps the suite honest about accessibility.
- Every bug fix lands with the regression test that would have caught it.
- Demo/sandbox fixtures are labelled `DEMO`; no test or seeder may produce realistic
  but fabricated client metrics.

## Static analysis

- **PHPStan (Larastan) level 8** over `app`, `config`, `database`, `routes`, `tests`.
  The only suppression is Pest's `$this` rebinding inside test closures, scoped to
  `tests/*` and to that single identifier.
- **TypeScript strict**, plus `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`
  and `erasableSyntaxOnly`.
- **ESLint type-checked** rules on all TypeScript sources.

## Coverage

`composer test:coverage` enforces a 70% floor on the backend. Coverage is a smoke
alarm, not a goal: a well-tested vertical slice beats a high percentage of trivial
assertions.
