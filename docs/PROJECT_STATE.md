# Project state — digitalSmart

**What this file is.** The recovery checkpoint required by the owner's global operating policy: the
last verified commit, what is open, what verifies it, and the safe next action. It is deliberately
short and mechanical.

**What this file is not.** It is not a second narrative. The live product state is
[`docs/RESUME_STATE.md`](RESUME_STATE.md) and the history is [`CHANGELOG.md`](../CHANGELOG.md);
this file points at them rather than restating them, because two state documents that can disagree
are worse than one. Where this file and Git disagree, **Git is right and this file is stale.**

Updated 2026-11-05.

## Last verified commit

| | |
|---|---|
| Repository | `digitalalharbi/digitalSmart` (public) |
| Branch | `main` |
| Commit | `9d26605` — docs(deployment): the one available server was assessed and is not the production server (#102) |
| Full sha | `9d2660590423ead46be98f9d45e66c9e49049657` |
| Evidence | #100 merged at head `e0ba45a` with Governance, Backend, Frontend and E2E green and up to date. **Nothing is deployed** (`AWAITING_SERVER_ACCESS`) and no email has been sent |

Verify, do not trust:

```bash
git fetch origin && git log --oneline -10 origin/main && git status
```

## Active pull requests

| PR | Branch | Title |
|---|---|---|
| the PR carrying this file | `feat/signin-destination` | feat(auth): sign-in lands in the application the person uses — once it is on `main`, none is open |

CI on each is the authority — `Governance`, `Backend` and `Frontend` are required and the branch
must be up to date with `main`:

```bash
gh pr list --repo digitalalharbi/digitalSmart --state open
gh pr checks <number> --repo digitalalharbi/digitalSmart
```

## Commands that verify

```bash
make verify                 # backend + frontend gates
make test                   # suites only
make lint && make analyse   # Pint, ESLint, Prettier, PHPStan level 8
make e2e                    # Playwright; runs scripts/check-disk.sh first (10 GB)
scripts/governance/no-paid-model.sh        # the first CI job, and the pre-push hook
scripts/governance/test-no-paid-model.sh   # proves the guard fails on each forbidden thing
scripts/sync-status.sh      # is main green, and is that what is live
```

Enable the local hook once per clone: `git config core.hooksPath .githooks` — already set in this
clone.

## Outstanding work and blockers

Read `docs/RESUME_STATE.md` under **START HERE** for the product queue. The standing external
blockers, in the project's own vocabulary:

| State | What is waiting |
|---|---|
| `AWAITING_SERVER_ACCESS` | A production server — host, SSH user, port, deploy path, host key, process names — then the `production` environment's secrets and `PRODUCTION_ENABLED=true` (`docs/DEPLOYMENT.md`) |
| `AWAITING_CREDENTIALS` | Every live provider connection; every stored credential in this installation is the literal `sandbox` |
| `AWAITING_MODEL_CREDENTIALS` | The product's own model use; the deterministic path runs without a key |
| `BLOCKED_EXTERNAL` | Live WhatsApp and email delivery |

Nothing in this repository is `LIVE_VERIFIED`.

## Decisions that outrank a recollection

- `origin/main` is the single source of truth. A local branch, a worktree or an earlier
  conversation is not.
- **One session, one branch, one worktree.** The main working tree is not shared scratch space;
  `git worktree add ../<name> -b <branch> origin/main`.
- Stage only the files you changed, by explicit path. Never `git add .`, `-A`, or a directory.
- No change reaches `main` except through a branch, a pull request and green required CI. A merge to
  `main` deploys to production.
- GitHub is source control, CI and deployment only — **it never invokes a model**
  (`docs/EXECUTION_POLICY.md`). The global policy's preference for cloud/remote sessions on long
  work does not apply here; the long-running path in this repository is CI/CD.

## Safe next actions

1. `git fetch origin` and re-read `docs/RESUME_STATE.md`; where it disagrees with `main`, the
   document is the defect.
2. Take the next item from the **Next** list in `docs/RESUME_STATE.md` on its own branch and
   worktree from the freshest `origin/main`.
3. Keep this file's *Last verified commit* and *Active pull requests* current at each milestone.
