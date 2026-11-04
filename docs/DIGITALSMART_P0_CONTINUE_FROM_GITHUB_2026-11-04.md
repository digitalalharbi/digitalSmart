# digitalSmart — GitHub-only continuation directive

**ID:** DS-P0-RESUME-2026-11-04  
**Date:** 2026-11-04 (Asia/Riyadh)  
**Repository:** https://github.com/digitalalharbi/digitalSmart  
**Authoritative directive:** [DIGITALSMART_P0_MASTER_EVOLUTION_EXECUTION_2026-11-03.md](./DIGITALSMART_P0_MASTER_EVOLUTION_EXECUTION_2026-11-03.md)  
**Purpose:** Resume the **same existing P0 instruction**, from the latest GitHub state. This is a continuation checkpoint, **not a replacement prompt, a new scope, a new project, or an approval to bypass safeguards**.

## 0. Remote-only operating mode
- The source repository and its PRs/workflows are on GitHub. The owner's Mac terminal is **not** the execution environment; do not ask the owner to install `claude`, run a terminal, clone the repository, or restart local processes.
- Operate inside a GitHub-connected engineering environment with repository read/write tools. If the current agent environment lacks GitHub write access, report exactly that access blocker; do not claim to have executed any PR or deploy.
- Resume the existing work and decisions as recorded by GitHub, not by assumptions about whether an old computer/terminal/session stayed running.
- Work exclusively on `digitalalharbi/digitalSmart`. CampaignsHub and Mohtam are **functional references only**. Never copy their production data, tokens, credentials, tenant records, URLs or code without an explicit authorised and reviewed transfer.
- Read the authoritative 635-line master MD completely. It remains unchanged, in force, and takes priority over this checkpoint on product scope.

## 1. First GitHub actions — fresh truth outranks this snapshot
1. Read `CLAUDE.md` on `main` and the master directive from branch `chore/p0-baseline` (where this checkpoint also resides).
2. Fetch current `main`, all open PRs, head SHAs, mergeability, required CI checks, workflow logs, branch protection, and the latest deployed SHA **if one really exists**. Do not use a stale snapshot to merge.
3. Review `docs/RESUME_STATE.md`, `docs/UNIFIED_COMMERCE_MATRIX.md`, `docs/GOOGLE_ANALYTICS.md`, `docs/REPORTING.md`, `docs/AGENTS.md`, `docs/LIVE_COMMISSIONING.md`, `docs/DEPLOYMENT.md`, and the P0 matrix being introduced in PR #76.
4. Reconcile the single authoritative requirement matrix with merged code and open PRs; preserve evidence and do not duplicate requirement IDs or create a second implementation engine.
5. Continue the incomplete slice, then the next eligible P0 slice in dependency order. Do not reset or repeat finished work.

## 2. Last independently checked GitHub snapshot (not a substitute for fresh read)
Checked on 2026-11-04 morning, Riyadh time:
- `main`: `1e19215bc6a6df0a8474e947c859af0ca2f24720`.
- **#75 merged** on 2026-11-03 22:55:58 Riyadh: store-timezone daily grouping, weekly/monthly trends, prior-period comparison (DS-UC-202).
- **#74 open**: ad-sync on disconnected account (DS-UC-103), head `aba648110b22`, CI SUCCESS (Governance/Backend/Frontend/E2E), mergeability `clean` on last read. Validate fresh and merge only if repository rules and checks still permit; don't rebuild the same fix.
- **#76 open**: P0 baseline documentation/matrix, previous head `a717d982728b`, now this branch also contains the full master and this continuation document. Previous CI failed **only in E2E**; other jobs green. Review changed contents and rerun. Do not treat documentation as completed functionality.
- **#77 open**: pause/resume live report share at its exact URL, head `72eadd9323f1`; Governance, Backend, Frontend SUCCESS; E2E FAILED. The implementation is not merged or production verified. Finish reliable E2E, review security, merge when compliant.
- Latest GitHub production deployment workflow was marked SUCCESS **only because** job `Production server not configured` succeeded and actual job `Deploy main to production` was **SKIPPED**. Status remains `AWAITING_SERVER_ACCESS`, never `LIVE_VERIFIED`.
- Existing P0 baseline in #76 enumerated 38 requirement rows (none production VERIFIED), and is a **baseline**, not 38 delivered features. Recalculate based on actual Git state.

