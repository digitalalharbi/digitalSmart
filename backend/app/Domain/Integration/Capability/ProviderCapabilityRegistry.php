<?php

declare(strict_types=1);

namespace App\Domain\Integration\Capability;

use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderEntityWrites;
use App\Domain\Integration\WriteSupport;

/**
 * What each advertising platform's own documentation says it can do, for every step of a campaign launch, with the page it was
 * read on. Read on 2026-10-24 for creation, targeting, placement, conversion, validation, idempotency and read-back; the status
 * and budget writes the product already carries out keep their own evidence in {@see ProviderEntityWrites}, so there is one
 * source for what is attempted.
 *
 * Documented is not permitted: every platform also needs an approval or access level before a live write (`access`), which is
 * the owner's to obtain. And a platform whose new campaigns deliver by default is named, because a create that forgets the paused
 * value spends money.
 */
final class ProviderCapabilityRegistry
{
    public const string VERIFIED_ON = '2026-10-24';

    private const array PROVIDERS = [
        'meta' => ['api' => 'Marketing API v25.0 (reference) / v26.0 (guides)', 'paused' => 'status=PAUSED on campaign, ad set and ad (only ACTIVE or PAUSED accepted at creation)', 'delivers_by_default' => false, 'access' => 'ads_management; other businesses\' accounts need Full (advanced) access through App Review', 'access_ref' => 'https://developers.facebook.com/docs/marketing-api/get-started/authorization'],
        'google_ads' => ['api' => 'Google Ads API v26', 'paused' => 'campaign.status=PAUSED (recommended before ads are attached); ad groups and ads accept PAUSED but the examples use ENABLED', 'delivers_by_default' => false, 'access' => 'developer token at Basic (brand verification) or Standard access', 'access_ref' => 'https://developers.google.com/google-ads/api/docs/api-policy/access-levels'],
        'tiktok' => ['api' => 'API for Business v1.3', 'paused' => 'operation_status=DISABLE on campaign, ad group and ad — the default is ENABLE at every level, so it must always be sent', 'delivers_by_default' => true, 'access' => 'developer registration and app review (2–3 business days) with the chosen scopes', 'access_ref' => 'https://business-api.tiktok.com/portal/docs/create-a-campaign/v1.3'],
        'snapchat' => ['api' => 'Marketing API v1', 'paused' => 'status=PAUSED on campaign (required) and ad (required); ad squad status optional with no stated default — send PAUSED', 'delivers_by_default' => false, 'access' => 'snapchat-marketing-api scope through a Business Manager OAuth app', 'access_ref' => 'https://developers.snap.com/api/marketing-api/Ads-API/authentication'],
        'linkedin' => ['api' => 'Marketing API li-lms-2026-10 (Linkedin-Version: 202610)', 'paused' => 'campaign status=PAUSED or DRAFT; creative intendedStatus=PAUSED or DRAFT; campaign group status not confirmed (example shows ACTIVE)', 'delivers_by_default' => false, 'access' => 'rw_ads and Advertising API approval; Development tier edits up to 5 ad accounts', 'access_ref' => 'https://learn.microsoft.com/en-us/linkedin/marketing/increasing-access'],
        'x' => ['api' => 'Ads API v12', 'paused' => 'campaign entity_status=PAUSED or DRAFT (default ACTIVE); line item entity_status=PAUSED; a promoted tweet takes no status on create', 'delivers_by_default' => true, 'access' => 'Ads API access form per app; Standard Access for campaign management', 'access_ref' => 'https://docs.x.com/x-ads-api/getting-started'],
        'openai' => ['api' => 'ChatGPT Ads API v1 (https://api.ads.openai.com)', 'paused' => 'status=paused, required on campaign, ad group and ad (the quickstart examples use active)', 'delivers_by_default' => false, 'access' => 'an ad account and API key from Ads Manager; an account cannot serve until its brand review is approved', 'access_ref' => 'https://developers.openai.com/ads/api-partner-setup'],
    ];

