# Execution policy

**Owner decision, 2026-10-19.** Binding on every session, human or agent.

## Where development runs

All product development — reading, writing, testing, committing — happens from a **Claude Code session on the
owner's machine**. Nothing develops, reviews or writes code on GitHub's side.

## What GitHub is for

Source control, CI and (later) deployment. **GitHub never invokes Claude, Anthropic or any other model.**

## No fees

Nothing in this repository may cause a charge to the owner's GitHub or Anthropic accounts.

| Never | Why |
|---|---|
| `.github/workflows/claude.yml`, any autopilot, any `@claude` mention path | A model invoked from GitHub is a paid development path outside the session |
| `anthropics/claude-code-action`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, a call to a model API | Same |
| A secret **value** in any file, workflow literal, commit, log or document | A value in Git is a value leaked; the guard scans every tracked file for key and token shapes |
| Any secret reference other than the approved deployment secrets in `deploy-production.yml` | Encrypted secrets exist for deployment infrastructure only (below) |
| A `schedule:` trigger | Nothing runs unattended |
| A paid plan, paid minutes or paid runners switched on to make something work | The fix for a plan limit is an owner decision, never a purchase made in passing |

## Secrets

GitHub Actions encrypted secrets are **allowed for deployment infrastructure only**, in the `production` environment,
and referenced only from `.github/workflows/deploy-production.yml`:

`PRODUCTION_HOST` · `PRODUCTION_USER` · `PRODUCTION_SSH_KEY` · `PRODUCTION_PORT` · `PRODUCTION_PATH` ·
`PRODUCTION_KNOWN_HOSTS`

| Safe | Unsafe |
|---|---|
| `${{ secrets.PRODUCTION_SSH_KEY }}` in `deploy-production.yml` | A private key, token or password written into any file |
| Provider and model credentials in the server's `shared/.env` or the product's encrypted store | Provider or model credentials as GitHub secrets — unless separately justified and added to the guard by an owner decision |
| | `${{ toJSON(secrets) }}` or `secrets[...]` — dynamic reads |

## How it is enforced

- `scripts/governance/no-paid-model.sh` — fails if any workflow breaks a rule above, if a tracked file contains a key or
  token, if the deploy workflow could run from anything but `main` or without its concurrency lock, if a deploy file
  contains a destructive database command, or if `ci.yml` loses its `governance` / `backend` / `frontend` jobs.
  Comments are ignored, so a file may explain the rules in prose.
- `scripts/governance/test-no-paid-model.sh` — proves the guard fails on each forbidden thing and passes what is
  allowed (21 cases).
- Both run as the first CI job (`governance`), which every other job waits on, and the guard runs in the local
  `pre-push` hook.

## The product's own model use is separate

The product calls a language model only with **a workspace's own key** (or a deployment key the owner sets), through
`ModelRouter`, and runs deterministically without one (`AWAITING_MODEL_CREDENTIALS`). That is product behaviour on the
owner's infrastructure, not GitHub invoking a model.
