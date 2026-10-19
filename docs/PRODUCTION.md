# Production

What this product needs to run for somebody other than its author, and how its operators find out when it
stops being true.

Two deliberate omissions first, so nothing here reads as more than it is:

- **No deployment has happened** (the pipeline exists — `docs/DEPLOYMENT.md` — the server does not). There is no hosting account, no domain, no TLS certificate and no secret
  store attached to this repository, so everything below is the procedure and the configuration rather than a
  record of a running system. Where a step needs a credential only the company holds, it says so.
- **No production figure is quoted.** The load numbers in this document were measured on a development server
  on a laptop. They are a floor and a regression signal. A capacity plan comes from the staging environment
  that does not exist yet.

## The twelve-factor edges that are already honoured

| Concern | Where |
|---|---|
| Configuration from the environment | `backend/.env.example` documents every key with an empty value; `config/digitalsmart.php` gives each one a default and a type |
| Stateless web tier | Sessions in Redis (`SESSION_DRIVER=redis`), so a request can be served by any instance |
| Asynchronous work off the request | Redis queues behind Horizon; webhooks queue their handling and never process in the request |
| Logs to stdout | `config/logging.php`; the request id on every line ties a log to a job and an audit entry |
| Build artefacts, not build steps, in the image | `npm run build` produces `frontend/dist`; the API serves no assets |
| Migrations as code | 38 migrations, every enum mirrored by a CHECK constraint so the database and the domain cannot drift |

## What to set before the first deploy

Every value below is **BLOCKED_EXTERNAL** on something only the company can issue. The names are final; the
values are not in this repository and must never be.

| Key | Why it cannot be defaulted |
|---|---|
| `APP_KEY` | Encrypts every stored provider credential. Rotating it without re-encrypting them makes all of them unreadable — see the runbook |
| `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` | Sanctum decides whether a request is first-party from the origin. A mismatch answers 401 to a valid session, which is the single most confusing misconfiguration this stack has |
| `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE` | A session cookie without `Secure` on an HTTPS origin is a session cookie that travels in clear text on the first plain request |
| `DB_*`, `REDIS_*` | Managed instances, with their own credentials |
| `REVERB_*` | The realtime key pair. Nothing readable travels over a socket in this product — a notification sends a signal and a board sends a nudge — but a subscription still authenticates, and the channel rules are re-checked on every one |
| Provider credentials | One per integration, each blocked on a developer-programme approval — `docs/INTEGRATIONS.md` names the exact missing item per provider |
| `ANTHROPIC_API_KEY` | The assistant works without one and says so; with one it writes prose over the product's own figures |

`php artisan about` prints what the application resolved, which is the first thing to read when an environment
behaves unlike another.

## Health: two endpoints, two questions

| Endpoint | Question | Who asks |
|---|---|---|
| `GET /up` | Is this process alive? | The orchestrator's liveness probe. It must stay cheap and must not touch a dependency — a probe that fails because Redis is down restarts a healthy web process and turns a degraded dependency into an outage |
| `GET /api/health` | Can this process serve? | The readiness probe and the uptime monitor. It probes the database, the cache, the queue and the failed-job store, and answers **503** when any of them is down so a load balancer stops sending traffic |

Four probes, and two of them are about work rather than connectivity:

- **`database`**, **`cache`** — reachable, with the server version in the detail.
- **`queue`** — reachable, *and whether anything is consuming it*. Depth alone cannot tell a dead worker from
  an idle one: five jobs on a stalled queue and five thousand on a queue keeping up read the same from a
  count. The signal is the age of the oldest waiting job — past five minutes, nothing is consuming it. Where
  the driver does not publish ages (Redis keeps a list of serialised payloads), the detail says so rather than
  implying it looked.
- **`failed_jobs`** — anything that gave up, with the age of the oldest. **Any failure at all is degraded**,
  not a threshold: a failed sync leaves yesterday's spend on a dashboard and a failed webhook leaves an order
  out of a client's revenue, and the first quiet failure is the one worth reading.