    /** key → provider → [state, official page, note]. Status and budget writes are read from ProviderEntityWrites instead. */
    private const array CELLS = [
        'campaign.create' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/campaigns/', 'POST /act_{id}/campaigns; special_ad_categories is required'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/campaigns/create-campaigns', 'CampaignService.MutateCampaigns'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-a-campaign/v1.3', 'POST /open_api/v1.3/campaign/create/ — from 2027-01-01 no longer for non-Search Legacy Manual Sales, App Promotion and Lead Generation campaigns'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/campaigns', 'POST /v1/adaccounts/{id}/campaigns'],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/getting-started', 'POST /rest/adAccounts/{id}/adCampaignGroups — a LinkedIn campaign group is this product\'s campaign'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', 'POST accounts/:id/campaigns'],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-reference/campaigns', 'POST /campaigns'],
        ],
        'campaign.update' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-campaign-group/', 'POST /{campaign_id}'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/campaigns/create-campaigns', 'CampaignService mutate with update_mask'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-a-campaign/v1.3', '/campaign/update/'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/campaigns', 'PUT with the entire object — omitted attributes with defaults are reset'],
            'linkedin' => ['unverified', null, 'Campaign group update not confirmed on the pages read'],
            'x' => ['unverified', null, 'Only the activation PUT was seen'],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-reference/campaigns', 'POST /campaigns/{id}'],
        ],
        'campaign.activate' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-campaign-group/', 'status=ACTIVE'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/campaigns/create-campaigns', 'status=ENABLED'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-a-campaign/v1.3', '/campaign/status/update/ with ENABLE'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/campaigns', 'PUT status=ACTIVE'],
            'linkedin' => ['unverified', null, 'Campaign group status not confirmed'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management', 'PUT entity_status=ACTIVE'],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-reference/campaigns', 'POST /campaigns/{id}/activate'],
        ],
        'ad_group.create' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/adsets/', 'POST /act_{id}/adsets'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/campaigns/search-campaigns/getting-started', 'AdGroupService'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', '/open_api/v1.3/adgroup/create/'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/ad-squads', 'POST /v1/campaigns/{id}/adsquads'],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/create-and-manage-campaigns', 'POST /rest/adAccounts/{id}/adCampaigns — a LinkedIn campaign is this product\'s ad group'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', 'POST accounts/:id/line_items'],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-reference/ad-groups', 'POST /ad_groups'],
        ],
        'ad_group.update' => [
            'meta' => ['unverified', null, null],
            'google_ads' => ['unverified', null, null],
            'tiktok' => ['unverified', null, null],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/ad-squads', 'PUT with the entire object'],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/create-and-manage-campaigns', 'POST with X-RestLi-Method: PARTIAL_UPDATE'],
            'x' => ['unverified', null, null],
            'openai' => ['unverified', null, null],
        ],
        'ad.create' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/ads/', 'POST /act_{id}/ads'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/campaigns/search-campaigns/getting-started', 'AdGroupAdService'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs?id=1739953377508354', '/open_api/v1.3/ad/create/'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/ads', 'POST /v1/adsquads/{id}/ads'],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/create-and-manage-creatives', 'POST /rest/adAccounts/{id}/creatives'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', 'POST accounts/:id/promoted_tweets'],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-reference/ads', 'POST /ads'],
        ],
        'ad.update' => [
            'meta' => ['unverified', null, null],
            'google_ads' => ['unverified', null, null],
            'tiktok' => ['unverified', null, null],
            'snapchat' => ['unverified', null, null],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/create-and-manage-creatives', 'POST /rest/adAccounts/{id}/creatives/{urn}'],
            'x' => ['unverified', null, null],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-reference/ads', 'POST /ads/{id}'],
        ],
        'creative.upload' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/adimages/', 'POST /act_{id}/adimages; video via /advideos in chunked phases'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/assets/working-with-assets', 'AssetService ImageAsset; video only as a YouTube asset'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs?id=1739953377508354', '/file/image/ad/upload/ and /file/video/ad/upload/'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/media', 'POST /v1/adaccounts/{id}/media, then a chunked upload'],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api', 'POST /rest/images?action=initializeUpload'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management', 'chunked media upload, then accounts/:id/media_library'],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-quickstart', 'POST /upload'],
        ],
        'targeting.location' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/adsets/', null],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/targeting/criteria', null],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', null],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/targeting', null],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/advertising-targeting/ads-targeting', null],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', null],
            'openai' => ['documented', 'https://developers.openai.com/ads/campaign-targeting', null],
        ],
        'targeting.demographic' => [
            'meta' => ['unverified', null, 'Age and gender were not on the page read'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/targeting/criteria', null],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', 'gender, age_groups'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/targeting', null],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/advertising-targeting/ads-targeting', 'fixed age ranges'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', null],
            'openai' => ['unverified', null, 'Only geo, platform and custom audiences are documented'],
        ],
        'targeting.interest' => [
            'meta' => ['unverified', null, null],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/targeting/criteria', 'user interest criteria'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', 'interest_category_ids'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/targeting', null],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/advertising-targeting/ads-targeting', null],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', null],
            'openai' => ['unverified', null, null],
        ],
        'targeting.custom_audience' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/adsets/', 'custom_audiences'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/targeting/criteria', 'user lists'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', 'audience_ids'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/targeting', 'segments'],
            'linkedin' => ['blocked_external', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/advertising-targeting/ads-targeting', 'Matched Audiences is a separate application'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', null],
            'openai' => ['documented', 'https://developers.openai.com/ads/campaign-targeting', null],
        ],
        'targeting.lookalike' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/audiences/guides/lookalike-audiences', null],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/remarketing/audience-segments/lookalike-audiences', 'Demand Gen campaigns only'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', '/dmp/custom_audience/lookalike/create/'],
            'snapchat' => ['unverified', null, 'Listed only as an option type'],
            'linkedin' => ['unverified', null, 'No lookalike facet listed'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', null],
            'openai' => ['unverified', null, null],
        ],
        'placement.manual' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/audiences/reference/placement-targeting', 'publisher_platforms and *_positions'],
            'google_ads' => ['unverified', null, 'The placements page could not be read'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', 'PLACEMENT_TYPE_NORMAL'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/ad-squads', 'placement_v2.config=CUSTOM'],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/create-and-manage-campaigns', 'offsiteDeliveryEnabled for the Audience Network'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', 'placements'],
            'openai' => ['documented', 'https://developers.openai.com/ads/campaign-targeting', 'platform only (iOS, Android, web)'],
        ],
        'placement.automatic' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/audiences/reference/placement-targeting', 'leave placement fields out'],
            'google_ads' => ['unverified', null, null],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', 'PLACEMENT_TYPE_AUTOMATIC'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/ad-squads', 'placement_v2.config=AUTOMATIC'],
            'linkedin' => ['unverified', null, null],
            'x' => ['unverified', null, null],
            'openai' => ['unverified', null, null],
        ],
        'conversion.optimize' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/adsets/', 'optimization_goal with promoted_object.pixel_id'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/campaigns/bidding/assign-strategies', 'target_cpa, maximize_conversion_value'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-an-ad-group-reference/v1.3', 'pixel_id with optimization_event'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/ad-squads', 'PIXEL_* optimisation goals'],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/create-and-manage-campaigns', 'optimizationTargetType=MAX_CONVERSION'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', 'goal=WEBSITE_CONVERSIONS'],
            'openai' => ['documented', 'https://developers.openai.com/ads/conversion-tracking', 'bidding_type=conversions'],
        ],
        'validation.preflight' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/campaigns/', 'execution_options=["validate_only"]'],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/campaigns/create-campaigns', 'validate_only'],
            'tiktok' => ['unverified', null, 'No validation found on the create pages'],
            'snapchat' => ['unverified', null, null],
            'linkedin' => ['unverified', null, null],
            'x' => ['unverified', null, null],
            'openai' => ['unverified', null, 'Preview shows appearance; it is not validation'],
        ],
        'idempotency.native' => [
            'meta' => ['unverified', null, null],
            'google_ads' => ['unverified', 'https://developers.google.com/google-ads/api/docs/best-practices/partial-failures', 'partial_failure only; no idempotency key'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-a-campaign/v1.3', 'request_id on campaign create, de-duplicated within 10 seconds'],
            'snapchat' => ['unverified', null, null],
            'linkedin' => ['unverified', null, null],
            'x' => ['unverified', null, 'Batches of up to 40 succeed or fail together; no key'],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-overview', 'Idempotency-Key header on supported creates'],
        ],
        'readback' => [
            'meta' => ['documented', 'https://developers.facebook.com/docs/marketing-api/reference/ad-campaign-group/', 'GET /{id}?fields='],
            'google_ads' => ['documented', 'https://developers.google.com/google-ads/api/docs/query/overview', 'GoogleAdsService Search with GAQL'],
            'tiktok' => ['documented', 'https://business-api.tiktok.com/portal/docs/create-a-campaign/v1.3', 'GET /open_api/v1.3/campaign/get/'],
            'snapchat' => ['documented', 'https://developers.snap.com/api/marketing-api/Ads-API/campaigns', 'GET /v1/campaigns/{id}'],
            'linkedin' => ['documented', 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/create-and-manage-campaigns', 'GET .../adCampaigns/{id}'],
            'x' => ['documented', 'https://docs.x.com/x-ads-api/campaign-management/reference', 'GET accounts/:id/campaigns/:campaign_id'],
            'openai' => ['documented', 'https://developers.openai.com/ads/api-reference/campaigns', 'GET /campaigns/{id}'],
        ],
        /* ChatGPT Ads has no entry in the write table yet (no adapter is built), so its status and budget evidence lives here. */
        'campaign.pause' => ['openai' => ['documented', 'https://developers.openai.com/ads/api-reference/campaigns', 'POST /campaigns/{id}/pause']],
        'campaign.resume' => ['openai' => ['documented', 'https://developers.openai.com/ads/api-reference/campaigns', 'POST /campaigns/{id}/activate']],
        'campaign.budget.update' => ['openai' => ['documented', 'https://developers.openai.com/ads/api-reference/campaigns', 'POST /campaigns/{id} with budget']],
        'ad_group.pause' => ['openai' => ['documented', 'https://developers.openai.com/ads/api-reference/ad-groups', 'POST /ad_groups/{id}/pause']],
        'ad_group.resume' => ['openai' => ['documented', 'https://developers.openai.com/ads/api-reference/ad-groups', 'POST /ad_groups/{id}/activate']],
        'ad.pause' => ['openai' => ['documented', 'https://developers.openai.com/ads/api-reference/ads', 'POST /ads/{id}/pause']],
        'ad.resume' => ['openai' => ['documented', 'https://developers.openai.com/ads/api-reference/ads', 'POST /ads/{id}/activate']],
    ];

    /** @return list<Provider> */
    public static function providers(): array
    {
        return array_map(static fn (string $p): Provider => Provider::from($p), array_keys(self::PROVIDERS));
    }

    public static function evidence(Provider $provider, CapabilityKey $key): CapabilityEvidence
    {
        $operation = $key->writeOperation();

        if ($operation !== null && ! isset(self::CELLS[$key->value][$provider->value])) {
            $support = ProviderEntityWrites::support($provider, $operation);
            $state = match (true) {
                $support === WriteSupport::Documented => CapabilityState::Documented,
                ProviderEntityWrites::isNotOffered($provider, $operation) => CapabilityState::Unsupported,
                default => CapabilityState::Unverified,
            };

            return new CapabilityEvidence(
                $provider, $key, $state,
                ProviderEntityWrites::reference($provider, $operation),
                self::PROVIDERS[$provider->value]['api'] ?? null,
                $state === CapabilityState::Documented ? '2026-10-19' : null,
                $state === CapabilityState::Documented ? null : ProviderEntityWrites::notOffered($provider, $operation),
            );
        }

        $cell = self::CELLS[$key->value][$provider->value] ?? null;

        if ($cell === null) {
            return new CapabilityEvidence($provider, $key, CapabilityState::Unverified, null, null, null, 'Not read for this platform.');
        }

        $state = CapabilityState::from($cell[0]);

        return new CapabilityEvidence(
            $provider, $key, $state, $cell[1],
            self::PROVIDERS[$provider->value]['api'] ?? null,
            $cell[1] === null ? null : self::VERIFIED_ON,
            $cell[2],
        );
    }

    /**
     * How a platform takes a campaign that must not deliver, whether it delivers when that is left out, and what access a live
     * write needs.
     *
     * @return array{api: string, paused: string, delivers_by_default: bool, access: string, access_ref: string}|null
     */
    public static function profile(Provider $provider): ?array
    {
        return self::PROVIDERS[$provider->value] ?? null;
    }
}
