<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Mapper;

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\CreativeAssetRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Advertising\ValueObject\Money;
use DateTimeImmutable;

/**
 * TikTok Marketing API → digitalSmart.
 *
 * Reads the `data.list[]` entries of `/campaign/get/`, `/adgroup/get/`,
 * `/ad/get/` and `/report/integrated/get/`. Field names and enum values were
 * read from TikTok's own reference rather than from memory.
 *
 * TikTok's conventions are a third set again, after Meta's and Google's:
 *
 *   - `budget` is a **float in the account's currency** — not minor-unit strings
 *     like Meta's, not micros like Google's.
 *   - Delivery state is split in two: `operation_status` is only ENABLE/DISABLE
 *     (the switch the advertiser set), and `secondary_status` is what is actually
 *     happening (`CAMPAIGN_STATUS_BUDGET_EXCEED`,
 *     `CAMPAIGN_STATUS_REVIEW_DISAPPROVED`, …).
 *   - Reporting is a separate endpoint whose rows are `{dimensions, metrics}`
 *     rather than flat, and **every metric is a string**.
 *   - An unavailable metric comes back as the literal `"-"`, which must become
 *     null. Casting it would silently produce 0 and read as a real result.
 *   - Pagination is a page *number*, not a cursor.
 *
 * Deliberately limited: only the metric names verified against TikTok's
 * reference are normalised. Everything else in a row is carried into metadata
 * untouched rather than guessed at, because a mis-named metric would show a
 * plausible number in the wrong column. docs/INTEGRATIONS.md lists what still
 * needs verifying.
 */
final class TikTokMapper
{
    /**
     * Metrics that are definitely TikTok's, with their meaning.
     *
     * Anything not listed here stays in metadata. The list grows only when a
     * name has been confirmed against the provider, never because a name looks
     * right.
     */
    private const VERIFIED_METRICS = [
        'spend', 'billed_cost', 'cash_spend', 'voucher_spend',
        'impressions', 'reach', 'conversions',
        'campaign_id', 'campaign_name', 'adgroup_id', 'adgroup_name',
        'ad_id', 'ad_name', 'currency', 'timezone', 'advertiser_id',
    ];

