# Change management

How a change gets from a request to `main`. Mirrors CampaignsHub.

## The path

```
request / issue → branch → implementation → tests → PR → CI green → review → merge
```

1. `git fetch origin`, then branch from the freshest `origin/main`:
   `claude/<issue>-<slug>`, or `feat/…` · `fix/…` · `chore/…`.
2. Implement. Stage **only the files you changed, by path** — another session works in the same folder (below).
3. Run the gate (see "CI" below) and paste the real counts into the PR.
4. Push the branch. Open a PR from `.github/pull_request_template.md` and answer every section.
5. Review, then merge on GitHub — a merge to `main` deploys to production automatically (`docs/DEPLOYMENT.md`). A merge on GitHub is not a push to `main`. Where the merge queue is
   enabled, merging adds the pull request to the queue: it is rebuilt on top of `main` plus anything queued
   ahead of it, and lands only if the checks are green against that combination.
6. `git fetch origin` — that is the state for the next piece of work.

## Protection of main

The repository became public on 2026-10-19 (`docs/CI_STATUS.md`), which made rulesets free, so protection
moved from the honour system to the server the same day.

| Layer | State |
|---|---|
| Ruleset `main protection` (id 25378817) | **Active.** Pull request required; `Governance`, `Backend` and `Frontend` required and the branch must be up to date; review threads resolved; no force-push; no deletion. |
| Bypass | Repository admin, `always`. Deliberate: the emergency procedure below is a documented path, and a rule with no bypass would make it an untruth. |
| Local `pre-push` hook | **Active, and still the only thing standing between the owner and an accidental direct push**, because the ruleset bypasses for an admin. `.githooks/pre-push` refuses any push to `main` and runs the governance guard. Enable per clone with `git config core.hooksPath .githooks`. |
| Rule in `CLAUDE.md` | **Binding** on every session |
| Auto-merge | **Allowed since 2026-11-12, by owner decision**; the repository setting was off until then, and it is still armed per pull request rather than repository-wide. It bypasses nothing — an armed pull request waits for `Governance`, `Backend` and `Frontend` and for the branch to be up to date, exactly as a manual merge does. What it removes is the last look at the green tick. Off again with `gh api -X PATCH repos/digitalalharbi/digitalSmart -f allow_auto_merge=false`. |

### What auto-merge costs, recorded rather than implied

The ruleset's own parameters are why this is worth writing down: `required_approving_review_count` is **0**, and the
five pull requests before the decision (#106–#110) carried no review at all. Review is not a gate here and never has
been; CI is. Auto-merge does not weaken that gate — it removes the moment somebody reads the result before the code
lands. Three consequences follow, and none is hypothetical.

**A pull request merges before the end-to-end suite finishes.** Only `Governance`, `Backend` and `Frontend` are
required, and E2E is not. #111 merged on those three — 9 s, 4 m 4 s and 17 m 28 s — **33 seconds after the end-to-end
check started** (14:06:59Z, merged 14:07:32Z), and it was still running long afterwards. With a manual merge the owner
tends to wait for it; armed, nothing does. Treat E2E as what it is here:
evidence read after the fact, not a gate.

**A late push lands on its own.** `require_last_push_approval` and `dismiss_stale_reviews_on_push` are both `false`,
so a commit pushed to an armed pull request merges when CI next goes green. With concurrent sessions that is the case
to watch.

**Reconsider it the day production exists.** A merge to `main` runs `Deploy Production`, and today a second
independent gate stops there: the repository variable `PRODUCTION_ENABLED` does not exist, so the workflow reports
`AWAITING_SERVER_ACCESS` and deploys nothing — which is exactly what it did for #111 (`16b3767`), succeeding without a
deployment. The day that variable is `true`, the chain becomes green CI → merge → deploy → `migrate --force` with
nobody in it. `docs/DEPLOYMENT.md` says so where the variable is set, because that is where somebody will be standing.

## CI

GitHub Actions runs. "CI green" in a pull request now means the three required checks are green on GitHub,
and a reviewer can see it rather than take a pasted figure on trust. The full local gate is still run before
opening a pull request, because the server does not run the end-to-end suite on every push.

## Working alongside another session

- Never `git add .`, `git add -A`, or a whole directory. Never reset, checkout, restore, stash or reformat files you
  did not change. Do not run Pint or Prettier over a directory containing another session's work.
- Before every commit: `git status --short` and `git diff --cached --name-only`.

## Emergency

The owner may push to `main` directly with `git push --no-verify` when production is broken and the normal path is
slower than the damage. Afterwards, add an entry here: date, commit, why, and the PR that followed it.

| Date | Commit | Why | Follow-up PR |
|---|---|---|---|
| — | — | — | — |