Both of the last two are **degraded, never down**. A stalled worker is not an outage of the platform, and
answering 503 for it would take the API offline behind a load balancer for work that needs retrying.

## Performance

### Query budgets, asserted

`tests/Feature/System/QueryBudgetTest.php` asserts **scale invariance** rather than a ceiling: the number of
queries a list endpoint runs must not grow with the number of rows. A fixed maximum is a number somebody
raises the first time it fails; "it must not grow" needs no magic figure and catches the defect that is
invisible on a developer's three seeded rows and takes a page down on a client with four hundred.

Covered: the client list (2 rows against 42), the approvals queue (4 against 44, half of them decided so both
queues render), the work board (4 cards against 44, each with a client, a project and an assignee to resolve).

### What else is already in place

- `Model::preventLazyLoading()`, `preventSilentlyDiscardingAttributes()` and `preventAccessingMissingAttributes()`
  outside production, so an N+1 or a typo'd attribute fails a test rather than shipping.
- `DB::whenQueryingForLongerThan()` logs any query over `digitalsmart.slow_query_threshold_ms` (500 by
  default) with its bindings redacted.
- Every list endpoint paginates, and every analytics read goes through one query that serves the scorecard,
  the chart and the table — so they cannot disagree and there is no second query to optimise.

### The load harness

`make load` runs `scripts/load.sh`, which prefers **k6** (`scripts/load/api.js`: ramping virtual users, tagged
thresholds — p95 under 1.5 s on analytics, under 300 ms on health, error rate under 1%) and falls back to a
concurrent curl sampler that measures the same endpoints and says plainly that it is a sampler.

Three findings came out of building it, and all three are properties of the product rather than of the
harness:

1. **The rate limiter is per user** — 120 requests a minute each. A load run driven by one account measures the
   limiter, not the application, so the harness drives several accounts and reports 429s in their own column.
2. **Signing in is throttled at five attempts a minute per address**, which a harness can exhaust on its own.
   Cookie jars are therefore kept between runs and reused while they still work.
3. **Roles differ, and a 403 is a correct answer.** The harness probes every session/endpoint pair once and
   drives only the readable ones, printing what it excluded — a pair that *should* be readable disappearing
   from that list is a permission regression.

Measured on this development stack — `php artisan serve` on a laptop also running the browser, four sessions,
eighteen readable pairs:

```
160 requests, 8 concurrent     0 failed, 14 rate-limited
29.5 req/s                     p50 218 ms   p95 246 ms   p99 250 ms
```

That is a floor. It is not a capacity figure and must not be quoted as one.

## Accessibility, measured

`make a11y` runs axe-core against the real application, signed in as a real role, over the published
`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` and `wcag22aa` rule sets — on five surfaces, in both browser
projects, and once in Arabic right-to-left. Twelve gates.

`src/styles/tokens.contrast.test.ts` is the other half, and the cheaper one: it parses `tokens.css` and
measures every ink against every surface it can be read on, in both themes, against 4.5:1 for text and 3:1
for the focus ring.

Between them they found four real defects, which is why they exist:

- **Tertiary ink at 3.66:1** on the sunken surface. It carries table headers, timestamps and the source labels
  this product's honesty rests on. Re-derived to a new ramp step at 4.80:1.
- **Amber at 3.07:1** on its own badge tint — the worst pair in the system, and the colour of a caveat.
- **The info ink** failing both the canvas and its own tint.
- **Dark-mode badges at 3.39–4.11:1**, because a dark tint washes the text's own hue over the surface and moves
  the backdrop *towards* the text. The tint went from 16% to 6%: the tint is decoration, the text is
  information, so the decoration gave way.
- **Invalid ARIA on seven loading states.** `aria-label` on a plain `<div>` names nothing: a generic element
  takes no accessible name, so every skeleton panel was silent to a screen reader. Caught *intermittently*,
  which is why it had survived — a loading state is on screen for a second. `LoadingRegion` fixes it in one
  place with `role="status"`, and a unit test asserts the role so the next one fails deterministically.
