# CI status

**State on 2026-10-19: GitHub Actions runs. Option C was chosen by the owner and implemented the same day.**

Until this morning every run failed in about four seconds with no step executed, because the account's
billing state blocks Actions on **private** repositories. The repository is now public, which is what makes
both Actions and branch protection free, and both now work.

## Evidence, not assumption

- Run [39212879996](https://github.com/digitalalharbi/digitalSmart/actions/runs/39212879996) on `main`:
  `Governance (no model, no secret, no schedule)` **passed in 8 seconds**, and `Backend` and `Frontend`
  started and ran for minutes. Before the switch the same workflow on the same commit died in 4 seconds with
  no step executed.
- The ruleset GitHub refused with *"Upgrade to GitHub Pro or make this repository public"* (HTTP 403) was
  created without complaint: `main protection`, id `25378817`, enforcement `active`.
- No fee is incurred. Actions minutes and rulesets are free on public repositories; nothing in this
  repository can spend them — see `docs/EXECUTION_POLICY.md`.

## What the owner gave up, stated plainly

The product code, every document and all 261 commits of history are now readable by anyone, and may be
indexed, forked and copied. That was the price of option C and it was paid knowingly.

The history was scanned before the switch rather than after: no `.env`, no private key, no `APP_KEY`, no
provider token. The only matches were `backend/.env.example`, `frontend/.env.example` and a 1×1 PNG fixture
in an end-to-end spec. Every stored provider credential in this installation is the literal `sandbox`
(`docs/LIVE_COMMISSIONING.md`), so there was never a live secret to leak.

## Protection of main

| Layer | State |
|---|---|
| Ruleset `main protection` | **Active.** Pull request required; `Governance`, `Backend` and `Frontend` required and the branch must be up to date; no deletion; no force-push; review threads must be resolved. |
| Bypass | Repository admin, `always` — copied from CampaignsHub deliberately, because `docs/CHANGE_MANAGEMENT.md` documents an emergency direct push and a rule with no bypass would make that procedure a lie. |
| Local `pre-push` hook | **Still active, and still the real guard against the owner's own accidental push**, precisely because the ruleset bypasses for an admin. Enable per clone with `git config core.hooksPath .githooks`. |
| Auto-merge | **Allowed since 2026-11-12** by owner decision; `allow_auto_merge` was off before. Armed per pull request, bypasses no required check, and lands on the three required ones — so a pull request can merge while the non-required end-to-end check is still running. The reasoning and what to reconsider: `docs/CHANGE_MANAGEMENT.md`. |

`Governance` is required here although CampaignsHub requires only `backend` and `frontend`. The no-fees rule
is the one a careless commit could break silently, so it is enforced by the server rather than by good
manners.

## Merge queue

GitHub's own merge queue, not a copy of CampaignsHub's `merge-queue.yml`. That file runs on a
`schedule:` and reads `secrets.MERGE_QUEUE_TOKEN`, and `scripts/governance/no-paid-model.sh` refuses both —
so copying it verbatim would fail the `governance` job, which every other job waits on, and take CI down
instead of improving it. Stripped of the schedule and the secret it is five kilobytes of shell doing what
the platform does natively, serially and server-side, for nothing.

It requires the `merge_group` trigger in `ci.yml`: the queue builds each pull request on top of `main` and
the entries ahead of it, and gates the merge on checks run against **that** combination. The trigger must be
on `main` **before** the rule is enabled, or every queued entry waits for a status that cannot arrive.

## What CI runs, and how long it takes — 2026-11-13

`.github/workflows/ci.yml` after [#113](https://github.com/digitalalharbi/digitalSmart/pull/113), merged 2026-11-13.
The three required checks are unchanged; what changed is what runs beside them and when.

| Job | Waits on | Required | What it does |
|---|---|---|---|
| `Governance` | — | yes | The guard's own test suite, then the guard over every workflow. Nothing else starts until it passes. |
| `Backend` | Governance | yes | Pint, PHPStan level 8, then Pest **in parallel** — one PHPUnit process per core, each on its own database (`digitalsmart_test_test_1 … _N`, created and migrated by Laravel's parallel-testing support). |
| `Frontend` | Governance | yes | Type check, ESLint, Prettier, Vitest, production build. |
| `End-to-end shard 1/6 … 6/6` | Governance | no | Six jobs, each migrating and seeding its own API, building the SPA and running one sixth of every Playwright project (`--shard=n/6`), one browser worker per core. The three browsers are cached between runs, keyed on the Playwright version the lockfile pins; the apt packages they need are installed every run. `fail-fast: false`, so every shard reports. |
| `End-to-end (Playwright)` | the six shards | no | One status line under the name the suite has always reported with; fails if any shard did. |

The end-to-end jobs wait on the governance gate and nothing else. They used to wait on `Backend`, which put the whole
serial Pest run in front of a suite that builds its own API anyway; a type error still fails both `Frontend` and the
shard's own build, so running them side by side loses nothing.

**Measured on `main`, same workflow file before and after** (job start → end, from the Actions API):

| | Serial — run [43239350172](https://github.com/digitalalharbi/digitalSmart/actions/runs/43239350172), `d9ba991` | Sharded — run [43356750566](https://github.com/digitalalharbi/digitalSmart/actions/runs/43356750566), `c374b3a` |
|---|---|---|
| Governance | 8 s | 7 s |
| Backend | 12 m 51 s | 9 m 57 s |
| Frontend | 4 m 46 s | 4 m 40 s |
| End-to-end | 26 m 06 s, after Backend | shards 4 m 18 s – 10 m 29 s, beside Backend |
| **Whole run** | **39 m 32 s** | **10 m 48 s** |

Two consecutive runs on the pull request were also green (the second in 9 m 13 s). The critical path is now `Backend`
and the slowest shard, both near ten minutes; the next minute saved comes from Pest or from a seventh shard, not from
ordering.

**What is still not run on the server:** the agency-scale profile (`E2E_AGENCY=1` in `scripts/e2e.sh`, sixty clients
and every role's history on top of the demo). The shards run all five Playwright projects — `chromium`, `mobile-safari`,
`firefox`, `webkit`, `mobile-chromium` — against the demo seed only; the local gate is still what a reviewer reads before
approving, as `docs/CHANGE_MANAGEMENT.md` says.

**What did not change:** no schedule, no secret, no model, no paid feature. The governance guard passed on the new file
before any of it ran, and `merge_group` is still on the trigger list.

## Deployment

`.github/workflows/deploy-production.yml` deploys `main` to production over SSH after every merge — see
`docs/DEPLOYMENT.md`. It is not CampaignsHub's copied: it uses only the approved infrastructure secrets
(`PRODUCTION_*`, in the `production` environment), which the governance guard now admits **only in that file**,
and reports `AWAITING_SERVER_ACCESS` until a server exists. The secret policy was corrected by the owner on
2026-10-19: no secret *value* anywhere; encrypted deployment-infrastructure secrets allowed.

## The options that were not taken

**A — a self-hosted runner on the owner's Mac.** Free and private, but whether a billing-blocked account
starts self-hosted jobs was never established, `ci.yml` would have needed rewriting (`services:` containers
are Linux-only), the Mac would have to stay awake, and every branch's workflow code would run with the
owner's user permissions. It also would not have given server-side protection of `main`.

**B — a local gate before push.** Free, private, certain, and the recommendation in the previous version of
this document. It is not discarded: `.githooks/pre-push` still runs the governance guard on every push, and
the full local gate is still what a reviewer reads before approving. What option C adds is a record on the
server that nobody can forget to produce.
