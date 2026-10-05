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
 * Meta Marketing API → digitalSmart.
 *
 * Field names follow the Graph API Campaign, AdSet, Ad, AdCreative and Insights
 * nodes. Points worth knowing, because they are where naive mappings go wrong:
 *
 *   - Budgets arrive as numeric *strings* in the account's minor units
 *     ("5000" = 50.00), not decimals.
 *   - `status` is what the advertiser set. `effective_status` is what is
 *     actually happening, folding in parent state, schedule and ad review —
 *     which is why a campaign can be ACTIVE and still not deliver.
 *   - The ODAX migration renamed objectives to OUTCOME_*; older campaigns keep
 *     the legacy names, so both must map.
 *   - Insights return `actions`, `action_values` and `purchase_roas` as arrays
 *     of `{action_type, value}` rather than scalar columns.
 *   - `spend` is a decimal string in the account currency, unlike budgets.
 */
final class MetaMapper
{
    /** @param array<string, mixed> $raw */
    public function campaign(array $raw): CampaignRecord
    {
        $currency = $this->string($raw, 'account_currency');
        $daily = $this->string($raw, 'daily_budget');
        $lifetime = $this->string($raw, 'lifetime_budget');

        [$budgetMode, $budgetMinor] = match (true) {
            $daily !== null && $daily !== '0' => [BudgetMode::Daily, (int) $daily],
            $lifetime !== null && $lifetime !== '0' => [BudgetMode::Lifetime, (int) $lifetime],
            // No budget on the campaign means it is set on the ad sets below it.
            default => [BudgetMode::Inherited, null],
        };

        return new CampaignRecord(
            externalId: (string) ($raw['id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled campaign',
            status: $this->status($this->string($raw, 'effective_status') ?? $this->string($raw, 'status')),
            rawStatus: $this->string($raw, 'status'),
            rawEffectiveStatus: $this->string($raw, 'effective_status'),
            objective: $this->objective($this->string($raw, 'objective')),
            rawObjective: $this->string($raw, 'objective'),
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            spendCapMinor: $this->intOrNull($this->string($raw, 'spend_cap')),
            currency: $currency,
            buyingType: $this->string($raw, 'buying_type'),
            bidStrategy: $this->string($raw, 'bid_strategy'),
            startsAt: $this->date($this->string($raw, 'start_time')),
            endsAt: $this->date($this->string($raw, 'stop_time')),
            providerCreatedAt: $this->date($this->string($raw, 'created_time')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_time')),
            metadata: array_filter([
                'special_ad_categories' => $raw['special_ad_categories'] ?? null,
                'account_id' => $this->string($raw, 'account_id'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw */
    public function adGroup(array $raw): AdGroupRecord
    {
        $daily = $this->string($raw, 'daily_budget');
        $lifetime = $this->string($raw, 'lifetime_budget');

        [$budgetMode, $budgetMinor] = match (true) {
            $daily !== null && $daily !== '0' => [BudgetMode::Daily, (int) $daily],
            $lifetime !== null && $lifetime !== '0' => [BudgetMode::Lifetime, (int) $lifetime],
            default => [BudgetMode::Inherited, null],
        };

        return new AdGroupRecord(
            externalId: (string) ($raw['id'] ?? ''),
            campaignExternalId: (string) ($raw['campaign_id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled ad set',
            status: $this->status($this->string($raw, 'effective_status') ?? $this->string($raw, 'status')),
            rawStatus: $this->string($raw, 'status'),
            rawEffectiveStatus: $this->string($raw, 'effective_status'),
            optimizationGoal: $this->string($raw, 'optimization_goal'),
            billingEvent: $this->string($raw, 'billing_event'),
            bidAmountMinor: $this->intOrNull($this->string($raw, 'bid_amount')),
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            startsAt: $this->date($this->string($raw, 'start_time')),
            endsAt: $this->date($this->string($raw, 'end_time')),
            providerCreatedAt: $this->date($this->string($raw, 'created_time')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_time')),
            // Targeting is large, deeply nested and never filtered in SQL.
            targeting: is_array($raw['targeting'] ?? null) ? $raw['targeting'] : [],
            metadata: array_filter([
                'promoted_object' => $raw['promoted_object'] ?? null,
                'destination_type' => $this->string($raw, 'destination_type'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw */
    public function ad(array $raw): AdRecord
    {
        $creative = is_array($raw['creative'] ?? null) ? $raw['creative'] : [];

        return new AdRecord(
            externalId: (string) ($raw['id'] ?? ''),
            adGroupExternalId: (string) ($raw['adset_id'] ?? ''),
            campaignExternalId: (string) ($raw['campaign_id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled ad',
            status: $this->status($this->string($raw, 'effective_status') ?? $this->string($raw, 'status')),
            rawStatus: $this->string($raw, 'status'),
            rawEffectiveStatus: $this->string($raw, 'effective_status'),
            creativeExternalId: isset($creative['id']) ? (string) $creative['id'] : null,
            reviewFeedback: $this->reviewFeedback($raw),
            providerCreatedAt: $this->date($this->string($raw, 'created_time')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_time')),
            metadata: array_filter([
                'tracking_specs' => $raw['tracking_specs'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw */
    public function creative(array $raw): CreativeRecord
    {
        $spec = is_array($raw['object_story_spec'] ?? null) ? $raw['object_story_spec'] : [];
        $linkData = is_array($spec['link_data'] ?? null) ? $spec['link_data'] : [];
        $videoData = is_array($spec['video_data'] ?? null) ? $spec['video_data'] : [];
        $childAttachments = is_array($linkData['child_attachments'] ?? null) ? $linkData['child_attachments'] : [];

        $format = match (true) {
            $childAttachments !== [] => CreativeFormat::Carousel,
            $videoData !== [] => CreativeFormat::Video,
            $linkData !== [] => CreativeFormat::Image,
            default => CreativeFormat::Unknown,
        };

        $assets = [];
        $position = 0;

        foreach ($childAttachments as $card) {
            if (is_array($card)) {
                $assets[] = new CreativeAssetRecord(
                    kind: 'image',
                    url: $this->string($card, 'picture'),
                    externalId: $this->string($card, 'image_hash'),
                    position: $position++,
                    metadata: array_filter([
                        'name' => $this->string($card, 'name'),
                        'link' => $this->string($card, 'link'),
                    ], static fn (mixed $value): bool => $value !== null),
                );
            }
        }

        if ($assets === [] && $this->string($raw, 'image_url') !== null) {
            $assets[] = new CreativeAssetRecord(kind: 'image', url: $this->string($raw, 'image_url'));
        }

        if ($videoData !== []) {
            $assets[] = new CreativeAssetRecord(
                kind: 'video',
                externalId: $this->string($videoData, 'video_id'),
                position: $position,
            );
        }

        $callToAction = $linkData['call_to_action']['type']
            ?? $videoData['call_to_action']['type']
            ?? null;

        return new CreativeRecord(
            externalId: (string) ($raw['id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled creative',
            format: $format,
            title: $this->string($linkData, 'name') ?? $this->string($raw, 'title'),
            body: $this->string($linkData, 'message')
                ?? $this->string($videoData, 'message')
                ?? $this->string($raw, 'body'),
            callToAction: is_string($callToAction) ? $callToAction : null,
            destinationUrl: $this->string($linkData, 'link'),
            thumbnailUrl: $this->string($raw, 'thumbnail_url'),
            previewUrl: $this->string($raw, 'preview_url'),
            assets: $assets,
            metadata: array_filter([
                'status' => $this->string($raw, 'status'),
                'object_type' => $this->string($raw, 'object_type'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw */
    public function insight(array $raw, AdEntityType $level, string $currency): InsightRecord
    {
        $externalId = match ($level) {
            AdEntityType::Campaign => (string) ($raw['campaign_id'] ?? ''),
            AdEntityType::AdGroup => (string) ($raw['adset_id'] ?? ''),
            AdEntityType::Ad => (string) ($raw['ad_id'] ?? ''),
            AdEntityType::Account => (string) ($raw['account_id'] ?? ''),
        };

        $actions = $this->actionMap($raw['actions'] ?? null);
        $actionValues = $this->actionMap($raw['action_values'] ?? null);

        // `spend` is a decimal string in account currency; budgets are not.
        $spend = $this->string($raw, 'spend');

        return new InsightRecord(
            entityType: $level,
            externalEntityId: $externalId,
            date: new DateTimeImmutable($this->string($raw, 'date_start') ?? 'today'),
            currency: $this->string($raw, 'account_currency') ?? $currency,
            spendMinor: $spend === null ? null : Money::fromDecimalString($spend, $currency)->minorUnits,
            revenueMinor: $this->purchaseValueMinor($actionValues, $currency),
            impressions: $this->intOrNull($this->string($raw, 'impressions')),
            reach: $this->intOrNull($this->string($raw, 'reach')),
            clicks: $this->intOrNull($this->string($raw, 'clicks')),
            linkClicks: $this->intOrNull($this->string($raw, 'inline_link_clicks')),
            videoViews: $this->actionCount($actions, 'video_view'),
            videoCompletions: $this->firstActionCount($raw['video_p100_watched_actions'] ?? null),
            engagements: $this->actionCount($actions, 'post_engagement'),
            conversions: $this->totalConversions($actions),
            conversionBreakdown: $this->conversions($actions, $actionValues, $currency),
            metadata: array_filter([
                'objective' => $this->string($raw, 'objective'),
                'purchase_roas' => $raw['purchase_roas'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Meta's ACTIVE/PAUSED/DELETED/ARCHIVED, plus the effective_status values
     * that explain why something is not delivering.
     */
    public function status(?string $raw): EntityStatus
    {
        return match (strtoupper((string) $raw)) {
            'ACTIVE' => EntityStatus::Active,
            'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED' => EntityStatus::Paused,
            'DELETED' => EntityStatus::Removed,
            'ARCHIVED' => EntityStatus::Archived,
            'PENDING_REVIEW', 'IN_PROCESS', 'PREAPPROVED' => EntityStatus::InReview,
            'DISAPPROVED' => EntityStatus::Rejected,
            'WITH_ISSUES', 'PENDING_BILLING_INFO' => EntityStatus::NotDelivering,
            default => EntityStatus::Unknown,
        };
    }

    /** Both the ODAX OUTCOME_* names and the legacy ones still on older campaigns. */
    public function objective(?string $raw): CampaignObjective
    {
        return match (strtoupper((string) $raw)) {
            'OUTCOME_AWARENESS', 'BRAND_AWARENESS', 'LOCAL_AWARENESS' => CampaignObjective::Awareness,
            'REACH' => CampaignObjective::Reach,
            'OUTCOME_TRAFFIC', 'LINK_CLICKS' => CampaignObjective::Traffic,
            'OUTCOME_ENGAGEMENT', 'POST_ENGAGEMENT', 'PAGE_LIKES', 'EVENT_RESPONSES' => CampaignObjective::Engagement,
            'VIDEO_VIEWS' => CampaignObjective::VideoViews,
            'OUTCOME_LEADS', 'LEAD_GENERATION' => CampaignObjective::Leads,
            'OUTCOME_APP_PROMOTION', 'APP_INSTALLS' => CampaignObjective::AppPromotion,
            'OUTCOME_SALES', 'CONVERSIONS', 'PRODUCT_CATALOG_SALES', 'OFFER_CLAIMS' => CampaignObjective::Sales,
            'STORE_VISITS' => CampaignObjective::StoreTraffic,
            'MESSAGES' => CampaignObjective::Messages,
            default => CampaignObjective::Other,
        };
    }

    /** Meta's action_type strings onto our vocabulary. */
    public function conversionType(string $rawActionType): ConversionActionType
    {
        return match (true) {
            str_contains($rawActionType, 'purchase') => ConversionActionType::Purchase,
            str_contains($rawActionType, 'lead') => ConversionActionType::Lead,
            str_contains($rawActionType, 'add_to_cart') => ConversionActionType::AddToCart,
            str_contains($rawActionType, 'initiate_checkout') => ConversionActionType::InitiateCheckout,
            str_contains($rawActionType, 'complete_registration') => ConversionActionType::CompleteRegistration,
            str_contains($rawActionType, 'contact') => ConversionActionType::Contact,
            str_contains($rawActionType, 'subscribe') => ConversionActionType::Subscribe,
            str_contains($rawActionType, 'mobile_app_install'), str_contains($rawActionType, 'app_install') => ConversionActionType::AppInstall,
            str_contains($rawActionType, 'landing_page_view') => ConversionActionType::LandingPageView,
            str_contains($rawActionType, 'view_content') => ConversionActionType::ViewContent,
            str_contains($rawActionType, 'search') => ConversionActionType::Search,
            str_starts_with($rawActionType, 'offsite_conversion.custom') => ConversionActionType::Custom,
            default => ConversionActionType::Other,
        };
    }

    /**
     * Meta returns `[{action_type, value}]`; flatten to a lookup.
     *
     * @return array<string, float>
     */
    private function actionMap(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        $map = [];

        foreach ($actions as $action) {
            if (is_array($action) && isset($action['action_type'])) {
                $map[(string) $action['action_type']] = (float) ($action['value'] ?? 0);
            }
        }

        return $map;
    }

    /** @param array<string, float> $actions */
    private function actionCount(array $actions, string $type): ?int
    {
        return isset($actions[$type]) ? (int) round($actions[$type]) : null;
    }

    private function firstActionCount(mixed $actions): ?int
    {
        if (! is_array($actions) || $actions === []) {
            return null;
        }

        $first = reset($actions);

        return is_array($first) && isset($first['value']) ? (int) round((float) $first['value']) : null;
    }

    /**
     * Purchases only.
     *
     * Summing every action type would double count: Meta reports nested
     * categories (`omni_purchase` contains `purchase`) alongside leaf actions.
     *
     * @param  array<string, float>  $actions
     */
    private function totalConversions(array $actions): ?float
    {
        foreach (['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'] as $key) {
            if (isset($actions[$key])) {
                return $actions[$key];
            }
        }

        return $actions['lead'] ?? null;
    }

    /** @param array<string, float> $actionValues */
    private function purchaseValueMinor(array $actionValues, string $currency): ?int
    {
        foreach (['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'] as $key) {
            if (isset($actionValues[$key])) {
                return Money::fromDecimalString((string) $actionValues[$key], $currency)->minorUnits;
            }
        }

        return null;
    }

    /**
     * @param  array<string, float>  $actions
     * @param  array<string, float>  $actionValues
     * @return list<ConversionRecord>
     */
    private function conversions(array $actions, array $actionValues, string $currency): array
    {
        $records = [];

        foreach ($actions as $rawType => $count) {
            $value = $actionValues[$rawType] ?? null;

            $records[] = new ConversionRecord(
                rawActionType: $rawType,
                count: $count,
                actionType: $this->conversionType($rawType),
                valueMinor: $value === null
                    ? null
                    : Money::fromDecimalString((string) $value, $currency)->minorUnits,
                currency: $value === null ? null : $currency,
            );
        }

        return $records;
    }

    /** @param array<string, mixed> $raw */
    private function reviewFeedback(array $raw): ?string
    {
        $feedback = $raw['ad_review_feedback'] ?? null;

        if (! is_array($feedback)) {
            return null;
        }

        $global = $feedback['global'] ?? null;

        return is_array($global) && $global !== [] ? implode('; ', array_map('strval', $global)) : null;
    }

    /** @param array<string, mixed> $raw */
    private function string(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private function intOrNull(?string $value): ?int
    {
        return $value === null || ! is_numeric($value) ? null : (int) $value;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
