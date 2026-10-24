# Campaign launch acceptance report

From a plan to campaigns on several platforms, created paused, read back, and switched on only with approval
(`docs/CAMPAIGN_LAUNCH.md`). Nothing here is `LIVE_VERIFIED`: no campaign has been created on any real advertising account, because
no live credential or platform write access exists in this installation.

| Requirement | Status | Evidence |
|---|---|---|
| Provider-neutral plan; never creates on a platform while editing; no credentials | VERIFIED | `CampaignPlanTest` (E) |
| Twelve states; `approved` never means executed | VERIFIED | `BlueprintStatus`, `CampaignPlanTest`, `CampaignLaunchTest` |
| Ten-step wizard, Arabic RTL and English LTR, desktop and phone | VERIFIED | `campaign-plans.spec.ts` (Chromium and mobile Safari, axe clean) |
| Reuses tracking links, conversions and Creative Hub; no duplicate UTM or creative store | VERIFIED | references checked against the project (`CampaignPlanTest`) |
| Eight canonical objectives; the translator decides, no forced equivalence | VERIFIED | `BlueprintTranslatorTest` |
| Capability registry: 26 keys × 7 platforms, each with the official page, version and date | VERIFIED | `ProviderCapabilityRegistryTest`; audit of 2026-10-24 |
| Create plan per account; payload drafts paused at every level; per-currency totals | VERIFIED | `LaunchPlanTest` (F) |
| Read-only preflight with six states and a fix for each; never creates to test | VERIFIED | `LaunchPlanTest` |
| Approval with a fingerprint; a change after approval voids it | VERIFIED | `CampaignLaunchTest` (G) |
| Approve, execute and activate are separate permissions; media buyers hold none | VERIFIED | `CampaignLaunchTest` |
| Queued creation, one execution per account, stable idempotency reference | VERIFIED | `CampaignLaunchTest` |
| Created ≠ verified: read-back before verified; mismatch reported, never auto-corrected | VERIFIED | `CampaignLaunchTest` |
| Unknown outcome never blindly retried; reconcile, then retry only proven failures | VERIFIED | `CampaignLaunchTest` |
| Partial launch keeps what was created | VERIFIED | `CampaignLaunchTest` |
| Reconciled into the existing campaign / ad group / ad tables, no second model | VERIFIED | `CampaignLaunchTest` |
| Activation separate, two acts, confirmation required, active only after read-back | VERIFIED | `CampaignLaunchTest` (H), `ActivationApproval.test.tsx` |
| Launch board by state with counts and filters; launch issues in Needs attention | VERIFIED | `CampaignLaunchTest` (filters, attention stream) |
| Audit: requested, approved, executed, provider ids, read-back, source; no secrets | VERIFIED | audit assertions in `CampaignLaunchTest` |
| Campaign launch is a plan feature enforced on the server; monthly launch quota | VERIFIED | `CampaignPlanTest`, `LaunchPlanTest` |

## What runs today, stated plainly

- **Sandbox connections** run the whole path — creation, read-back, reconciliation, activation — and every record, outcome and
  audit entry is labelled **SANDBOX**.
- **Real credentials** are recorded `blocked_external` at creation and activation, and **no platform is called**: no live creation
  or activation adapter exists, and no platform has confirmed this product's write access.

## External blockers

| Platform | What a live launch needs | Status |
|---|---|---|
| Meta | `ads_management`; Full (advanced) access through App Review for other businesses' accounts | `BLOCKED_EXTERNAL` |
| Google Ads | developer token at Basic (brand verification) or Standard access | `BLOCKED_EXTERNAL` |
| TikTok | developer registration and app review with write scopes; note the 2027-01-01 create cut-off for some legacy campaign types | `BLOCKED_EXTERNAL` |
| Snapchat | Marketing API OAuth app in Business Manager | `AWAITING_CREDENTIALS` |
| LinkedIn | `rw_ads` and Advertising API approval (Development tier: 5 accounts) | `BLOCKED_EXTERNAL` |
| X | Ads API access form; Standard Access for campaign management | `BLOCKED_EXTERNAL` |
| ChatGPT Ads | an ad account and API key; brand review approved before serving; no translator or adapter built yet | `BLOCKED_EXTERNAL` |
| All | live creation and activation adapters, built from the payload drafts and verified against a real account | follows the access above |
