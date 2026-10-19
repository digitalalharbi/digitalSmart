<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Advertising\AdEntityType;

/**
 * What each advertising platform's **own** documentation says it lets an API change — provider × level × change.
 *
 * Read on 2026-10-19 on each platform's developer site (Meta developers.facebook.com, Google
 * developers.google.com/google-ads, TikTok business-api.tiktok.com — rendered in a browser, the portal serves nothing
 * to a plain fetch — Snapchat developers.snap.com, X docs.x.com, LinkedIn learn.microsoft.com/linkedin). Third-party
 * SDKs and directories are not evidence and were not used. Every cell is its own reading: support at one level is
 * never inferred from another, which is how "X pauses campaigns" would otherwise become "X pauses ads" — it does not.
 *
 * The levels are this product's: an ad group is a Meta ad set, a Snapchat ad squad, an X line item and a LinkedIn
 * *campaign*; LinkedIn's *campaign group* is this product's campaign.
 *
 * A bid is documented at the ad-group level on every platform here and is not wired in this product, so it is
 * recorded separately ({@see self::bid()}) and never offered as an action.
 */
final class ProviderEntityWrites
{
    private const array DOCUMENTED = [
        'meta' => [
            'pause_campaign' => 'POST /{campaign_id} with status=ACTIVE|PAUSED — pausing a campaign also pauses its ad sets and ads',
            'resume_campaign' => 'POST /{campaign_id} with status=ACTIVE',
            'set_campaign_daily_budget' => 'POST /{campaign_id} with daily_budget (minor units) — only where the budget is held on the campaign: "campaign or ad set, not both"',
            'pause_ad_group' => 'POST /{adset_id} with status=PAUSED — Marketing API, ad set reference',
            'resume_ad_group' => 'POST /{adset_id} with status=ACTIVE',
            'set_ad_group_daily_budget' => 'POST /{adset_id} with daily_budget (minor units) — only where the budget is held on the ad set, not the campaign',
            'pause_ad' => 'POST /{ad_id} with status=PAUSED — Marketing API, ad reference',
            'resume_ad' => 'POST /{ad_id} with status=ACTIVE',
        ],
        'google_ads' => [
            'pause_campaign' => 'CampaignService.MutateCampaigns, status=PAUSED, update_mask=status',
            'resume_campaign' => 'CampaignService.MutateCampaigns, status=ENABLED, update_mask=status',
            'set_campaign_daily_budget' => 'CampaignBudgetService.MutateCampaignBudgets with amount_micros and update_mask — the budget is its own resource, at campaign level only',
            'pause_ad_group' => 'AdGroupService.MutateAdGroups, status=PAUSED, update_mask=status',
            'resume_ad_group' => 'AdGroupService.MutateAdGroups, status=ENABLED, update_mask=status',
            'pause_ad' => 'AdGroupAdService.MutateAdGroupAds, status=PAUSED, update_mask=status ("Pause ad" sample)',
            'resume_ad' => 'AdGroupAdService.MutateAdGroupAds, status=ENABLED, update_mask=status',
        ],
        'tiktok' => [
            'pause_campaign' => 'POST /v1.3/campaign/update/ (operation_status=DISABLE); /campaign/status/update/ named in TikTok\'s MCP tool list',
            'resume_campaign' => 'POST /v1.3/campaign/update/ (operation_status=ENABLE)',
            'set_campaign_daily_budget' => 'POST /v1.3/campaign/update/ with budget — at least 105% of what has already been spent',
            'pause_ad_group' => 'POST /v1.3/adgroup/status/update/ with operation_status=DISABLE (up to 20 ids; not for Reach & Frequency ad groups)',
            'resume_ad_group' => 'POST /v1.3/adgroup/status/update/ with operation_status=ENABLE',
            'set_ad_group_daily_budget' => 'POST /v1.3/adgroup/budget/update/ — a scheduled_budget takes effect around 00:00 the next day in the account\'s time zone; at least 105% of spend',
            'pause_ad' => 'POST /v1.3/ad/status/update/ with operation_status=DISABLE',
            'resume_ad' => 'POST /v1.3/ad/status/update/ with operation_status=ENABLE',
        ],
        'snapchat' => [
            'pause_campaign' => 'PATCH /v1/adaccounts/{ad_account_id}/campaigns with status=PAUSED',
            'resume_campaign' => 'PATCH /v1/adaccounts/{ad_account_id}/campaigns with status=ACTIVE',
            'set_campaign_daily_budget' => 'PATCH /v1/adaccounts/{ad_account_id}/campaigns with daily_budget_micro (minimum 20,000,000)',
            'pause_ad_group' => 'PATCH /v1/campaigns/{campaign_id}/adsquads with status=PAUSED',
            'resume_ad_group' => 'PATCH /v1/campaigns/{campaign_id}/adsquads with status=ACTIVE',
            'set_ad_group_daily_budget' => 'PATCH /v1/campaigns/{campaign_id}/adsquads with daily_budget_micro (minimum 5,000,000)',
            'pause_ad' => 'PATCH /v1/adsquads/{ad_squad_id}/ads with status=PAUSED',
            'resume_ad' => 'PATCH /v1/adsquads/{ad_squad_id}/ads with status=ACTIVE',
        ],
        'x' => [
            'pause_campaign' => 'PUT accounts/:account_id/campaigns/:campaign_id with entity_status=PAUSED',
            'resume_campaign' => 'PUT accounts/:account_id/campaigns/:campaign_id with entity_status=ACTIVE',
            'set_campaign_daily_budget' => 'PUT accounts/:account_id/campaigns/:campaign_id with daily_budget_amount_local_micro',
            'pause_ad_group' => 'PUT accounts/:account_id/line_items/:line_item_id with entity_status=PAUSED',
            'resume_ad_group' => 'PUT accounts/:account_id/line_items/:line_item_id with entity_status=ACTIVE',
            'set_ad_group_daily_budget' => 'PUT accounts/:account_id/line_items/:line_item_id with daily_budget_amount_local_micro — only when the campaign\'s budget_optimization is LINE_ITEM',
        ],
        'linkedin' => [
            /*
             * This product's campaign is LinkedIn's campaign *group*. The reference recorded here before this matrix
             * named /adCampaigns — LinkedIn's campaign, this product's ad group — which would have paused one level
             * below the one a person approved.
             */
            'pause_campaign' => 'POST /rest/adAccounts/{id}/adCampaignGroups/{id}, X-RestLi-Method: PARTIAL_UPDATE, patch.$set.status=PAUSED',
            'resume_campaign' => 'POST /rest/adAccounts/{id}/adCampaignGroups/{id}, X-RestLi-Method: PARTIAL_UPDATE, patch.$set.status=ACTIVE',
            'set_campaign_daily_budget' => 'POST /rest/adAccounts/{id}/adCampaignGroups/{id} PARTIAL_UPDATE patch.$set.dailyBudget — only when budgetOptimizationStrategy=DYNAMIC (API 202504 or later)',
            'pause_ad_group' => 'POST /rest/adAccounts/{id}/adCampaigns/{id}, X-RestLi-Method: PARTIAL_UPDATE, patch.$set.status=PAUSED',
            'resume_ad_group' => 'POST /rest/adAccounts/{id}/adCampaigns/{id}, X-RestLi-Method: PARTIAL_UPDATE, patch.$set.status=ACTIVE',
            'set_ad_group_daily_budget' => 'POST /rest/adAccounts/{id}/adCampaigns/{id} PARTIAL_UPDATE patch.$set.dailyBudget{amount,currencyCode}',
            'pause_ad' => 'POST /rest/adAccounts/{id}/creatives/{urn} PARTIAL_UPDATE patch.$set.intendedStatus=PAUSED — not while the creative is in review',
            'resume_ad' => 'POST /rest/adAccounts/{id}/creatives/{urn} PARTIAL_UPDATE patch.$set.intendedStatus=ACTIVE',
        ],
    ];

