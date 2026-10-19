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
| A `secrets.` reference in any workflow | No workflow holds a secret, so none can be forwarded to a model or a paid service |
| A `schedule:` trigger | Nothing runs unattended |
| A paid plan, paid minutes or paid runners switched on to make something work | The fix for a plan limit is an owner decision, never a purchase made in passing |

## How it is enforced

- `scripts/governance/no-paid-model.sh` — fails if any workflow file breaks a rule above, or if `ci.yml` loses its
  `backend` / `frontend` jobs (the rule cannot be met by deleting CI). Comments are ignored, so a workflow may explain
  the rule in prose.
- `scripts/governance/test-no-paid-model.sh` — proves the guard fails on each forbidden thing (nine cases).
- Both run as the first CI job (`governance`), which every other job waits on, and the guard runs in the local
  `pre-push` hook.

## The product's own model use is separate

The product calls a language model only with **a workspace's own key** (or a deployment key the owner sets), through
`ModelRouter`, and runs deterministically without one (`AWAITING_MODEL_CREDENTIALS`). That is product behaviour on the
owner's infrastructure, not GitHub invoking a model.
