# CI status

**State on 2026-10-19: GitHub Actions does not run for this repository. Verified, not assumed.**

## Evidence

- Every run fails in about four seconds with no step executed — e.g. run
  [39211019891](https://github.com/digitalalharbi/digitalSmart/actions/runs/39211019891) on `fab6d4b`, created
  06:37:50Z, completed 06:37:54Z.
- GitHub's own message on each job: *"The job was not started because recent account payments have failed or your
  spending limit needs to be increased."*
- The same account's public repository (CampaignsHub) runs; private repositories (digitalSmart, leadflow-crm) do not.
  GitHub blocks rather than charges, so **no fee is being incurred**.
- Branch protection and rulesets are refused for the same reason: *"Upgrade to GitHub Pro or make this repository
  public to enable this feature"* (HTTP 403).

## The three free options

| | A. Self-hosted runner on this Mac | B. Local gate before push | C. Make the repository public |
|---|---|---|---|
| Cost | Free (self-hosted minutes are not billed) | Free | Free (Actions and protection are free on public repositories) |
| Works today | **Unverified** — whether a billing-blocked account still starts self-hosted jobs is only known by trying | **Yes** | Yes, once public |
| Keeps the code private | Yes | Yes | **No** — product code, docs and history become public |
| Server-side record of green CI | Yes | No — the gate output is pasted into the PR | Yes |
| Server-side protection of `main` | No (still 403 while private) | No | **Yes** |
| Work needed | `ci.yml` rewritten: `services:` containers are Linux-only, so Postgres/Redis must come from the Mac; isolated test databases; the runner installed as a service | `scripts/gate.sh` running exactly `ci.yml`'s checks, called by the `pre-push` hook (fast checks) and before each PR (full suite) | A secret scan of the whole history first; then the visibility switch and protection |
| Ongoing cost | The Mac must be on and awake; every branch's workflow code runs with the owner's user permissions | Pushes wait for the fast checks (~4 min); the full backend suite (~16 min) and E2E run before a PR | None, beyond the code being public |
| Who decides | Engineering | Engineering | **Owner only** |

## Recommendation: B

B is the only option that works today with certainty, keeps the repository private, can never incur a fee, and fits
the execution policy — all development already runs on this machine, so the checks belong there. A adds a runner to
keep alive and a CI rewrite for a benefit (a server-side tick) that the account cannot currently guarantee. C solves
both CI and protection, and is the right choice only if the owner is content for the code to be public.

**Not implemented until the owner chooses.** Until then: the `pre-push` hook (no push to `main`, governance guard) is
active, and every PR carries the local gate output as its CI evidence.
