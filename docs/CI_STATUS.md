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

## Deployment is not part of this

`deploy-production.yml` was **not** copied. It reads `secrets.VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY` and
`VPS_PORT`, which the governance guard refuses — and digitalSmart has no host to deploy to, so an adapted
version would be a workflow that could never run. `BLOCKED_EXTERNAL`: a hosting account.
See `docs/PRODUCTION.md`, "What is still missing".

## The options that were not taken

**A — a self-hosted runner on the owner's Mac.** Free and private, but whether a billing-blocked account
starts self-hosted jobs was never established, `ci.yml` would have needed rewriting (`services:` containers
are Linux-only), the Mac would have to stay awake, and every branch's workflow code would run with the
owner's user permissions. It also would not have given server-side protection of `main`.

**B — a local gate before push.** Free, private, certain, and the recommendation in the previous version of
this document. It is not discarded: `.githooks/pre-push` still runs the governance guard on every push, and
the full local gate is still what a reviewer reads before approving. What option C adds is a record on the
server that nobody can forget to produce.
