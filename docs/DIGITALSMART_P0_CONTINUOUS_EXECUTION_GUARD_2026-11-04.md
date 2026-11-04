# digitalSmart — Continuous Execution & CI Follow-through Guard

**Directive ID:** `DS-P0-CONTINUITY-GUARD-2026-11-04`  
**Product/repository:** `digitalalharbi/digitalSmart` only  
**Priority:** P0 — continuity and completion discipline  
**Authority:** **Addendum** to, not a replacement for, `docs/DIGITALSMART_P0_MASTER_EVOLUTION_EXECUTION_2026-11-03.md` and `docs/DIGITALSMART_P0_CONTINUE_FROM_GITHUB_2026-11-04.md`.  
**Owner intent:** No repeated prompts or instructions for normal engineering decisions, CI results, PR merges, or movement to the next already-approved P0 task.

> **Important truth:** A Markdown instruction cannot keep a terminated agent session alive or restart it automatically. It can require the agent **while running** to observe CI until a result, continue with ready work, and write a durable GitHub checkpoint before its run ends. True unattended continuation between separate agent runs requires the execution platform's supported persistent agent/scheduler or a separately authorised GitHub-triggered agent workflow. Do not claim a background worker was created by this file.

## 1. Immediate root cause and required behavior change

The observed agent message ended with: **"I'm waiting on CI for #76, #77 and #78 and not polling."** This is **not an acceptable stopping point** for an autonomous work session while more executable P0 work exists.

Replace idle waiting with the following executable loop:
1. Check all active PR head SHAs and required checks using GitHub's current state, not a historical result.
2. If checks are **running**, watch/poll them through supported `gh pr checks --watch`, `gh run watch`, GitHub actions/check APIs or equivalent; in parallel, work on the **next independent, safely scoped P0 slice**.
3. If checks **fail**, inspect the exact failing job/trace, reproduce and fix the root cause, push the smallest correction, and watch the **new** SHA's checks. Never hide failure by skipping E2E/Mobile Safari or weakening product assertions.
4. If checks **pass**, validate mergeability/required checks/current base, merge by the repository's normal rules, rebase or merge-main into dependent PRs when needed, rerun checks on the changed head and continue.
5. If the result remains pending and no independent work is possible, keep observing for a **bounded, reasoned period**, not indefinitely. If the execution session is about to end or a tool/runtime limit is reached, leave a reproducible checkpoint (Section 7). Never assert that progress will continue on its own outside the active agent process.
6. Do not repeatedly re-run expensive full suites without a new reason; use existing completed runs tied to the exact SHA, target the failure, and rerun full required gates before merging.

**Forbidden as a final answer:** “Waiting on CI,” “not polling,” “tell me to continue,” “I finished the plan,” or any unqualified “100% complete.”

## 2. GitHub snapshot at file creation — re-read before action

As observed 2026-11-04 (Asia/Riyadh), not a static mandate:

| Item | Actual observed state | Next appropriate action |
| --- | --- | --- |
| `main` | `5bd92f3308539c7d879caa076a5740f6bf9b6a11` | Fetch latest; check new merges |
| PR #74 | **MERGED**, merge commit `5bd92f3` | Do **not** reimplement |
| PR #76 | Open; `b5f51449dca8`; CI **IN PROGRESS** | Review outcome; preserve master + continuation + this guard; merge when compliant |
| PR #77 | Open; `d17880ffa551`; CI **IN PROGRESS** | Validate Mobile Safari and report wizard gate; safe merge when green |
| PR #78 | Open; `a602e1200a85`; CI **IN PROGRESS** | Inspect GA4 ingest migration/mapper, run gates, merge when green |
| Deployment | GitHub action may say SUCCESS while actual production step is SKIPPED because no production server | Mark `AWAITING_SERVER_ACCESS`; do not claim deployed or VERIFIED |

The main PR streams may land in a different safe order according to checks and conflicts. Never blindly merge from stale SHA.

### Evidence about #78
- GA4 ingest stores sandbox acquisition metrics by day, channel grouping, source and medium; metric absence is `null`, not `0`.
- It adds a traffic table/writer/mapper/sync hook and tests; **it does not yet implement read model/API/UI, nor live OAuth/Data API transport**.
- Do not label its fixture rows as live traffic.

## 3. Mandatory execution cycle

```text
FRESH GITHUB STATE
  -> choose highest-impact unblocked requirement from ORIGINAL P0 master
  -> verify existing code and tests (avoid duplication)
  -> reproduce issue / establish acceptance target
  -> implement narrow slice in isolated branch/worktree
  -> targeted tests + negative authorization/tenant tests
  -> full required CI + PR
  -> watch checks and repair any failures
  -> safe merge + handle dependent PRs
  -> production deploy ONLY if an authorized configured target exists
  -> real acceptance or honest blocked/not-verified status
  -> update existing requirement matrix and resume-state
  -> immediately choose the next unblocked slice
```

A worktree is an **agent execution detail**, not a requirement that the owner use a Mac or run commands. Work in a GitHub-connected coding environment; the GitHub repository is the source of truth.

