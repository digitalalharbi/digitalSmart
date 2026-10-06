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

Two environment details matter, and both are configuration rather than
weakened checks:

- **Rate limits.** Every browser in the run shares one address, so a whole
  suite's traffic lands in a single per-IP bucket sized for one person. The
  runner raises the per-IP budgets for that process only; production keeps the
  defaults, and the per-account login limit — the one that actually protects a
  user against brute force — stays in force.
- **Concurrency.** `php artisan serve` handles one request at a time *per
  worker*, and by default it has one. The runner starts it with
  `PHP_CLI_SERVER_WORKERS=16 --no-reload` — the flag is required, or Laravel
  warns and falls back to a single worker — against two browser workers, a 10s
  assertion timeout and a 45s test timeout. Sixteen is headroom rather than a
  measured optimum. A multi-process runtime (FPM, or Octane) would make it
  unnecessary, and is a hardening concern.

  **The proxy must not keep connections alive to this server, and that is the
  thing to know.** Two tests failed or flaked on every run for weeks, always
  with the application reporting "the server could not be reached" on the
  sign-in screen. It reads exactly like a bug in the sign-in screen, and it was
  mistaken for one twice — first as host load, then as password hashing cost.

  It was neither. The browser talks to Vite's preview server, which proxies
  `/api` to PHP's built-in server. Node has kept proxy connections alive by
  default since 19, and `php -S` binds a kept-alive connection to one forked
  worker and closes it on its own schedule; when Node then picks that socket for
  the next request the read fails, the proxy returns nothing, and the
  application correctly says it could not reach the server. It appeared only
  under WebKit, which opens and reuses more connections than Chromium — which
  is why one browser project failed and the other did not, and why the failures
  moved between projects run to run.

  `vite.config.ts` now gives the proxy an agent with `keepAlive: false` and a
  socket cap. The suite went from 148 passed / 1 failed / 1 flaky in roughly
  **25 minutes** to **158–160 passed in 3.5 minutes**: the pathological ~500ms
  request times in the server log were socket reuse stalling, not the
  application being slow.

  **The last of those failures was a real API defect, and the harness found it.** Once the
  sign-in helper reported the browser's own view, the next run named the cause in one line:
  `302 /api/auth/login`. The route carried Laravel's `guest` middleware, which answers an
  already-authenticated request with a redirect to a web route — absolute against `APP_URL`, so
  cross-origin for the SPA, so blocked by the browser, so indistinguishable from an unreachable
  server. Any test that signed in twice in one context hit it. Fixed in the API, not the harness.

  **Do not trust `artisan serve`'s own timings when it is forked.** They
  mis-pair across workers, and the same log reported a bcrypt verification as
  taking 0.02ms and a cookie fetch as taking 500ms. The sign-in helper now
  records the browser's view — every auth response status and every failed
  request — and includes it in the error when it gives up, because two separate
  investigations of this error reached the wrong conclusion from that log.

  Host load still matters and is still worth checking first: the same suite
  finished in 1.9 minutes all green on an idle machine and took 19 minutes with
  three failures on a busy one.

  Three mitigations are in place and all three are worth having regardless:

  - The sign-in helper distinguishes a *slow* response (waited out — retrying a
    request still in flight only adds load) from a *dropped* one (retried with a
    backoff, since an immediate retry lands in the same congested moment).
  - The suite allows one Playwright retry. A retried test is reported as
    **flaky** rather than as a pass, which is the point: the signal stays in the
    output rather than being hidden by a longer timeout.
  - **Every name a test creates carries the attempt number.** The records this
    suite creates are unique per client and period, which the server enforces, so
    a test that failed *after* saving could never pass on retry: the second
    attempt's save was correctly refused, the form stayed open, and the test timed
    out on a control that never appeared. That turned every flake into a hard
    failure whose message described the retry rather than the original fault, and
    it hid the proxy problem above for weeks.
  - `scripts/e2e.sh` kills whatever holds either port on exit. Both servers put
    processes beyond the reach of the PID it recorded — `artisan serve` forks
    workers as grandchildren, `npm run preview` runs through a shell — and a
    leaked preview server is worse than a leaked API one because it keeps a Node
    process busy rather than idle. Ten accumulated across runs before this
    existed, on the machine running the tests.