- **Scrollable regions with no way in from the keyboard.** A wide table or a board column scrolls sideways on a
  phone, and without a tab stop its last columns are invisible to anybody not using a pointer. `ScrollRegion`
  is the shared primitive that fixed all fourteen of them at once.

What the automated gates cannot check is stated rather than implied: they catch contrast, names, roles,
labels, landmarks and order, and they cannot tell whether a label *describes* its control or whether a flow
can be completed by keyboard alone. A green run means no machine-detectable violation, which is a smaller
claim than "accessible".

## The schedule, and the one cron it needs

Three commands keep the product from going stale, and until this release **nothing ran any of them** — the kind
of gap no test can see from inside a command, because each command's own tests pass while the product quietly
ages.

| Command | Cadence | What goes wrong without it |
| ------- | ------- | -------------------------- |
| `advertising:sync` | hourly | A dashboard is as old as the last person who pressed a button. Each run re-reads the adapter's own attribution lookback, because conversions keep landing against past dates |
| `commerce:sync` | 03:30 daily | Webhooks carry the day's orders in seconds; this catches what they dropped. One dropped delivery, one deploy during a busy hour, one platform incident, and a client's revenue is quietly short for that window with nothing on any screen to say so |
| `access:sync` | 03:00 daily | A deploy that adds a permission reaches new workspaces immediately and existing ones not at all — the feature is live and every current workspace is refused it |

All three are `withoutOverlapping` and `runInBackground`. Each **queues** work rather than doing it, so a run
starting while the previous one is still dispatching would double every job in flight; the per-account and
per-store jobs are unique, so the duplicates would be dropped silently and nobody would learn the schedule was
too tight.

Nightly rather than hourly for the two daily passes: each commerce adapter's restatement window is measured in
days, so an hourly pass would spend a platform's rate limit re-reading the same window twenty-four times to
find the same nothing.

**The one thing a host must do** is run Laravel's scheduler every minute:

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Nothing in this repository installs that line — there is no host to install it on yet (see *What is still
missing*) — and `php artisan schedule:list` is how to check it is doing what this table says.

## Backup and restore

`make backup` writes a `pg_dump` custom-format archive **and a manifest beside it** listing every table's row
count, the server version and the migration count. `make restore` restores the newest archive into a scratch
database, compares every table against that manifest, and drops the scratch database again.

The manifest is the whole point. An archive that loads is not an archive that is complete, and the only check
that means anything is a row count taken at dump time compared with one taken after a restore. Verified here:
50 tables, every count equal; and the comparison was itself tested by tampering with a manifest, which
reported the mismatch and exited non-zero.

Three rules, in `docs/RUNBOOKS.md` with the procedures:

1. A backup is verified or it is a hope. Restore it on a schedule, not after an incident.
2. Dumps are client data. Never in the repository (`.gitignore` refuses `/storage/backups/`), never in a
   bucket the application itself can read.
3. `pg_dump` must be at least the server's major version, which `scripts/backup.sh` checks before it starts —
   discovering that during an incident is the wrong time.

## Deploying

No pipeline exists yet, because there is nowhere to deploy to. The order below is the part that is decided,
and it is decided because getting it wrong is recoverable only by a restore:

1. **Build the frontend** and publish `frontend/dist` to the CDN or static host. It is a separate origin from
   the API and carries its own CSP.
2. **Put the new API image up alongside the old one.** Do not migrate yet.
3. **Run migrations** from one instance (`php artisan migrate --force`). Every migration in this repository is
   additive or guarded; a column is never dropped in the same release that stops writing to it, so the old
   instances keep serving while this runs.
4. **Shift traffic** once `GET /api/health` answers 200 on the new instances. Readiness is the gate, not a timer.
5. **Restart Horizon last** (`php artisan horizon:terminate`, and let the supervisor start it again). A worker
   running the old code against the new schema is the one combination the additive rule does not cover.
6. **Retire the old instances.**

`php artisan optimize` (config, routes, events, views) belongs in the image build, not in the deploy step: a
cache built at boot is a cache built once per instance instead of once per release.

