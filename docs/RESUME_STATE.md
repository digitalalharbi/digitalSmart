# Resume state

## START HERE

**Source of truth:** `origin/main` on https://github.com/digitalalharbi/digitalSmart (public). Run
`git fetch origin && git log --oneline -10 origin/main && git status` before anything else; where this file and Git
disagree, Git is right.

### Where things stand — 2026-10-22

| Area | State |
|---|---|
| Product | Agentic campaign OS: ten areas, Today as the one decision queue, seven agents, rules with trust levels, one-time approval links, cross-platform builder, campaign hierarchy, bulk actions, shared period. See `CHANGELOG.md`. |
| Providers | All live connections `AWAITING_CREDENTIALS`; model use `AWAITING_MODEL_CREDENTIALS`; WhatsApp / email delivery `awaiting_credentials`. Nothing is `LIVE_VERIFIED`. |
| Governance | `CLAUDE.md`: bootstrap, pipeline, one session per branch/worktree, secret policy (no values anywhere; encrypted `PRODUCTION_*` deployment secrets only). Guard: 21 cases, first CI job and `pre-push`. |
| CI | **Runs and is required.** Public repository; `Governance`, `Backend`, `Frontend` required and up to date; E2E runs in CI. #3 (money/format work) and #2 (public CI, ruleset, merge queue) merged 2026-10-19 — `main` green. |
| Deployment | **Pipeline built, `AWAITING_SERVER_ACCESS`** — `docs/DEPLOYMENT.md`. Environment `production` exists (protected branches only), no secrets yet. |
| Protection of `main` | **Server-side ruleset `main protection` active** (id 25378817): PR required, `Governance`/`Backend`/`Frontend` required and up to date, no force-push, no deletion. Admin bypass kept so the documented emergency push stays real, which is why the local `pre-push` hook is still the guard against the owner's own slip. |
| Entity-level rules | **VERIFIED by CI** (#9, main `65e48d0`): ad-group and ad rules on their own metric rows, capability provider × level × change, dry run, history, bulk, hierarchy, readiness UI. Live reads/writes `AWAITING_CREDENTIALS`; bids `UNSUPPORTED_IN_DIGITALSMART`. |
| Agents & Automation | Rules = control, Agents = reasoning, Approvals = governance, Execution = provider mutation, Routines = schedule, Action history = evidence, Change log = configuration history. Auto refused; bid wired nowhere. Rules at campaign, ad-group and ad level, each on its own rows; capability provider × level × change (`docs/PROVIDER_READINESS.md`). |
| Creatives | **VERIFIED by CI** (#32–#35, #38, #39; main `41a6d9a`): aggregate creative read, `/creatives` page, client-safe creative report section — hero, gallery, trend switch, top creatives, comparison, format and platform mix, fatigue, ≤4 insights, ≤3 recommendations — shared-link narrowing by platform, campaign, creative and format, presentation-mode creative story in order. |
| Notifications | **VERIFIED by CI** (#36, #37, #40; main `41a6d9a`): severity by rule, a tab per kind, dedup/cooldown, daily/weekly/monthly performance digests at nine in workspace time, the centre with history and per-person settings, the ten alerts raised hourly from the product's own signals. Email/WhatsApp delivery (#41) is `AWAITING_CREDENTIALS`: one honest row per channel, nothing sent, `sent` unwritable by any adapter here. |
| Campaign operations | **VERIFIED by CI**: navigation + Arabic vocabulary (#42), campaign table (#43), Ad Groups (#44), Ads (#45), reports centre (#46; main `4a07cd4`). In review: PR 6 — campaign report preset, funnel and attributed-revenue sections, advanced settings, a page per report. |
| Visual restructure | PR #11 merged. Brand kit applied, sign-in screen and one-screen landing page (`/welcome`) in review on `feat/brand-identity`; nothing merged. Remaining: heavy-form audit beyond the client form, creative thumbnails from real assets (none exist), a measured before/after table. |
| Tracking & Funnels | Six-PR chain (`docs/TRACKING_FUNNELS.md`): A merged; B–F in review in order (links/redirect/events, forms→leads, funnels, health/debug, dispatch/mapping, reports/QR). Nothing dispatched to any provider; live delivery `BLOCKED_EXTERNAL`. |
| Access requests | Request → inbox → contact → approve/reject → invitation → account on acceptance → first sign-in (`docs/ACCESS_REQUESTS.md`). Email/WhatsApp delivery `AWAITING_CREDENTIALS`; nothing is shown as sent. |
| SaaS program | `docs/SAAS_CAMPAIGN_MASTER.md` A→H. **A** merged (#61): tenant route matrix + schema guard, six findings fixed, ADR 7. **B** merged (#62): one identity in many workspaces, revoked memberships, audited switch, switcher (`docs/SAAS.md`). **C** in review: plans, feature catalogue, entitlements on 74 routes, locked quotas, seats, overrides, Plan & usage (`docs/ENTITLEMENTS.md`). Next: **D** (subscription lifecycle, `/platform` control plane, suspension, onboarding). |
| Sessions | Each session works on its own branch and worktree; the shared folder is not scratch space. |

### Last verified gates (local)

- Backend: PHPStan level 8 clean, Pint clean; feature suites for every new slice green. Full suite: see the latest PR.
- Frontend: typecheck, ESLint, Prettier clean; 900 unit tests; production build.
- E2E desktop: 210 passed, 1 failed + 1 flaky in the dashboard builder under machine load — being re-run in isolation.

### Next

1. Owner: a production server — host, SSH user, port, deploy path, host key, web-server arrangement, process
   names (`docs/DEPLOYMENT.md`); then the `production` environment's secrets and `PRODUCTION_ENABLED=true`.
2. Free disk space (≈2 GB free on 2026-10-19; `scripts/check-disk.sh` needs 10 GB) and rerun desktop, agency and
   mobile-Safari E2E. Mobile Safari is not verified until it completes.
3. Agents & Automation is closed at campaign, ad-group and ad level (#7, #8, #9 — main green at `65e48d0`, E2E on
   Chromium and mobile Safari included). Next, in this order, with no new architecture phase before the first:
   A. provider live-read commissioning readiness; B. live-write commissioning readiness; C. the cross-platform
   campaign blueprint (created `PAUSED` or draft, never spending by accident); D. the production server
   (`AWAITING_SERVER_ACCESS`); E. credential connection. Matrix and gaps: `docs/PROVIDER_READINESS.md`.
