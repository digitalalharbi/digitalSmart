# Resume state

Written for whoever picks this up next, including a later session of the same agent.

## Where it is

| | |
| --- | --- |
| Branch | `main` |
| Commit | The tip of `main`, which is the commit that added or last changed this file. The one below it is `691d1fa` — *fix(auth): a sign-out that failed looked exactly like one that worked*, which is where the gates below were measured |
| Working tree | Clean of this session's work. **Another session's uncommitted files are present** and were deliberately left alone: `backend/app/Domain/Advertising/ValueObject/Money.php`, `backend/app/Domain/Advertising/ValueObject/CurrencyExponent.php` (untracked), `backend/app/Infrastructure/Measurement/Mapper/GoogleAnalyticsMapper.php`, `backend/tests/Unit/Domain/AdvertisingTest.php`, `docs/GOOGLE_ANALYTICS.md`, `docs/MEASUREMENT.md`. Every commit message in this pass names the exclusion |

## Gates, as measured at that commit

1 776 backend tests (6 402 assertions) · 579 frontend tests · 398 Playwright tests, no failures and no flakes ·
PHPStan level 8 clean · Pint clean · TypeScript strict clean · ESLint and Prettier clean · production build.

Reproduce with: `cd backend && php artisan test && vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/pint --test`,
`cd frontend && npx vitest run && npm run typecheck && npx eslint src e2e && npx prettier --check src e2e && npm run build`,
and `./scripts/e2e.sh` from the repository root.

## What is closed

`docs/CLOSURE.md` is the ledger: one row per product area, the tests that prove it, and the exact external
thing any unfinished row waits for. Every row is closed except for live provider handshakes.

Nothing internally executable is known to be open. The sweeps that would find more have all been run at this
commit and return nothing: exported frontend symbols nothing imports, uncalled port methods, API routes with
no caller (209 routes, the store webhook the only one — and its caller is Salla), navigation items with no
page, and `TODO`/`FIXME` markers.

## First next command

```
cd backend && php artisan test
```

Confirm the gates still hold, then pick up from `docs/CLOSURE.md`'s last section — *What is not closed* — which
lists only items blocked on a host, an account or a credential. Each needs a decision from the company rather
than code:

1. A staging environment, so no figure in `docs/PRODUCTION.md` has to be labelled as a developer's machine.
2. An error tracker and a metrics backend (a key and an endpoint).
3. An uptime monitor polling `GET /api/health`, which is built for one.
4. The cron that runs `schedule:run` every minute — see `docs/PRODUCTION.md`; runbook 8 covers its absence.
5. Provider credentials, per `docs/INTEGRATIONS.md`, each of which turns a `BLOCKED_EXTERNAL_CREDENTIALS` row
   into a live one with no code change: the adapters answer honestly against real credentials already.
