# Resume state

## START HERE

**Source of truth:** `origin/main` on https://github.com/digitalalharbi/digitalSmart (private). Run
`git fetch origin && git log --oneline -10 origin/main && git status` before anything else; where this file and Git
disagree, Git is right.

### Where things stand — 2026-10-19

| Area | State |
|---|---|
| Product | Agentic campaign OS: ten areas, Today as the one decision queue, seven agents, rules with trust levels, one-time approval links, cross-platform builder, campaign hierarchy, bulk actions, shared period. See `CHANGELOG.md`. |
| Providers | All live connections `AWAITING_CREDENTIALS`; model use `AWAITING_MODEL_CREDENTIALS`; WhatsApp / email delivery `awaiting_credentials`. Nothing is `LIVE_VERIFIED`. |
| Governance | Bootstrap, path to `main`, no-fees rule in `CLAUDE.md`; templates in `.github/`; governance guard in CI and the `pre-push` hook. |
| CI | **GitHub Actions blocked by the account's billing state** — `docs/CI_STATUS.md`. Option B recommended; **awaiting owner decision**. |
| Protection of `main` | Server-side unavailable on this plan while private; local `pre-push` hook active. |
| Other session | Leaves uncommitted money/format work (≈31 files: `money.ts` in attribution/budgets/commerce, lead panels, `vite.config.ts`, five docs). Not ours — never stage, format or restore it. |

### Last verified gates (local)

- Backend: PHPStan level 8 clean, Pint clean; feature suites for every new slice green. Full suite: see the latest PR.
- Frontend: typecheck, ESLint, Prettier clean; 730 unit tests; production build.
- E2E desktop: 210 passed, 1 failed + 1 flaky in the dashboard builder under machine load — being re-run in isolation.

### Next

1. Owner: choose a CI option (`docs/CI_STATUS.md`).
2. Re-run the dashboard builder E2E in isolation; run `E2E_AGENCY=1` (incl. `e2e/agents.spec.ts`) and mobile Safari.
3. Commerce surfaces onto the shared period once the other session's money/format work lands.