## What is still missing

Named rather than implied, because a production document that lists only what exists is the one somebody
trusts by mistake:

- **A deploy pipeline, but no host.** `.github/workflows/deploy-production.yml` and `scripts/deploy/` are built and
  guarded (push to `main` → SSH → release-based deploy → migrate forward → reload → health → switch back on failure);
  see `docs/DEPLOYMENT.md`. No container images: ADR 0004 records why the applications run on the host.
  `AWAITING_SERVER_ACCESS`: a server, its SSH user, port, deploy path, host key, web-server arrangement and process
  names — then `PRODUCTION_ENABLED=true`.
- **No staging environment**, which is why no figure in this document is a production figure.
- **No error tracker and no metrics backend.** Logs are structured and carry the request id; nothing ships
  them anywhere, and the wiring is **not** already in place — the earlier version of this line implied it was.
  What it actually takes: install the tracker's Laravel package, set its DSN in the environment, and add its
  channel to `LOG_STACK` (which is `single` today and is a comma-separated list). The `stderr` channel is
  already defined, so a container platform that collects stdout needs only `LOG_STACK=stderr`. The account and
  the DSN are the company's to issue; the three steps above are an afternoon once they exist.
- **No uptime monitor.** `GET /api/health` is built for one and nothing is polling it. It answers `503` when a
  dependency is down, names which, and distinguishes *degraded* from *down* — so a monitor needs the URL and
  nothing else from this side.
- **No automated backup schedule.** The scripts are real and verified; running them hourly is a cron on a host
  that does not exist yet — the same host that owes `schedule:run` its minute.


## Sync policy and queues

Added for agency scale. The point of this section is that a hundred projects must not turn "sync everything
every hour" into a queue where one large client — or a historical import — delays everybody else.

### Four sync queues, in strict priority order

| Queue | What waits in it | Why it is separate |
|---|---|---|
| `sync-recovery` | Active accounts whose figures stopped arriving (no success for 3 cadences) | The account somebody is most likely waiting on |
| `sync` | Active accounts, hourly | Current monitoring |
| `sync-low` | Accounts with no running campaigns, every six hours | Worth keeping, not worth the rate limit hourly |
| `sync-backfill` | A requested historical window | Lowest priority of anything that runs |

Horizon's `supervisor-sync` lists them in that order with `balance => false`, so a worker drains each before the
next. Archived work spends nothing recurring: an account whose projects are all finished is left alone unless
`advertising:sync --force` is run.

Other queues: `webhooks` and `commerce` (stores), and `notifications`, `default`, `reports`, `ai`
(everything user-facing). A provider call that takes minutes can never occupy a worker a notification needs.

### Fairness and throttle

`SyncPlanner` (a pure domain service, exhaustively unit-tested) decides which accounts run and in what order:

- **Round-robin by who the work is for** (the owning client of a client-granted connection, otherwise the
  client of a bound project). A client with 50 accounts and one with 2 reach their first jobs at the same
  position; the small one is not queued behind the large one's backlog. Tested at exactly that 50-vs-2 case.
- **Per-provider bursts**: five jobs released together, then ten seconds, per provider — providers limit by
  app, not by account, and a hundred accounts dispatched on one tick are otherwise limited only by workers.
- **Adaptive cadence**: hourly with campaigns running, six-hourly without, never for finished projects.

### Failure isolation

One job per account, each retried independently (`tries = 3`, backoff 60/300/900 s). A failure is that
job's: the scale tests drive a batch through the real handler with one account vanished mid-flight and assert
every other account was read.

### Database session time zone

`config/database.php` now sets the Postgres session time zone explicitly (`DB_TIMEZONE`, default UTC). Laravel
sends timestamps as naive strings in the application's zone and Postgres reads them in the *session's*; with the
two left to agree by luck, a database host in `+03` stored every instant three hours wrong while the application
read it back consistently wrong. The first comparison against a clock that had not been through the database
(`synced five minutes ago`) was then three hours out.
