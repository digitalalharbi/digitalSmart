# Provider readiness — provider × level × change

What each advertising platform's **own** documentation lets this product read and change, at each level, and how far
each operation has been proven here. Read on **2026-10-19** on each platform's developer site; third-party SDKs,
directories and blogs are not evidence. The code that holds this is `App\Domain\Integration\ProviderEntityWrites`;
`GET /api/integrations/readiness` publishes it; the Integrations page shows it.

Levels are this product's: an **ad group** is a Meta ad set, a Snapchat ad squad, an X line item and a LinkedIn
*campaign*; LinkedIn's *campaign group* is this product's campaign. Support at one level is never inferred from another.

## The ladder

| Rung | Means | Reached |
|---|---|---|
| `CODE_VERIFIED` | Modelled, gated and tested against the documented contract | Every documented cell |
| `SANDBOX_VERIFIED` | Ran end to end against sandbox credentials (`ProviderReadinessTest`, `CarryOutApprovalTest`, `EntityLevelRulesTest`) | Every documented cell |
| `AWAITING_CREDENTIALS` | Needs a real connection — none exists in this installation | Next rung, all providers |
| `LIVE_READ_VERIFIED` | Real credentials, a real read, the result seen in the product | None |
| `LIVE_WRITE_VERIFIED` | A real write confirmed by reading the platform back | None |

No rung is skipped. With real credentials stored and no live adapter built, the matrix says `live_adapter_not_built`.

## Reads

Campaigns, ad groups and ads are documented as readable on all six platforms, and the sync adapters read all three
levels from response-shaped fixtures (`SANDBOX_VERIFIED`).

## Changes

✅ documented (sandbox verified) · ✖ not offered by the platform · ◐ documented by the platform, not wired here

| Platform | Level | Pause / resume | Daily budget | Bid |
|---|---|---|---|---|
| Meta | Campaign | ✅ `POST /{campaign_id}` status | ✅ `daily_budget` — only where the budget sits on the campaign | ✖ |
| | Ad set | ✅ `POST /{adset_id}` status | ✅ `daily_budget` — only where the budget sits on the ad set | ◐ `bid_amount` |
| | Ad | ✅ `POST /{ad_id}` status | ✖ | ✖ |
| Google Ads | Campaign | ✅ `CampaignService` status | ✅ `CampaignBudgetService` `amount_micros` | ✖ (bidding strategy) |
| | Ad group | ✅ `AdGroupService` status | ✖ no budget on an ad group | ◐ `cpc_bid_micros` |
| | Ad | ✅ `AdGroupAdService` status | ✖ | ✖ |
| TikTok | Campaign | ✅ `/campaign/update/` `operation_status` | ✅ `budget` ≥ 105% of spend | ✖ |
| | Ad group | ✅ `/adgroup/status/update/` | ✅ `/adgroup/budget/update/` — takes effect ~00:00 next day | ◐ `bid_price` (custom bid only) |
| | Ad | ✅ `/ad/status/update/` | ✖ | ✖ |
| Snapchat | Campaign | ✅ PATCH status | ✅ `daily_budget_micro` ≥ 20,000,000 | ✖ |
| | Ad squad | ✅ PATCH status | ✅ `daily_budget_micro` ≥ 5,000,000 | ◐ `bid_micro` |
| | Ad | ✅ PATCH status | ✖ | ✖ |
| X | Campaign | ✅ PUT `entity_status` | ✅ `daily_budget_amount_local_micro` | ✖ |
| | Line item | ✅ PUT `entity_status` | ✅ only when the campaign's `budget_optimization` is `LINE_ITEM` | ◐ `bid_amount_local_micro` |
| | Promoted tweet | ✖ "not possible to update (PUT) promoted Tweet entities" | ✖ | ✖ |
| LinkedIn | Campaign group | ✅ `adCampaignGroups` PARTIAL_UPDATE status | ✅ `dailyBudget` only with `budgetOptimizationStrategy=DYNAMIC` | ✖ |
| | Campaign | ✅ `adCampaigns` PARTIAL_UPDATE status | ✅ `dailyBudget` | ◐ `unitCost.amount` |
| | Creative | ✅ `creatives` `intendedStatus` (not while in review) | ✖ | ✖ |

Every write goes through the direct API, where the change is read back to confirm it — never through an MCP server.

## MCP

| Platform | Official MCP | Writes |
|---|---|---|
| Google Ads | developers.google.com — read-only | none |
| Snapchat | developers.snap.com — read-only | planned |
| Meta | developers.facebook.com — "create and edit ads, ad sets, campaigns" | documented, not used |
| TikTok | business-api.tiktok.com — ~400 tools incl. status and budget updates | documented, not used |
| X, LinkedIn | none found on their own documentation | — |

## What changed from the earlier reading

- **TikTok and X are no longer "unverified".** TikTok's portal was read in a browser (it serves nothing to a plain
  fetch); X's reference at docs.x.com was readable. Both are now recorded per level.
