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

## Still not verified

- Google Ads: the field reference's mutability flags (the guides and samples were read).
- TikTok: the campaign status page itself (the endpoint is confirmed through `/campaign/update/` and the MCP tool list);
  the Smart+ write endpoints.
- Whether X publishes an official MCP server.

## Production

`AWAITING_SERVER_ACCESS` — see `docs/DEPLOYMENT.md`. Once access exists: deploy `main`, migrate, build the frontend,
restart services, health-check, record the deployed SHA. Live reads and writes then wait on each platform's
credentials and app review (`docs/PROVIDER_WRITES.md`).
