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
5. Review, then merge on GitHub. A merge on GitHub is not a push to `main`. Where the merge queue is
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
