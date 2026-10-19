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
5. Review, then merge on GitHub (squash or merge commit — a merge on GitHub is not a push to `main`).
6. `git fetch origin` — that is the state for the next piece of work.

## Protection of main

| Layer | State |
|---|---|
| Server-side branch protection / rulesets | **Not available**: GitHub answers *"Upgrade to GitHub Pro or make this repository public to enable this feature"* (HTTP 403, checked 2026-10-19). A paid upgrade is excluded by the no-fees rule. |
| Local `pre-push` hook | **Active** — `.githooks/pre-push` refuses any push to `main` and runs the governance guard. Enable per clone with `git config core.hooksPath .githooks`. |
| Rule in `CLAUDE.md` | **Binding** on every session |

If the owner makes the repository public (`docs/CI_STATUS.md`, option C), protection is enabled the same day:
require a pull request, require the `governance`, `backend` and `frontend` checks, no force-push, no deletion.

## CI

See `docs/CI_STATUS.md`. While GitHub Actions cannot run, "CI green" in a PR means the local gate output pasted in it.

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