## 3. What interrupted the flow — concrete CI diagnosis
Recent failing E2E jobs:
- #77 run `41728798039`, E2E job `126245836395`.
- #76 run `41708396622`, E2E job `126179855014`.
- main run `41707863244`, E2E job `126175384372`.

Visible repeated failure: `[mobile-safari] e2e/responsive.spec.ts:38` — `narrow screens › no surface is wider than the screen (en)`, with 45-second timeouts, `page.evaluate` target/context closed, and sometimes the Arabic equivalent. A few runs also report `shell-mobile-navigation` or `access-requests` delayed.
- Reproduce in a comparable browser/runtime; inspect Playwright traces/screenshots, server logs, interaction setup, resource pressure and test isolation.
- Determine root cause (product regression vs test infrastructure/flakiness). Write a regression test or targeted reproduction where applicable, make the smallest correct fix, and rerun appropriate full gates.
- **Do not** silently skip Mobile Safari, raise timeouts endlessly, remove tests, weaken assertions, or label a non-green run green. Failure of a CI job is not proof that the new report link feature is faulty; investigate rather than guess.

## 4. Immediate continuation sequence
A. Reconfirm live GitHub state and branch dependencies; preserve PR #74, #76 and #77, do not open duplicate PRs for already implemented slices.  
B. Repair/triage common E2E blockers first, while preserving product functionality. Re-run and review affected PR CI.  
C. Merge the safe #74 disconnect guard when branch rules pass and no conflicting changes remain.  
D. Complete #76 requirements/reference baseline and #77 same-URL pause/resume, with tested public page/API/export/cache behaviour. If sequencing demands rebasing, do so non-destructively.  
E. Continue P0 **without new owner instructions**: GA4 read-only integration and analytics, store/GA4/ad-platform reconciliation, tracking/UTM and influencer attribution where evidence supports it, customizable LIVE/snapshot reports and secure links, unified login/Google, capabilities, agents/automation, notifications/digests/email, media previews, performance, mobile UX, security and commissioning as defined in the full master. Prioritize security and implementation dependencies over arbitrary numbering.  
F. For each small complete slice: diagnose → fail-first test where suitable → implement → security/tenant tests → Backend/Frontend/E2E CI → PR → merge → authorized deploy → independent live verification → update matrix and resume state → continue next slice.

## 5. Hard invariants
- No changes to financial spend, live campaign status/budget, billing, production user data, live provider credentials or irreversible migrations without suitable authorisation and reversible controls.
- All workspace/client/project scope and RBAC enforcement is **server-side**, including reports, URLs, export, caches, jobs, notifications, agents and public links.
- A shared report raw token is stored only as a hash: **lost old URLs cannot be reconstructed**. Pause/resume must preserve the originally issued token without exfiltration; client-side copy is available only while the original URL is still held. Provide a secure replacement mechanism if lost.
- Store is the actual order/revenue authority; do not sum duplicate GA4, ad-platform or store sales. Label source, window, currency, data completeness, sample/threshold, timezone and freshness.
- Never call sandbox results LIVE. Missing credentials/approval/server are precise external blockers, not a reason to stop all unrelated engineering.
- Always distinguish `IMPLEMENTED_NOT_VERIFIED` from `VERIFIED`. CI success alone is not a production deployment.
- Do not ask the owner to retype the P0 master or explain the request anew; the master is now present on this GitHub branch. Ask only for genuinely external/irreversible authorisation when necessary.

## 6. Progress/reporting contract
Update existing GitHub requirement matrix and `docs/RESUME_STATE.md` as slices merge. Every row must identify status, acceptance, code/tests, PR/commit, actual deployed SHA, production evidence and exact remaining blocker. Give the owner a **concise** factual checkpoint: merged items, active PRs + checks, what was tested, remaining blockers, current next slice. Do not proclaim “100% complete” unless every applicable requirement has verifiable acceptance evidence.

**Bottom line:** Resume the original P0 programme from GitHub, not from the owner's laptop and not by initiating a different instruction.