While one CI is active, perform **independent** work:
- Inspect/prepare next GA4 read model/API and cross-project access tests.
- Prepare downstream GA4 dashboard/report components only after the upstream interface contract is stable.
- Triage currently failing CI and its artifacts.
- Review existing report-share or auth surfaces for regressions without changing the pending PR's unrelated files.
- Record exact blockers and move to an unrelated ready requirement.

Avoid concurrent edits to the **same migration, shared changelog, matrix, or mutable branch**. Coordinate dependency rebases when merging.

## 4. Specific next P0 chain after #76/#77/#78

Continue the **original** master, not a new mini-roadmap:

1. Complete #76/#77/#78 safely with all required checks and security acceptance. #74 is already merged.
2. GA4: read model and API over `ga4_traffic_daily`, restricted by workspace/client/project and property access, with negative cross-scope tests and correct rate/denominator handling.
3. GA4: visual Analytics surface (KPI cards, trends, traffic channel/source/medium, UTM where available, engagement, session/visitor dimensions), data freshness and source-quality flags. Never sum users from multiple dimensions as unique users; never equate GA4 sessions with advertising clicks.
4. GA4: ecommerce event funnel only where events genuinely exist, distinct from merchant order/revenue authority; integrate with report sections, Live/Snapshot, shared-link visibility and exports.
5. Reports: intent (Executive vs Detailed), same-link updates, duplication, pause/resume, privacy/visibility, snapshot immutability, secure lost-link handling, audit, CSV/XLSX/PDF parity when implemented.
6. Continue GA4 live **read-only** OAuth/Data API transport where credentials and approvals allow; test error cases 401/403/429, quotas and source freshness. If external permissions absent, mark only that external verification as blocked and continue independent code.
7. Continue automation/agents, login/Google, notifications and email, integration health/creative previews, performance/mobile, and operational readiness in dependency-aware priority from the master and existing matrix.

**Do not create a second GA4 engine, report engine, login architecture, agent framework or notification queue.**

## 5. Branch/merge/CI safety

- Keep **small PRs**: one independent vertical slice or cohesive defect fix per PR. Maintain one isolated worktree/branch per active slice.
- Each merge must pass current repository review and protection gates, **including required Mobile Safari/E2E if required**. No bypassing branch protection because a check is slow.
- A red E2E for `responsive.spec.ts` is a problem to investigate, not an automatic conclusion that GA4 or reports are defective. Use logs, screenshots, Playwright traces, realistic CI resource limits and reruns only with evidence.
- On conflict, preserve content from both sides if valid; resolve semantically (especially `CHANGELOG.md` and traceability tables); re-run tests and update PR head.
- Read all merged commits, and do not merge same changes twice.
- A CI-successful, merged commit **does not** prove a live product: if deployment job is skipped, status stays `IMPLEMENTED_NOT_VERIFIED` or an exact external blocker.

## 6. Boundaries and external blockers

- No alteration of real ad spend, campaign status/budgets, billing/subscriptions, provider app settings, confidential data, or irreversible production actions outside authorized runbooks.
- No cross-tenant, cross-workspace, cross-client, cross-project reads/writes; scope applies to queries, exports, caches, public report links, jobs, automations, notifications and agents.
- Preserve report share token hashing; do not pretend old tokens can be extracted from the hash.
- Real GA4 access, Google sign-in, SMTP/SMS, provider previews, and live deployment each require real credentials/infrastructure. Classify as `BLOCKED_EXTERNAL_CREDENTIALS`, `BLOCKED_OPERATIONAL_EVIDENCE` or the repository's compatible current state, with one exact owner/provider action.
- Never invent provider data, turn fixture into live, or merge store+GA4+platform revenue as if they were distinct sales.
- Do not wait for external credentials to finish independent code, tests, UI and documentation.
- Ask for explicit approval **only** at genuine external or irreversible boundaries requiring it. Routine implementation, test repair and safe PR steps are already delegated.

## 7. Mandatory persistent checkpoint before every session ends

If the agent can continue now, it **must continue**. If environment limits/tool permissions terminate a session, preserve a truthful GitHub-resident handoff through the existing `docs/RESUME_STATE.md` / requirement matrix or a PR comment (no duplicate project backlog), specifying:

```text
UTC and Asia/Riyadh timestamp:
current main SHA:
open PRs with heads and check statuses:
last merged PR(s):
last failed CI job ID / first actionable error:
last tested code area and exact command:
current worktree/branch and uncommitted files (if applicable):
next atomic step (one actionable action):
next 3 P0 dependencies:
external blockers and exact party/action:
production state (real deployment evidence or explicit skipped):
```

Do not write secrets, raw tokens, client data, or sensitive logs. Do not claim the file has initiated a persistent agent. Updating a checkpoint does not itself complete a P0 requirement.

## 8. Completion standard

The agent's active run ends only when (a) all currently executable P0 work it can safely complete in that execution has been completed with evidence; or (b) a real runtime/token/tool/credential/irreversible-action limit prevents continuation. In case (b), save the exact checkpoint and report the blocker. Continue to the next unblocked approved task instead of waiting idly for an unrelated blocker.

`VERIFIED` requires implementation, merge, real deployment, and production acceptance where applicable. Any unavailable production test remains explicit.

**Instruction to the executing agent:** The owner expects you to **act**, not to stop after a progress update. As long as the execution environment is active and authorized tasks remain, continue the next existing P0 slice without asking for another prompt.