- **The application retries a request that got no response at all,** once. A
  request that came back with a status got an answer and is never retried; one
  that came back with nothing may have been unlucky. GET and HEAD by default,
  plus signing in, which is the one write that can be repeated without repeating
  its effect. `src/shared/api/client.test.ts` holds that distinction, and the
  suite benefits from it for the same reason a user on a train does.
- **Locale.** The app picks its language from the browser, which is correct
  behaviour, so on an Arabic-locale machine it renders the Arabic interface and
  every English selector misses. Playwright pins `en-US`, and
  `e2e/localisation.spec.ts` deliberately does not — it asserts that an Arabic
  browser gets Arabic, right to left, with Latin digits, without touching a
  toggle.

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

## Advertising sync

The sync suite is the first place where "the tests pass" and "the numbers are
right" come apart, so it tests the second thing:

- **Normalisation** (`tests/Unit/Infrastructure/Advertising/MetaMapperTest.php`)
  runs against the SANDBOX fixtures, which hold real Graph API shapes. It asserts
  the conventions a naive mapping gets wrong: minor-unit budget strings versus
  decimal spend strings, `effective_status` over `status`, legacy objectives
  alongside the ODAX names, and that nested `actions` categories are not summed.
- **Null versus zero** is asserted explicitly at both layers. A non-video ad must
  report `null` video completions, not `0`; the latter reads as "nobody finished
  it" about something that had no video.
- **Idempotency** (`tests/Feature/Advertising/SyncTest.php`) runs the same sync
  twice and compares every table's count, then corrupts a stored figure and
  checks a re-run fixes it. This test found a real defect: a nullable column in a
  unique index, which PostgreSQL treats as distinct, so conversions doubled on
  every re-read.
- **Isolation** covers the three ways an account must not be synced: not bound,
  bound with sync paused, and belonging to another workspace. All three answer
  `Skipped` and write nothing.
- **Data honesty** asserts every sandbox row carries `source = sandbox`, that the
  run records it too, and — the important one — that a connection with *real*
  credentials fails outright and writes nothing. A fallback from "cannot call the
  API" to "show the fixtures" would be the one bug that puts invented numbers on a
  client report, so there is a test whose only job is to keep that path closed.

Fixture dates are relative and resolved against an injected clock, so the sandbox
window follows today without the assertions drifting.

## Analytics and charts

The analytics surfaces are tested at three levels, and each level is there because
the one above it cannot catch what it catches.

- **Unit, against real shapes.** `shared/metrics/format.test.ts` and
  `charts.test.ts` assert the rules that make a chart honest: a null renders as an
  em dash and never a zero; minor units divide exactly once; a gap in a series stays
  `null` with `connectNulls: false`; the whole chart mirrors in Arabic, axis side
  included.
- **Component.** `MetricCard` is asserted to show the server's
  `unavailable_reason` beside a missing figure, and to colour a change by whether it
  is *good* rather than by its sign. `SourceNotice` is asserted to carry the
  "do not share with a client" warning for sandbox data.
- **End-to-end, against the real pipeline.** `e2e/analytics.spec.ts` runs against
  the demo workspace, whose figures the actual sync wrote from the SANDBOX fixtures.
  That means a break anywhere — adapter, mapper, store, SQL aggregation, API
  resource, chart option — fails here, and the sandbox warning is asserted to be on
  screen before any number is.

ECharts is stubbed in component tests: jsdom has no canvas and no measured
container. The option builders are therefore covered by their own unit tests
against real payload shapes, which is where the interesting logic lives anyway.

**The lazily loaded chunk.** `/analytics` and `/campaigns` fetch a 209 kB chart
chunk on first visit, which against a single-process dev server shared by every
worker can exceed the default assertion timeout. `openSurface()` makes that wait
explicit and generous; every assertion after it uses the normal timeout. This is a
harness constraint, not an application one — the analytics queries themselves
measure about 30 ms.