    /** Where the platform says plainly that it does not offer a change — not a gap in what was read. */
    private const array NOT_OFFERED = [
        'google_ads' => ['set_ad_group_daily_budget' => 'Google Ads keeps budgets on a campaign budget resource; an ad group has none.'],
        'x' => [
            'pause_ad' => 'X: "It is not possible to update (PUT) promoted Tweet entities" — only GET, POST and DELETE.',
            'resume_ad' => 'X: "It is not possible to update (PUT) promoted Tweet entities" — only GET, POST and DELETE.',
        ],
    ];

    /** Whose documentation each reference was read in — named in every reference, so it is never a bare path. */
    private const array API = [
        'meta' => 'Meta Marketing API',
        'google_ads' => 'Google Ads API',
        'tiktok' => 'TikTok API for Business v1.3',
        'snapchat' => 'Snapchat Marketing API',
        'x' => 'X Ads API',
        'linkedin' => 'LinkedIn Marketing API',
    ];

    /** The bid field each platform documents on an ad group. Recorded, not wired: no bid write exists in this product. */
    private const array BID = [
        'meta' => 'bid_amount on the ad set',
        'google_ads' => 'cpc_bid_micros on the ad group (AdGroupService)',
        'tiktok' => 'bid_price on the ad group, only when bid_type is BID_TYPE_CUSTOM',
        'snapchat' => 'bid_micro on the ad squad',
        'x' => 'bid_amount_local_micro on the line item, when bid_strategy is MAX or TARGET',
        'linkedin' => 'unitCost.amount on the LinkedIn campaign',
    ];

    public static function support(Provider $provider, WriteOperation $operation): WriteSupport
    {
        if (isset(self::DOCUMENTED[$provider->value][$operation->value])) {
            return WriteSupport::Documented;
        }

        return WriteSupport::NotSupported;
    }

    /** The endpoint and fields read, with any condition the platform attaches; null where nothing is attempted. */
    public static function reference(Provider $provider, WriteOperation $operation): ?string
    {
        $reference = self::DOCUMENTED[$provider->value][$operation->value] ?? null;

        return $reference === null ? null : sprintf('%s — %s', $reference, self::API[$provider->value] ?? $provider->label());
    }

    /** Why a platform cannot take a change at a level, in its own words where it said so. */
    public static function notOffered(Provider $provider, WriteOperation $operation): ?string
    {
        if (self::support($provider, $operation) === WriteSupport::Documented) {
            return null;
        }

        return self::NOT_OFFERED[$provider->value][$operation->value]
            ?? sprintf('%s has no %s at this level in its documentation.', $provider->label(), $operation->change() === 'budget' ? 'budget' : 'status change');
    }

    /** The documented bid field at the ad-group level, or null. Never an action here: no bid write is wired. */
    public static function bid(Provider $provider, AdEntityType $level): ?string
    {
        return $level === AdEntityType::AdGroup ? (self::BID[$provider->value] ?? null) : null;
    }

    /** Whether the platform's own documentation was read for this matrix — the six advertising platforms with an API. */
    public static function read(Provider $provider): bool
    {
        return isset(self::DOCUMENTED[$provider->value]);
    }
}