    /** @param array<string, mixed> $raw a `data.list[]` entry from /campaign/get/ */
    public function campaign(array $raw, string $currency): CampaignRecord
    {
        $budgetMode = $this->string($raw, 'budget_mode');
        $budget = $this->float($raw, 'budget');

        return new CampaignRecord(
            externalId: (string) ($raw['campaign_id'] ?? ''),
            name: $this->string($raw, 'campaign_name') ?? 'Untitled campaign',
            status: $this->status(
                $this->string($raw, 'operation_status'),
                $this->string($raw, 'secondary_status'),
            ),
            rawStatus: $this->string($raw, 'operation_status'),
            // TikTok's equivalent of Meta's effective_status.
            rawEffectiveStatus: $this->string($raw, 'secondary_status'),
            objective: $this->objective($this->string($raw, 'objective_type')),
            rawObjective: $this->string($raw, 'objective_type'),
            budgetMode: $this->budgetMode($budgetMode),
            // A decimal in the account currency, so it converts the same way
            // Meta's `spend` does rather than the way its budgets do.
            budgetMinor: $budget === null
                ? null
                : Money::fromDecimalString((string) $budget, $currency)->minorUnits,
            /*
             * TikTok has no campaign spend cap. BUDGET_MODE_INFINITE means the
             * budget lives on the ad groups, which is a different fact and is
             * recorded as the budget mode, not as a null cap with a number.
             */
            spendCapMinor: null,
            currency: $currency,
            buyingType: $this->string($raw, 'buying_type') ?? $this->string($raw, 'campaign_type'),
            bidStrategy: $this->string($raw, 'bid_type'),
            /*
             * TikTok schedules at ad group level, not campaign level. Leaving
             * these null is accurate; copying the first ad group's dates up
             * would invent a campaign schedule that does not exist.
             */
            startsAt: null,
            endsAt: null,
            providerCreatedAt: $this->date($this->string($raw, 'create_time')),
            providerUpdatedAt: $this->date($this->string($raw, 'modify_time')),
            metadata: array_filter([
                'advertiser_id' => $this->string($raw, 'advertiser_id'),
                'raw_budget_mode' => $budgetMode,
                'optimization_goal' => $this->string($raw, 'optimization_goal'),
                'deep_bid_type' => $this->string($raw, 'deep_bid_type'),
                'roas_bid' => $raw['roas_bid'] ?? null,
                // Campaign Budget Optimization: the campaign, not its ad groups,
                // decides where the money goes, which changes how pacing reads.
                'budget_optimize_on' => $raw['budget_optimize_on'] ?? null,
                'special_industries' => $raw['special_industries'] ?? null,
                'app_promotion_type' => $this->string($raw, 'app_promotion_type'),
                'sales_destination' => $this->string($raw, 'sales_destination'),
                'campaign_automation_type' => $this->string($raw, 'campaign_automation_type'),
                'is_search_campaign' => $raw['is_search_campaign'] ?? null,
                'rf_campaign_type' => $this->string($raw, 'rf_campaign_type'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw */
    public function adGroup(array $raw, string $currency): AdGroupRecord
    {
        $budgetMode = $this->string($raw, 'budget_mode');
        $budget = $this->float($raw, 'budget');
        /*
         * TikTok returns `bid_price: 0` when bidding is automatic and the real
         * target lives in `conversion_bid_price`. Reading the zero would report
         * "the bid is zero", which is a claim about the campaign rather than an
         * absence — so a zero here is treated as unset.
         */
        $bid = $this->positiveFloat($raw, 'bid_price')
            ?? $this->positiveFloat($raw, 'conversion_bid_price');

        return new AdGroupRecord(
            externalId: (string) ($raw['adgroup_id'] ?? ''),
            campaignExternalId: (string) ($raw['campaign_id'] ?? ''),
            name: $this->string($raw, 'adgroup_name') ?? 'Untitled ad group',
            status: $this->status(
                $this->string($raw, 'operation_status'),
                $this->string($raw, 'secondary_status'),
            ),
            rawStatus: $this->string($raw, 'operation_status'),
            rawEffectiveStatus: $this->string($raw, 'secondary_status'),
            optimizationGoal: $this->string($raw, 'optimization_goal'),
            billingEvent: $this->string($raw, 'billing_event'),
            bidAmountMinor: $bid === null
                ? null
                : Money::fromDecimalString((string) $bid, $currency)->minorUnits,
            budgetMode: $this->budgetMode($budgetMode),
            budgetMinor: $budget === null
                ? null
                : Money::fromDecimalString((string) $budget, $currency)->minorUnits,
            currency: $currency,
            startsAt: $this->date($this->string($raw, 'schedule_start_time')),
            endsAt: $this->date($this->string($raw, 'schedule_end_time')),
            providerCreatedAt: $this->date($this->string($raw, 'create_time')),
            providerUpdatedAt: $this->date($this->string($raw, 'modify_time')),
            // TikTok returns targeting as sibling fields rather than one object,
            // so the ones present are collected without being reshaped.
            targeting: array_filter([
                'location_ids' => $raw['location_ids'] ?? null,
                'age_groups' => $raw['age_groups'] ?? null,
                'gender' => $this->string($raw, 'gender'),
                'languages' => $raw['languages'] ?? null,
                'interest_category_ids' => $raw['interest_category_ids'] ?? null,
                'audience_ids' => $raw['audience_ids'] ?? null,
                'excluded_audience_ids' => $raw['excluded_audience_ids'] ?? null,
                'placements' => $raw['placements'] ?? null,
                'placement_type' => $this->string($raw, 'placement_type'),
                'operating_systems' => $raw['operating_systems'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            metadata: array_filter([
                'promotion_type' => $this->string($raw, 'promotion_type'),
                'pacing' => $this->string($raw, 'pacing'),
                'schedule_type' => $this->string($raw, 'schedule_type'),
                'pixel_id' => $this->string($raw, 'pixel_id'),
                'optimization_event' => $this->string($raw, 'optimization_event'),
                'raw_budget_mode' => $budgetMode,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw */
    public function ad(array $raw): AdRecord
    {
        return new AdRecord(
            externalId: (string) ($raw['ad_id'] ?? ''),
            adGroupExternalId: (string) ($raw['adgroup_id'] ?? ''),
            campaignExternalId: (string) ($raw['campaign_id'] ?? ''),
            name: $this->string($raw, 'ad_name') ?? 'Untitled ad',
            status: $this->status(
                $this->string($raw, 'operation_status'),
                $this->string($raw, 'secondary_status'),
            ),
            rawStatus: $this->string($raw, 'operation_status'),
            rawEffectiveStatus: $this->string($raw, 'secondary_status'),
            /*
             * TikTok has no separate creative resource either: the creative
             * fields live on the ad. The ad's own id is reused so creative
             * reporting has something to group by.
             */
            creativeExternalId: isset($raw['ad_id']) ? (string) $raw['ad_id'] : null,
            reviewFeedback: $this->rejectionReason($raw),
            providerCreatedAt: $this->date($this->string($raw, 'create_time')),
            providerUpdatedAt: $this->date($this->string($raw, 'modify_time')),
            metadata: array_filter([
                'ad_format' => $this->string($raw, 'ad_format'),
                'identity_id' => $this->string($raw, 'identity_id'),
                'identity_type' => $this->string($raw, 'identity_type'),
                'tracking_pixel_id' => $this->string($raw, 'tracking_pixel_id'),
                'optimization_event' => $this->string($raw, 'optimization_event'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * The ad, read as a creative.
     *
     * @param  array<string, mixed>  $raw
     */
    public function creative(array $raw): CreativeRecord
    {
        $format = $this->format($this->string($raw, 'ad_format'));
        $assets = [];
        $position = 0;

        $videoId = $this->string($raw, 'video_id');

        if ($videoId !== null) {
            $assets[] = new CreativeAssetRecord(kind: 'video', externalId: $videoId, position: $position++);
        }

        foreach ($this->stringList($raw, 'image_ids') as $imageId) {
            $assets[] = new CreativeAssetRecord(kind: 'image', externalId: $imageId, position: $position++);
        }

        return new CreativeRecord(
            externalId: (string) ($raw['ad_id'] ?? ''),
            name: $this->string($raw, 'ad_name') ?? 'Untitled creative',
            format: $format,
            // TikTok has no separate headline: `ad_text` is the whole caption.
            title: null,
            body: $this->string($raw, 'ad_text'),
            callToAction: $this->string($raw, 'call_to_action'),
            destinationUrl: $this->string($raw, 'landing_page_url'),
            thumbnailUrl: $this->string($raw, 'image_url'),
            previewUrl: $this->string($raw, 'preview_url'),
            assets: $assets,
            metadata: array_filter([
                'ad_format' => $this->string($raw, 'ad_format'),
                'display_name' => $this->string($raw, 'display_name'),
                'music_id' => $this->string($raw, 'music_id'),
                'tiktok_item_id' => $this->string($raw, 'tiktok_item_id'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * One reporting row: `{dimensions: {...}, metrics: {...}}`.
     *
     * @param  array<string, mixed>  $raw
     */
    public function insight(array $raw, AdEntityType $level, string $currency): InsightRecord
    {
        $dimensions = $this->object($raw, 'dimensions');
        $metrics = $this->object($raw, 'metrics');

        $externalId = match ($level) {
            AdEntityType::Campaign => (string) ($dimensions['campaign_id'] ?? ''),
            AdEntityType::AdGroup => (string) ($dimensions['adgroup_id'] ?? ''),
            AdEntityType::Ad => (string) ($dimensions['ad_id'] ?? ''),
            AdEntityType::Account => (string) ($dimensions['advertiser_id'] ?? ''),
        };

        // Multi-advertiser reports return the currency on the row itself.
        $rowCurrency = $this->metricString($metrics, 'currency') ?? $currency;
        $spend = $this->metricString($metrics, 'spend');

        return new InsightRecord(
            entityType: $level,
            externalEntityId: $externalId,
            date: new DateTimeImmutable($this->string($dimensions, 'stat_time_day') ?? 'today'),
            currency: $rowCurrency,
            spendMinor: $spend === null
                ? null
                : Money::fromDecimalString($spend, $rowCurrency)->minorUnits,
            /*
             * Not mapped. TikTok's revenue metric names are the part of its
             * reference that could not be read, and putting the wrong name here
             * would show a plausible figure in the revenue column. Null says
             * "not read", which a chart can label.
             */
            revenueMinor: null,
            impressions: $this->metricInt($metrics, 'impressions'),
            reach: $this->metricInt($metrics, 'reach'),
            // Likewise unverified: left null rather than guessed.
            clicks: null,
            linkClicks: null,
            videoViews: null,
            videoCompletions: null,
            engagements: null,
            conversions: $this->metricFloat($metrics, 'conversions'),
            conversionBreakdown: [],
            accountTimezone: $this->metricString($metrics, 'timezone'),
            /*
             * Everything else the row carried, unchanged. Keeping it means a
             * later verification pass can map a metric without re-syncing, and
             * means nothing was silently discarded in the meantime.
             */
            metadata: array_filter([
                'billed_cost' => $this->metricString($metrics, 'billed_cost'),
                'cash_spend' => $this->metricString($metrics, 'cash_spend'),
                'voucher_spend' => $this->metricString($metrics, 'voucher_spend'),
                'unmapped_metrics' => $this->unverifiedMetrics($metrics),
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * ENABLE/DISABLE refined by the secondary status.
     *
     * `operation_status` is only the switch the advertiser set. An ENABLE
     * campaign over budget, under review or disapproved is not delivering, and
     * only `secondary_status` says so.
     */
    public function status(?string $operationStatus, ?string $secondaryStatus): EntityStatus
    {
        $secondary = strtoupper((string) $secondaryStatus);

        $fromSecondary = match (true) {
            str_contains($secondary, 'DELETE') => EntityStatus::Removed,
            str_contains($secondary, 'REVIEW_DISAPPROVED'), str_contains($secondary, 'AUDIT_DENY') => EntityStatus::Rejected,
            str_contains($secondary, 'AUDIT') => EntityStatus::InReview,
            str_contains($secondary, 'BUDGET_EXCEED'),
            str_contains($secondary, 'UNAVAILABLE'),
            str_contains($secondary, 'PUNISH'),
            str_contains($secondary, 'CONTRACT_PENDING'),
            str_contains($secondary, 'AWAITING_RELEASE'),
            str_contains($secondary, 'AUTHORIZATION_CANCEL') => EntityStatus::NotDelivering,
            default => null,
        };

        if ($fromSecondary instanceof EntityStatus) {
            return $fromSecondary;
        }

        return match (strtoupper((string) $operationStatus)) {
            'ENABLE' => EntityStatus::Active,
            'DISABLE' => EntityStatus::Paused,
            default => EntityStatus::Unknown,
        };
    }

    /** TikTok's objective_type enum, including the values it has deprecated. */
    public function objective(?string $raw): CampaignObjective
    {
        return match (strtoupper((string) $raw)) {
            'REACH', 'RF_REACH', 'TOPVIEW_REACH' => CampaignObjective::Reach,
            'BRAND_CONSIDERATION' => CampaignObjective::Awareness,
            'TRAFFIC', 'RF_TRAFFIC' => CampaignObjective::Traffic,
            'VIDEO_VIEWS', 'RF_VIDEO_VIEW' => CampaignObjective::VideoViews,
            'ENGAGEMENT', 'RF_ENGAGEMENT' => CampaignObjective::Engagement,
            'LEAD_GENERATION' => CampaignObjective::Leads,
            'APP_PROMOTION', 'APP_INSTALL', 'RF_APP_INSTALL' => CampaignObjective::AppPromotion,
            'WEB_CONVERSIONS', 'CONVERSIONS', 'PRODUCT_SALES',
            'CATALOG_SALES', 'SHOP_PURCHASES' => CampaignObjective::Sales,
            default => CampaignObjective::Other,
        };
    }

    public function budgetMode(?string $raw): BudgetMode
    {
        return match (strtoupper((string) $raw)) {
            'BUDGET_MODE_DAY', 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET' => BudgetMode::Daily,
            'BUDGET_MODE_TOTAL' => BudgetMode::Lifetime,
            // Unlimited at this level means the budget is set below it.
            'BUDGET_MODE_INFINITE' => BudgetMode::Unlimited,
            default => BudgetMode::Unknown,
        };
    }

    public function format(?string $adFormat): CreativeFormat
    {
        return match (strtoupper((string) $adFormat)) {
            'SINGLE_VIDEO', 'LIVE_CONTENT' => CreativeFormat::Video,
            'SINGLE_IMAGE' => CreativeFormat::Image,
            'CAROUSEL_ADS' => CreativeFormat::Carousel,
            'COLLECTION_ADS', 'CATALOG_CAROUSEL' => CreativeFormat::Collection,
            default => CreativeFormat::Unknown,
        };
    }

    /**
     * A metric string, with TikTok's `"-"` sentinel honoured.
     *
     * `"-"` means the platform could not produce the figure — a UV metric under
     * maintenance, or a cost metric on a row with no id dimension. Casting it
     * yields 0, which reads as a real result, so it becomes null instead.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function metricString(array $metrics, string $key): ?string
    {
        $value = $metrics[$key] ?? null;

        if (is_string($value)) {
            return $value === '' || $value === '-' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $metrics */
    private function metricInt(array $metrics, string $key): ?int
    {
        $value = $this->metricString($metrics, $key);

        return $value === null || ! is_numeric($value) ? null : (int) round((float) $value);
    }

    /** @param array<string, mixed> $metrics */
    private function metricFloat(array $metrics, string $key): ?float
    {
        $value = $this->metricString($metrics, $key);

        return $value === null || ! is_numeric($value) ? null : (float) $value;
    }

    /**
     * Metrics whose names have not been verified against TikTok's reference.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function unverifiedMetrics(array $metrics): array
    {
        return array_diff_key($metrics, array_flip(self::VERIFIED_METRICS));
    }

    /** @param array<string, mixed> $raw */
    private function rejectionReason(array $raw): ?string
    {
        $reasons = $raw['ad_reject_reasons'] ?? $raw['reject_reasons'] ?? null;

        if (! is_array($reasons) || $reasons === []) {
            return null;
        }

        return implode('; ', array_map('strval', array_filter($reasons, 'is_scalar')));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function object(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private function stringList(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;
        $items = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) || is_int($item)) {
                $items[] = (string) $item;
            }
        }

        return $items;
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

    /**
     * A bid or budget, where zero means "not set" rather than "zero".
     *
     * @param  array<string, mixed>  $raw
     */
    private function positiveFloat(array $raw, string $key): ?float
    {
        $value = $this->float($raw, $key);

        return $value === null || $value <= 0.0 ? null : $value;
    }

    /** @param array<string, mixed> $raw */
    private function float(array $raw, string $key): ?float
    {
        $value = $raw[$key] ?? null;

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