- **LinkedIn's campaign-level reference was one level too low.** It named `/adCampaigns` — LinkedIn's campaign, this
  product's ad group. Campaign-level writes now name `/adCampaignGroups`.
- **X cannot pause an ad**, whatever it lets a campaign or line item do.

## Commissioning matrix

Every cell below is `SANDBOX_VERIFIED` unless it says otherwise; the rung after it is `AWAITING_CREDENTIALS` for every
provider. **No cell is `LIVE_READ_VERIFIED` or `LIVE_WRITE_VERIFIED`** — no live credential exists here, and a live
rung needs a real round trip seen in the product.

| Platform | Entity | READ | PAUSE | RESUME | BUDGET | BID |
|---|---|---|---|---|---|---|
| Meta | Campaign / Ad set / Ad | sandbox | sandbox | sandbox | sandbox · sandbox · — | — · unsupported · — |
| Google Ads | Campaign / Ad group / Ad | sandbox | sandbox | sandbox | sandbox · not offered · — | — · unsupported · — |
| TikTok | Campaign / Ad group / Ad | sandbox | sandbox | sandbox | sandbox · sandbox · — | — · unsupported · — |
| Snapchat | Campaign / Ad squad / Ad | sandbox | sandbox | sandbox | sandbox · sandbox · — | — · unsupported · — |
| X | Campaign / Line item / Promoted tweet | sandbox | sandbox · sandbox · **not offered** | same | sandbox · sandbox (LINE_ITEM only) · — | — · unsupported · — |
| LinkedIn | Campaign group / Campaign / Creative | sandbox | sandbox | sandbox | sandbox · sandbox · — | — · unsupported · — |

"—" is a change the platform does not offer at that level. **Bid is `UNSUPPORTED_IN_DIGITALSMART`** wherever the platform
documents one (the ad group): no bid write exists, so no rule, request or button offers it.

## Closed on 2026-10-19, from current official documentation

| Gap | Verdict | Evidence |
|---|---|---|
| Google Ads: `status` and `CampaignBudget.amount_micros` mutability | **VERIFIED** (campaign status, budget amount); ad-group and ad status inferred from the same convention | v26 (released 2026-10-14) RPC reference marks read-only fields "Output only." and set-once ones "Immutable."; `Campaign.status` and `CampaignBudget.amount_micros` carry neither. Update goes through `updateMask` (`rest/common/mutate`). The field-reference pages have no "updatable" attribute. |
| Google Ads: an official example changing `amount_micros` | **UNVERIFIED** | The budget guides only set it on create; no official update sample was found. Budget support rests on the field reference and the generic mutate contract. |
| TikTok: campaign status endpoint | **VERIFIED** | `POST /open_api/v1.3/campaign/status/update/` — `advertiser_id`, `campaign_ids` (1–20), `operation_status` DELETE / DISABLE / ENABLE. (The earlier reference named `/campaign/update/`; corrected.) |
| TikTok: upgraded Smart+ | **VERIFIED, and different** | Smart+ has its own endpoints: `/smart_plus/campaign/status/update/` (same fields) and `/smart_plus/campaign/update/` (`budget`; `budget_auto_adjust_strategy`; `current_budget` returned). The manual endpoints are **not** claimed for Smart+ campaigns. From 2027-01-01 non-Search Sales / App / Lead Gen campaigns can no longer be created on the manual flow. Smart+ writes are **`UNSUPPORTED_IN_DIGITALSMART`** until implemented; Smart+ ad-group budgets and per-level status pages were not read. An allow-list requirement was seen in a search result only — **UNVERIFIED**. |
| X: official MCP server | **VERIFIED: none for the Ads API** | `docs.x.com/tools/mcp`: XMCP (local, X API v2 tools, no advertising) and a docs-search server. No Ads API server. |

## Still not verified

- Google Ads: ad-group and ad status mutability was inferred from the marker convention, not read on those pages; an
  official budget-update sample was not found.
- TikTok: Smart+ ad-group budget and per-level status pages; the allow-list requirement for Smart+.
- Manual-campaign `/campaign/update/` budget fields were not re-read in this pass.

## Campaign creation (2026-10-24)

Creation, targeting, placement, conversion optimisation, validation, idempotency and read-back were read on each platform's own
documentation for the campaign launch path. The per-key states with their pages are in the provider capability registry
(`GET /api/integrations/capabilities`); the findings that change behaviour — TikTok and X deliver by default, only Meta and Google
validate without creating, ChatGPT Ads now publishes an advertiser API — are in `docs/CAMPAIGN_LAUNCH.md`.

## Production

`AWAITING_SERVER_ACCESS` — see `docs/DEPLOYMENT.md`. Once access exists: deploy `main`, migrate, build the frontend,
restart services, health-check, record the deployed SHA. Live reads and writes then wait on each platform's
credentials and app review (`docs/PROVIDER_WRITES.md`).
