<!--
Answer every section. "None" is an answer and a useful one; a blank line is not.
A section left empty is read as "not considered", because that is usually what it means.
-->

## Request / issue

Closes #

## Root cause

<!-- The mechanism, not the symptom. For a fix: what was actually wrong and how you proved it — a failing test that
     passes afterwards is the proof. For new work: what was missing and why it belongs here. -->

## Scope

<!-- What changed, in files and in behaviour — and what deliberately did NOT change where somebody might expect it.
     If another session's uncommitted files are in the folder, say that none of them are in this branch. -->

## Tests and gate

<!-- While GitHub Actions cannot run (docs/CI_STATUS.md), the local gate output below IS the CI evidence.
     Paste the real counts. Capture exit codes on their own line — piping into `tail` reports tail's exit code. -->

```
governance : bash scripts/governance/test-no-paid-model.sh && bash scripts/governance/no-paid-model.sh
backend    : pint --test · phpstan (level 8) · pest
frontend   : typecheck · lint · format:check · vitest · build
e2e        : scripts/e2e.sh (desktop / mobile-safari / E2E_AGENCY=1)
```

- [ ] Fail-first demonstrated (for a defect)
- [ ] Backend: `vendor/bin/pint --test` · `vendor/bin/phpstan analyse` · `vendor/bin/pest` — all clean
- [ ] Frontend: `npm run typecheck` · `npm run lint` · `npm run format:check` · `npm test` · `npm run build` — all clean
- [ ] End-to-end run, when the change is reachable from a browser
- [ ] Arabic RTL and English LTR checked, when the change is visible

## Security and permissions

<!-- New endpoint, permission, input, upload, anything touching auth, sessions, tokens or approval links?
     Say how it fails closed, and which test proves it. -->

## Tenant and project isolation

<!-- Does anything read or write across workspaces, clients or projects? Which test proves one cannot see another's
     data through this path? -->

## Money, approvals and providers

- [ ] Changes no live spend, status or targeting — or does so only through an approval a person decides
- [ ] No provider capability claimed beyond what its own documentation says
- [ ] Nothing recorded as sent, connected or `LIVE_VERIFIED` without a real provider response

## Agents and models

- [ ] No agent gained a permission, a tool that writes to a platform, or a way round the approval layer
- [ ] No workflow, script or code path makes GitHub invoke a model (`scripts/governance/no-paid-model.sh` green)

## Database migration

- [ ] No migration
- [ ] Migration included — reversible, and run up → down → up
- [ ] Migration rewrites existing rows (say which, and what a rollback does to them)

## Rollback

<!-- How to undo this, and what is lost if you do. -->

## Evidence

<!-- Command output, screenshots, the thing itself — not a claim about it. -->

---

- [ ] Only files this change touched are staged — checked with `git diff --cached --name-only`
- [ ] No secret, key or token in any file of any commit on this branch
- [ ] `CHANGELOG.md` and `docs/RESUME_STATE.md` updated where the state moved
