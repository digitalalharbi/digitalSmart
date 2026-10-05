<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Mapper;

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\ConversionRecord;
use App\Application\Advertising\DTO\CreativeAssetRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\ConversionActionType;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Advertising\ValueObject\Money;
use DateTimeImmutable;

/**
 * Google Ads API → digitalSmart.
 *
 * Reads `GoogleAdsRow` objects as the REST `searchStream` endpoint returns them,
 * so the shape is protobuf JSON: lowerCamelCase keys, enums as their name
 * strings, and **int64 fields as strings** rather than numbers. Field names were
 * taken from the v22 resource and metrics definitions.
 *
 * Google differs from Meta in ways that matter more than they look:
 *
 *   - Money is **micros** (a millionth of the unit), not minor units.
 *   - A campaign's budget lives on a separate `CampaignBudget` resource, joined
 *     into the row — an unshared budget behaves like a campaign budget, a shared
 *     one is used by several campaigns at once.
 *   - `status` is ENABLED/PAUSED/REMOVED. Whether it is actually running is
 *     `primaryStatus` (ELIGIBLE, LIMITED, MISCONFIGURED, LEARNING…), with
 *     `primaryStatusReasons` explaining why.
 *   - There is no `objective`. Intent is expressed through
 *     `advertisingChannelType` plus `advertisingChannelSubType` and the bidding
 *     strategy, which map onto our objectives only approximately.
 *   - `conversions` is a **fractional double** under Google's own attribution
 *     model, not an integer count.
 *   - There is no general deduplicated reach, and no link-click metric separate
 *     from `clicks`. Those stay null rather than being approximated.
 */
