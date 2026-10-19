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
| CI | **Runs.** The owner chose option C on 2026-10-19: the repository is public, so Actions and rulesets are free. `Governance` green; `Backend` **fixed here** (the attention query budget); **`Frontend` still red** — see below. `docs/CI_STATUS.md`. |
| Protection of `main` | **Server-side ruleset `main protection` active** (id 25378817): PR required, `Governance`/`Backend`/`Frontend` required and up to date, no force-push, no deletion. Admin bypass kept so the documented emergency push stays real, which is why the local `pre-push` hook is still the guard against the owner's own slip. |
| Other session | Leaves uncommitted money/format work (≈31 files: `money.ts` in attribution/budgets/commerce, lead panels, `vite.config.ts`, five docs). Not ours — never stage, format or restore it. **It now blocks CI**: `src/shared/lib/money.test.ts` is on `main` and the `vite.config.ts` that lets it read the backend's `CurrencyExponent.php` is not, so the required `Frontend` check fails for everyone. |

### Last verified gates (local)

- Backend: PHPStan level 8 clean, Pint clean; feature suites for every new slice green. Full suite: see the latest PR.
- Frontend: typecheck, ESLint, Prettier clean; 730 unit tests; production build.
- E2E desktop: 210 passed, 1 failed + 1 flaky in the dashboard builder under machine load — being re-run in isolation.

### Next

1. **Other session: commit `frontend/vite.config.ts`.** Until it lands, `Frontend` fails on `main` and on
   every pull request, and the ruleset will not let anything merge. One file; nothing else is wrong — it is
   the last thing standing between this repository and a green `main`.
2. Enable the `merge_queue` rule on the ruleset — **after** the `merge_group` trigger is on `main`
   (PR `chore/public-ci-merge-queue`), never before, or queued entries wait for a status that cannot arrive.
3. Re-run the dashboard builder E2E in isolation; run `E2E_AGENCY=1` (incl. `e2e/agents.spec.ts`) and mobile Safari.
4. Commerce surfaces onto the shared period once the other session's money/format work lands.