final class GoogleAdsMapper
{
    /** @param array<string, mixed> $row a GoogleAdsRow */
    public function campaign(array $row): CampaignRecord
    {
        $campaign = $this->object($row, 'campaign');
        $budget = $this->object($row, 'campaignBudget');
        $customer = $this->object($row, 'customer');
        $currency = $this->string($customer, 'currencyCode');

        /*
         * A daily budget is `amountMicros` with period DAILY; `totalAmountMicros`
         * is the custom-period total. A shared budget still sets the campaign's
         * spend, so it is reported here with the sharing recorded in metadata
         * rather than hidden.
         */
        $period = $this->string($budget, 'period');
        $amount = $this->int($budget, 'amountMicros');
        $total = $this->int($budget, 'totalAmountMicros');

        [$budgetMode, $budgetMinor] = match (true) {
            $period === 'DAILY' && $amount !== null => [
                BudgetMode::Daily,
                $currency === null ? null : Money::fromMicros($amount, $currency)->minorUnits,
            ],
            $total !== null && $total > 0 => [
                BudgetMode::Lifetime,
                $currency === null ? null : Money::fromMicros($total, $currency)->minorUnits,
            ],
            $amount !== null => [
                BudgetMode::Daily,
                $currency === null ? null : Money::fromMicros($amount, $currency)->minorUnits,
            ],
            default => [BudgetMode::Unknown, null],
        };

        $channel = $this->string($campaign, 'advertisingChannelType');
        $subType = $this->string($campaign, 'advertisingChannelSubType');

        return new CampaignRecord(
            // `id` is an int64, so protobuf JSON gives it as a string already.
            externalId: (string) ($campaign['id'] ?? ''),
            name: $this->string($campaign, 'name') ?? 'Untitled campaign',
            status: $this->status(
                $this->string($campaign, 'status'),
                $this->string($campaign, 'primaryStatus'),
            ),
            rawStatus: $this->string($campaign, 'status'),
            // Google's equivalent of Meta's effective_status.
            rawEffectiveStatus: $this->string($campaign, 'primaryStatus'),
            objective: $this->objective($channel, $subType),
            /*
             * No single provider field holds this, so the raw value records the
             * pair the objective was inferred from. An operator comparing our
             * screen with Google's needs to see what we read, not our guess.
             */
            rawObjective: $subType === null || $subType === 'UNSPECIFIED'
                ? $channel
                : $channel.'/'.$subType,
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            // Google has no campaign-level spend cap; account-level limits are a
            // separate resource and are not reported here as if they were one.
            spendCapMinor: null,
            currency: $currency,
            // Google has no "buying type"; the serving status is the nearest
            // operationally useful field, and it is recorded as itself.
            buyingType: $this->string($campaign, 'servingStatus'),
            bidStrategy: $this->string($campaign, 'biddingStrategyType'),
            // Dates, not timestamps: Google states them in the account timezone.
            startsAt: $this->date($this->string($campaign, 'startDate')),
            endsAt: $this->date($this->string($campaign, 'endDate')),
            // Google does not expose creation or modification times on a campaign.
            providerCreatedAt: null,
            providerUpdatedAt: null,
            metadata: array_filter([
                'resource_name' => $this->string($campaign, 'resourceName'),
                'advertising_channel_type' => $channel,
                'advertising_channel_sub_type' => $subType,
                'primary_status_reasons' => $campaign['primaryStatusReasons'] ?? null,
                'optimization_score' => $campaign['optimizationScore'] ?? null,
                'budget_resource_name' => $this->string($budget, 'resourceName'),
                'budget_explicitly_shared' => $budget['explicitlyShared'] ?? null,
                'budget_period' => $period,
                'experiment_type' => $this->string($campaign, 'experimentType'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $row */
    public function adGroup(array $row): AdGroupRecord
    {
        $adGroup = $this->object($row, 'adGroup');
        $customer = $this->object($row, 'customer');
        $currency = $this->string($customer, 'currencyCode');

        /*
         * Google has several bid fields and which one applies depends on the
         * bidding strategy. `effectiveCpcBidMicros` is what is actually in force,
         * which is the number an operator is asking about.
         */
        $bid = $this->int($adGroup, 'effectiveCpcBidMicros')
            ?? $this->int($adGroup, 'cpcBidMicros')
            ?? $this->int($adGroup, 'cpmBidMicros')
            ?? $this->int($adGroup, 'cpvBidMicros');

        return new AdGroupRecord(
            externalId: (string) ($adGroup['id'] ?? ''),
            campaignExternalId: $this->idFromResourceName($this->string($adGroup, 'campaign')),
            name: $this->string($adGroup, 'name') ?? 'Untitled ad group',
            status: $this->status($this->string($adGroup, 'status'), null),
            rawStatus: $this->string($adGroup, 'status'),
            rawEffectiveStatus: $this->string($adGroup, 'primaryStatus'),
            // The ad group's type is what it optimises towards here; Google has
            // no separate optimization_goal field at this level.
            optimizationGoal: $this->string($adGroup, 'type'),
            // Billing follows the campaign's bidding strategy, not the ad group.
            billingEvent: null,
            bidAmountMinor: $bid === null || $currency === null
                ? null
                : Money::fromMicros($bid, $currency)->minorUnits,
            // Budgets are a campaign-level resource in Google Ads.
            budgetMode: BudgetMode::Inherited,
            budgetMinor: null,
            currency: $currency,
            // Ad groups carry no schedule of their own.
            startsAt: null,
            endsAt: null,
            providerCreatedAt: null,
            providerUpdatedAt: null,
            /*
             * Targeting is a set of separate criterion resources, each its own
             * row. Returning `[]` says we have not read them; a fabricated
             * summary would misrepresent who the ads reach.
             */
            targeting: [],
            metadata: array_filter([
                'resource_name' => $this->string($adGroup, 'resourceName'),
                'type' => $this->string($adGroup, 'type'),
                'target_cpa_micros' => $adGroup['targetCpaMicros'] ?? null,
                'target_roas' => $adGroup['targetRoas'] ?? null,
                'primary_status_reasons' => $adGroup['primaryStatusReasons'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $row */
    public function ad(array $row): AdRecord
    {
        $adGroupAd = $this->object($row, 'adGroupAd');
        $ad = $this->object($adGroupAd, 'ad');
        $policy = $this->object($adGroupAd, 'policySummary');
        $approval = $this->string($policy, 'approvalStatus');

        return new AdRecord(
            externalId: (string) ($ad['id'] ?? ''),
            adGroupExternalId: $this->idFromResourceName($this->string($adGroupAd, 'adGroup')),
            /*
             * Google's ad_group_ad row has no campaign id of its own; it is
             * derivable from the ad group's resource name
             * (customers/X/adGroups/Y), which carries no campaign segment. So the
             * campaign must be selected explicitly in the query, and that is what
             * is read here.
             */
            campaignExternalId: $this->idFromResourceName(
                $this->string($this->object($row, 'campaign'), 'resourceName'),
            ) ?: (string) ($this->object($row, 'campaign')['id'] ?? ''),
            name: $this->string($ad, 'name')
                ?? $this->headline($ad)
                ?? 'Ad '.($ad['id'] ?? ''),
            status: $this->adStatus(
                $this->string($adGroupAd, 'status'),
                $approval,
                $this->string($adGroupAd, 'primaryStatus'),
            ),
            rawStatus: $this->string($adGroupAd, 'status'),
            rawEffectiveStatus: $approval ?? $this->string($adGroupAd, 'primaryStatus'),
            /*
             * Google has no separate creative entity: the creative *is* the ad.
             * The ad's own id is used so creative reporting has something to
             * group by, which keeps the model honest about the platform rather
             * than inventing a parent that does not exist.
             */
            creativeExternalId: isset($ad['id']) ? (string) $ad['id'] : null,
            reviewFeedback: $this->policyFeedback($policy),
            providerCreatedAt: null,
            providerUpdatedAt: null,
            metadata: array_filter([
                'resource_name' => $this->string($adGroupAd, 'resourceName'),
                'ad_type' => $this->string($ad, 'type'),
                'ad_strength' => $this->string($adGroupAd, 'adStrength'),
                'policy_review_status' => $this->string($policy, 'reviewStatus'),
                'primary_status_reasons' => $adGroupAd['primaryStatusReasons'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * The ad, read as a creative.
     *
     * Google models no creative separately from the ad, so this reads the same
     * row from the other direction rather than pretending to fetch a second
     * resource.
     *
     * @param  array<string, mixed>  $row
     */
    public function creative(array $row): CreativeRecord
    {
        $adGroupAd = $this->object($row, 'adGroupAd');
        $ad = $this->object($adGroupAd, 'ad');
        $type = $this->string($ad, 'type');

        $responsiveSearch = $this->object($ad, 'responsiveSearchAd');
        $responsiveDisplay = $this->object($ad, 'responsiveDisplayAd');
        $image = $this->object($ad, 'imageAd');
        $video = $this->object($ad, 'videoAd');

        $format = match (true) {
            $responsiveSearch !== [] => CreativeFormat::ResponsiveSearch,
            $responsiveDisplay !== [] => CreativeFormat::ResponsiveDisplay,
            $image !== [] => CreativeFormat::Image,
            $video !== [] => CreativeFormat::Video,
            $type === 'TEXT_AD', $type === 'EXPANDED_TEXT_AD' => CreativeFormat::Text,
            default => CreativeFormat::Unknown,
        };

        $assets = [];
        $position = 0;

        // Responsive ads carry many headlines and descriptions; Google decides
        // which are shown. Keeping all of them is the only accurate record.
        foreach ($this->textAssets($responsiveSearch, 'headlines') as $text) {
            $assets[] = new CreativeAssetRecord(kind: 'headline', position: $position++, metadata: ['text' => $text]);
        }

        foreach ($this->textAssets($responsiveSearch, 'descriptions') as $text) {
            $assets[] = new CreativeAssetRecord(kind: 'description', position: $position++, metadata: ['text' => $text]);
        }

        if ($image !== []) {
            $assets[] = new CreativeAssetRecord(
                kind: 'image',
                url: $this->string($image, 'imageUrl'),
                position: $position++,
                metadata: array_filter([
                    'preview_image_url' => $this->string($image, 'previewImageUrl'),
                    'mime_type' => $this->string($image, 'mimeType'),
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        if ($video !== []) {
            $assets[] = new CreativeAssetRecord(kind: 'video', position: $position);
        }

        $finalUrls = $ad['finalUrls'] ?? null;

        return new CreativeRecord(
            externalId: (string) ($ad['id'] ?? ''),
            name: $this->string($ad, 'name') ?? $this->headline($ad) ?? 'Ad '.($ad['id'] ?? ''),
            format: $format,
            title: $this->headline($ad),
            body: $this->firstTextAsset($responsiveSearch, 'descriptions')
                ?? $this->string($this->object($ad, 'textAd'), 'description1'),
            // Google generates the call to action for responsive formats; there
            // is no field to read, and guessing one would be a fabrication.
            callToAction: null,
            destinationUrl: is_array($finalUrls) && isset($finalUrls[0]) ? (string) $finalUrls[0] : null,
            thumbnailUrl: $this->string($image, 'previewImageUrl'),
            previewUrl: null,
            assets: $assets,
            metadata: array_filter([
                'ad_type' => $type,
                'display_url' => $this->string($ad, 'displayUrl'),
                'ad_strength' => $this->string($adGroupAd, 'adStrength'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $row */
    public function insight(array $row, AdEntityType $level, string $currency): InsightRecord
    {
        $metrics = $this->object($row, 'metrics');
        $segments = $this->object($row, 'segments');
        $customer = $this->object($row, 'customer');

        $externalId = match ($level) {
            AdEntityType::Campaign => (string) ($this->object($row, 'campaign')['id'] ?? ''),
            AdEntityType::AdGroup => (string) ($this->object($row, 'adGroup')['id'] ?? ''),
            AdEntityType::Ad => (string) ($this->object($this->object($row, 'adGroupAd'), 'ad')['id'] ?? ''),
            AdEntityType::Account => (string) ($customer['id'] ?? ''),
        };

        $accountCurrency = $this->string($customer, 'currencyCode') ?? $currency;
        $costMicros = $this->int($metrics, 'costMicros');
        $conversionsValue = $this->float($metrics, 'conversionsValue');
        $impressions = $this->int($metrics, 'impressions');

        /*
         * Google reports quartile completion as a *rate*, not a count. The rate
         * is defined against impressions, so the product is exact rather than an
         * estimate — but it is a derivation, and the rate itself is kept in
         * metadata so the original figure is never lost.
         */
        $completionRate = $this->float($metrics, 'videoQuartileP100Rate');
        $completions = $completionRate === null || $impressions === null
            ? null
            : (int) round($impressions * $completionRate / 100);

        return new InsightRecord(
            entityType: $level,
            externalEntityId: $externalId,
            date: new DateTimeImmutable($this->string($segments, 'date') ?? 'today'),
            currency: $accountCurrency,
            spendMinor: $costMicros === null ? null : Money::fromMicros($costMicros, $accountCurrency)->minorUnits,
            revenueMinor: $conversionsValue === null
                ? null
                : Money::fromDecimalString((string) $conversionsValue, $accountCurrency)->minorUnits,
            impressions: $impressions,
            /*
             * Google publishes no general deduplicated reach. `uniqueUsers` is
             * available only for some campaign types, so it is read when present
             * and left null otherwise — never approximated from impressions.
             */
            reach: $this->int($metrics, 'uniqueUsers'),
            clicks: $this->int($metrics, 'clicks'),
            /*
             * There is no link-click metric distinct from `clicks`. Copying
             * clicks here would make Google's CTR and link CTR identical for a
             * reason that is ours, not the platform's.
             */
            linkClicks: null,
            // v22 names this `videoTrueviewViews`; there is no plain `videoViews`.
            videoViews: $this->int($metrics, 'videoTrueviewViews'),
            videoCompletions: $completions,
            engagements: $this->int($metrics, 'engagements'),
            conversions: $this->float($metrics, 'conversions'),
            conversionBreakdown: $this->conversions($row, $accountCurrency),
            accountTimezone: $this->string($customer, 'timeZone'),
            metadata: array_filter([
                'interactions' => $metrics['interactions'] ?? null,
                'video_quartile_p100_rate' => $completionRate,
                'video_quartile_p25_rate' => $metrics['videoQuartileP25Rate'] ?? null,
                'all_conversions' => $metrics['allConversions'] ?? null,
                'all_conversions_value' => $metrics['allConversionsValue'] ?? null,
                'view_through_conversions' => $metrics['viewThroughConversions'] ?? null,
                'search_impression_share' => $metrics['searchImpressionShare'] ?? null,
                'ad_network_type' => $this->string($segments, 'adNetworkType'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * ENABLED/PAUSED/REMOVED, refined by primaryStatus where it is selected.
     *
     * `status` alone cannot say whether a campaign delivers: an ENABLED campaign
     * can be LIMITED by budget or MISCONFIGURED by a broken conversion action,
     * and both look identical without it.
     */
    public function status(?string $status, ?string $primaryStatus): EntityStatus
    {
        $base = match (strtoupper((string) $status)) {
            'ENABLED' => EntityStatus::Active,
            'PAUSED' => EntityStatus::Paused,
            'REMOVED' => EntityStatus::Removed,
            default => EntityStatus::Unknown,
        };

        if ($base !== EntityStatus::Active || $primaryStatus === null) {
            return $base;
        }

        return match (strtoupper($primaryStatus)) {
            'ELIGIBLE', 'LEARNING' => EntityStatus::Active,
            'LIMITED', 'MISCONFIGURED', 'NOT_ELIGIBLE', 'PENDING' => EntityStatus::NotDelivering,
            'ENDED' => EntityStatus::Completed,
            'PAUSED' => EntityStatus::Paused,
            'REMOVED' => EntityStatus::Removed,
            default => EntityStatus::Active,
        };
    }

    /** An ad's status also depends on policy review, which can block an enabled ad. */
    public function adStatus(?string $status, ?string $approvalStatus, ?string $primaryStatus): EntityStatus
    {
        $approval = strtoupper((string) $approvalStatus);

        if ($approval === 'DISAPPROVED') {
            return EntityStatus::Rejected;
        }

        if ($approval === 'AREA_OF_INTEREST_ONLY') {
            // Serving, but only where the policy allows — not full delivery.
            return EntityStatus::NotDelivering;
        }

        return $this->status($status, $primaryStatus);
    }

    /**
     * Channel and sub-type onto our objectives.
     *
     * Approximate by necessity: Google has no objective field, and a Search
     * campaign can be bid for clicks or for conversions. The pair the inference
     * came from is always stored alongside, so the approximation is auditable
     * rather than presented as the platform's own word.
     */
    public function objective(?string $channelType, ?string $subType): CampaignObjective
    {
        $sub = strtoupper((string) $subType);

        $fromSubType = match ($sub) {
            'APP_CAMPAIGN', 'APP_CAMPAIGN_FOR_ENGAGEMENT', 'APP_CAMPAIGN_FOR_PRE_REGISTRATION' => CampaignObjective::AppPromotion,
            'VIDEO_REACH_TARGET_FREQUENCY', 'VIDEO_NON_SKIPPABLE' => CampaignObjective::Reach,
            'VIDEO_ACTION' => CampaignObjective::Sales,
            'LOCAL_CAMPAIGN' => CampaignObjective::StoreTraffic,
            default => null,
        };

        if ($fromSubType instanceof CampaignObjective) {
            return $fromSubType;
        }

        return match (strtoupper((string) $channelType)) {
            'SEARCH' => CampaignObjective::Traffic,
            'DISPLAY', 'DEMAND_GEN' => CampaignObjective::Awareness,
            'SHOPPING', 'PERFORMANCE_MAX', 'TRAVEL', 'HOTEL' => CampaignObjective::Sales,
            'VIDEO' => CampaignObjective::VideoViews,
            'LOCAL', 'LOCAL_SERVICES' => CampaignObjective::StoreTraffic,
            'MULTI_CHANNEL' => CampaignObjective::AppPromotion,
            default => CampaignObjective::Other,
        };
    }

    /** Google's conversion-action categories onto our vocabulary. */
    public function conversionType(string $category): ConversionActionType
    {
        return match (strtoupper($category)) {
            'PURCHASE', 'STORE_SALE' => ConversionActionType::Purchase,
            'ADD_TO_CART' => ConversionActionType::AddToCart,
            'BEGIN_CHECKOUT' => ConversionActionType::InitiateCheckout,
            'SIGNUP' => ConversionActionType::CompleteRegistration,
            'SUBSCRIBE_PAID' => ConversionActionType::Subscribe,
            'SUBMIT_LEAD_FORM', 'PHONE_CALL_LEAD', 'IMPORTED_LEAD',
            'QUALIFIED_LEAD', 'CONVERTED_LEAD', 'REQUEST_QUOTE',
            'BOOK_APPOINTMENT' => ConversionActionType::Lead,
            'CONTACT' => ConversionActionType::Contact,
            'DOWNLOAD' => ConversionActionType::AppInstall,
            'PAGE_VIEW' => ConversionActionType::LandingPageView,
            'STORE_VISIT' => ConversionActionType::StoreVisit,
            'OUTBOUND_CLICK', 'ENGAGEMENT', 'GET_DIRECTIONS' => ConversionActionType::Other,
            'DEFAULT' => ConversionActionType::Custom,
            default => ConversionActionType::Other,
        };
    }

    /**
     * The conversion breakdown, when the query segmented by conversion action.
     *
     * Without that segment a row carries only totals, and `[]` says exactly
     * that: one conversion row per action would have to be invented otherwise.
     *
     * @param  array<string, mixed>  $row
     * @return list<ConversionRecord>
     */
    private function conversions(array $row, string $currency): array
    {
        $segments = $this->object($row, 'segments');
        $metrics = $this->object($row, 'metrics');
        $name = $this->string($segments, 'conversionActionName');
        $category = $this->string($segments, 'conversionActionCategory');

        if ($name === null && $category === null) {
            return [];
        }

        $count = $this->float($metrics, 'conversions');

        if ($count === null) {
            return [];
        }

        $value = $this->float($metrics, 'conversionsValue');

        return [
            new ConversionRecord(
                // The category is the stable identifier; the name is the
                // client's own label and they will rename it.
                rawActionType: $category ?? 'DEFAULT',
                count: $count,
                actionType: $this->conversionType($category ?? 'DEFAULT'),
                actionName: $name,
                valueMinor: $value === null
                    ? null
                    : Money::fromDecimalString((string) $value, $currency)->minorUnits,
                currency: $value === null ? null : $currency,
                metadata: array_filter([
                    'conversion_action' => $this->string($segments, 'conversionAction'),
                ], static fn (mixed $value): bool => $value !== null),
            ),
        ];
    }

    /** @param array<string, mixed> $ad */
    private function headline(array $ad): ?string
    {
        return $this->firstTextAsset($this->object($ad, 'responsiveSearchAd'), 'headlines')
            ?? $this->string($this->object($ad, 'textAd'), 'headline');
    }

    /**
     * @param  array<string, mixed>  $container
     * @return list<string>
     */
    private function textAssets(array $container, string $key): array
    {
        $assets = $container[$key] ?? null;
        $texts = [];

        foreach (is_array($assets) ? $assets : [] as $asset) {
            if (is_array($asset) && isset($asset['text']) && is_string($asset['text'])) {
                $texts[] = $asset['text'];
            }
        }

        return $texts;
    }

    /** @param array<string, mixed> $container */
    private function firstTextAsset(array $container, string $key): ?string
    {
        return $this->textAssets($container, $key)[0] ?? null;
    }

    /**
     * Why a policy blocked an ad, from its topic entries.
     *
     * @param  array<string, mixed>  $policy
     */
    private function policyFeedback(array $policy): ?string
    {
        $topics = $policy['policyTopicEntries'] ?? null;
        $reasons = [];

        foreach (is_array($topics) ? $topics : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $topic = $entry['topic'] ?? null;
            $type = $entry['type'] ?? null;

            if (is_string($topic)) {
                $reasons[] = is_string($type) ? sprintf('%s (%s)', $topic, $type) : $topic;
            }
        }

        return $reasons === [] ? null : implode('; ', $reasons);
    }

    /**
     * The trailing id of a resource name such as `customers/123/campaigns/456`.
     *
     * Google identifies everything by resource name, and the numeric id is the
     * last segment. Reading it is safer than requiring the id to be selected
     * separately in every query.
     */
    private function idFromResourceName(?string $resourceName): string
    {
        if ($resourceName === null) {
            return '';
        }

        $segments = explode('/', $resourceName);
        $last = end($segments);

        // A composite key such as adGroupAds/{adGroupId}~{adId}.
        if (str_contains($last, '~')) {
            $parts = explode('~', $last);

            return $parts[0];
        }

        return $last;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function object(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * An int64, which protobuf JSON serialises as a string.
     *
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $row */
    private function float(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value);
    }
}
