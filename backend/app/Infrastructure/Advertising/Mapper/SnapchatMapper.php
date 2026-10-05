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
 * Snapchat Marketing API → digitalSmart.
 *
 * Field names and enum values were read from Snap's published reference. The shapes
 * that matter:
 *
 *   - Every list response wraps its items twice:
 *     `{request_status, paging: {next_link}, campaigns: [{sub_request_status, campaign: {…}}]}`.
 *     The per-item `sub_request_status` is why: a batch can partly succeed, and an
 *     item that failed carries no entity.
 *   - Money is **micro-currency** — `daily_budget_micro`, `lifetime_spend_cap_micro`,
 *     `bid_micro` — the same convention as Google's micros.
 *   - `status` is only ACTIVE or PAUSED. Why something is not delivering lives in
 *     `delivery_status`, an array of reason codes.
 *   - Pagination is a whole `next_link` URL rather than a token, so the cursor the
 *     caller stores is that URL.
 *   - Snapchat's click equivalent is a **swipe**. There is no separate link-click
 *     metric, and reach is `uniques`.
 */
final class SnapchatMapper
{
    /**
     * Metric names confirmed against Snap's measurement reference.
     *
     * Anything outside this list is carried into metadata rather than guessed at.
     */
    private const VERIFIED_METRICS = [
        'impressions', 'swipes', 'spend', 'view_completion', 'screen_time_millis',
        'quartile_1', 'quartile_2', 'quartile_3',
        'uniques', 'frequency', 'total_reach',
        'android_installs', 'ios_installs', 'total_installs',
        'conversion_purchases', 'conversion_purchases_value', 'conversion_save',
        'conversion_add_cart', 'conversion_page_views', 'conversion_sign_ups',
        'attachment_total_view_time_millis', 'attachment_view_completion',
        'attachment_frequency', 'attachment_uniques',
    ];

    /** @param array<string, mixed> $raw the inner `campaign` object */
    public function campaign(array $raw, string $currency): CampaignRecord
    {
        $daily = $this->int($raw, 'daily_budget_micro');
        $lifetime = $this->int($raw, 'lifetime_spend_cap_micro');

        /*
         * Snapchat's lifetime field is a *spend cap*, not a lifetime budget: it limits
         * total spend while the daily budget still paces delivery. So a campaign with
         * both has a daily budget and a cap, and only a campaign with the cap alone is
         * reported as lifetime-budgeted.
         */
        [$budgetMode, $budgetMinor] = match (true) {
            $daily !== null && $daily > 0 => [BudgetMode::Daily, Money::fromMicros($daily, $currency)->minorUnits],
            $lifetime !== null && $lifetime > 0 => [BudgetMode::Lifetime, Money::fromMicros($lifetime, $currency)->minorUnits],
            // No budget here means the ad squads carry it.
            default => [BudgetMode::Inherited, null],
        };

        $objective = $this->string($raw, 'objective');

        return new CampaignRecord(
            externalId: (string) ($raw['id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled campaign',
            status: $this->status($this->string($raw, 'status'), $raw['delivery_status'] ?? null),
            rawStatus: $this->string($raw, 'status'),
            // Snapchat's equivalent of Meta's effective_status: a list of reasons.
            rawEffectiveStatus: $this->deliveryStatus($raw['delivery_status'] ?? null),
            objective: $this->objective($objective),
            rawObjective: $objective,
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            spendCapMinor: $lifetime === null || $lifetime === 0
                ? null
                : Money::fromMicros($lifetime, $currency)->minorUnits,
            currency: $currency,
            buyingType: $this->string($raw, 'buy_model'),
            // Bidding is configured per ad squad, not per campaign.
            bidStrategy: null,
            startsAt: $this->date($this->string($raw, 'start_time')),
            endsAt: $this->date($this->string($raw, 'end_time')),
            providerCreatedAt: $this->date($this->string($raw, 'created_at')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_at')),
            metadata: array_filter([
                'ad_account_id' => $this->string($raw, 'ad_account_id'),
                'creation_state' => $this->string($raw, 'creation_state'),
                'delivery_status' => $raw['delivery_status'] ?? null,
                'objective_v2_properties' => $raw['objective_v2_properties'] ?? null,
                'measurement_spec' => $raw['measurement_spec'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw the inner `adsquad` object */
    public function adGroup(array $raw, string $currency): AdGroupRecord
    {
        $daily = $this->int($raw, 'daily_budget_micro');
        $lifetime = $this->int($raw, 'lifetime_budget_micro');
        $bid = $this->int($raw, 'bid_micro');

        [$budgetMode, $budgetMinor] = match (true) {
            $daily !== null && $daily > 0 => [BudgetMode::Daily, Money::fromMicros($daily, $currency)->minorUnits],
            $lifetime !== null && $lifetime > 0 => [BudgetMode::Lifetime, Money::fromMicros($lifetime, $currency)->minorUnits],
            default => [BudgetMode::Inherited, null],
        };

        return new AdGroupRecord(
            externalId: (string) ($raw['id'] ?? ''),
            campaignExternalId: (string) ($raw['campaign_id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled ad squad',
            status: $this->status($this->string($raw, 'status'), $raw['delivery_status'] ?? null),
            rawStatus: $this->string($raw, 'status'),
            rawEffectiveStatus: $this->deliveryStatus($raw['delivery_status'] ?? null),
            optimizationGoal: $this->string($raw, 'optimization_goal'),
            /*
             * Snapchat bills on impressions and nothing else — `billing_event` has a
             * single enum value. Recorded as reported rather than omitted, so a future
             * addition shows up rather than being silently normalised away.
             */
            billingEvent: $this->string($raw, 'billing_event'),
            bidAmountMinor: $bid === null || $bid === 0
                ? null
                : Money::fromMicros($bid, $currency)->minorUnits,
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            currency: $currency,
            startsAt: $this->date($this->string($raw, 'start_time')),
            endsAt: $this->date($this->string($raw, 'end_time')),
            providerCreatedAt: $this->date($this->string($raw, 'created_at')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_at')),
            targeting: is_array($raw['targeting'] ?? null) ? $raw['targeting'] : [],
            metadata: array_filter([
                'type' => $this->string($raw, 'type'),
                'bid_strategy' => $this->string($raw, 'bid_strategy'),
                'pacing_type' => $this->string($raw, 'pacing_type'),
                'delivery_constraint' => $this->string($raw, 'delivery_constraint'),
                'conversion_window' => $raw['conversion_window'] ?? null,
                'placement_v2' => $raw['placement_v2'] ?? null,
                'child_ad_type' => $this->string($raw, 'child_ad_type'),
                'delivery_status' => $raw['delivery_status'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw the inner `ad` object */
    public function ad(array $raw): AdRecord
    {
        $review = $this->string($raw, 'review_status');

        return new AdRecord(
            externalId: (string) ($raw['id'] ?? ''),
            adGroupExternalId: (string) ($raw['ad_squad_id'] ?? ''),
            /*
             * Snapchat's ad carries no campaign id: the hierarchy is reached through
             * the ad squad. Left empty rather than guessed; the store resolves the
             * campaign from the ad squad it already synced.
             */
            campaignExternalId: (string) ($raw['campaign_id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled ad',
            status: $this->adStatus($this->string($raw, 'status'), $review, $raw['delivery_status'] ?? null),
            rawStatus: $this->string($raw, 'status'),
            rawEffectiveStatus: $review ?? $this->deliveryStatus($raw['delivery_status'] ?? null),
            creativeExternalId: $this->string($raw, 'creative_id'),
            reviewFeedback: $this->reviewReasons($raw['review_status_reasons'] ?? null),
            providerCreatedAt: $this->date($this->string($raw, 'created_at')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_at')),
            metadata: array_filter([
                'type' => $this->string($raw, 'type'),
                'render_type' => $this->string($raw, 'render_type'),
                'delivery_status' => $raw['delivery_status'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $raw the inner `creative` object */
    public function creative(array $raw): CreativeRecord
    {
        $type = $this->string($raw, 'type');
        $webView = $this->object($raw, 'web_view_properties');
        $appInstall = $this->object($raw, 'app_install_properties');
        $deepLink = $this->object($raw, 'deep_link_properties');

        $assets = [];
        $topSnap = $this->string($raw, 'top_snap_media_id');

        if ($topSnap !== null) {
            /*
             * A top snap is the full-screen creative, which may be a still or a video.
             * Snapchat's creative object does not say which — that lives on the media
             * resource — so the kind is `media` rather than a guess at either.
             */
            $assets[] = new CreativeAssetRecord(
                kind: 'media',
                externalId: $topSnap,
                metadata: array_filter([
                    'crop_position' => $this->string($raw, 'top_snap_crop_position'),
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        foreach (['icon_media_id'] as $key) {
            $icon = $this->string($appInstall, $key) ?? $this->string($deepLink, $key);

            if ($icon !== null) {
                $assets[] = new CreativeAssetRecord(kind: 'icon', externalId: $icon, position: 1);
            }
        }

        return new CreativeRecord(
            externalId: (string) ($raw['id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled creative',
            format: $this->format($type),
            // Snapchat's single text field is the headline; there is no body copy.
            title: $this->string($raw, 'headline'),
            body: null,
            callToAction: $this->string($raw, 'call_to_action'),
            destinationUrl: $this->string($webView, 'url')
                ?? $this->string($deepLink, 'deep_link_uri'),
            thumbnailUrl: null,
            previewUrl: null,
            assets: $assets,
            metadata: array_filter([
                'type' => $type,
                'brand_name' => $this->string($raw, 'brand_name'),
                'review_status' => $this->string($raw, 'review_status'),
                'packaging_status' => $this->string($raw, 'packaging_status'),
                'shareable' => $raw['shareable'] ?? null,
                'ad_product' => $this->string($raw, 'ad_product'),
                'app_name' => $this->string($appInstall, 'app_name') ?? $this->string($deepLink, 'app_name'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * One day of a timeseries entry.
     *
     * Snapchat nests stats three deep:
     * `timeseries_stats[].timeseries_stat.timeseries[] = {start_time, end_time, stats}`.
     * The caller flattens that; this maps one flattened row, which carries the entity
     * id and granularity alongside its own `stats`.
     *
     * @param  array<string, mixed>  $raw
     */
    public function insight(array $raw, AdEntityType $level, string $currency): InsightRecord
    {
        $stats = $this->object($raw, 'stats');
        $spend = $this->int($stats, 'spend');

        $purchases = $this->float($stats, 'conversion_purchases');
        $installs = $this->int($stats, 'total_installs')
            ?? $this->sumInstalls($stats);

        return new InsightRecord(
            entityType: $level,
            externalEntityId: (string) ($raw['id'] ?? ''),
            date: new DateTimeImmutable($this->string($raw, 'start_time') ?? 'today'),
            currency: $currency,
            /*
             * Micro-currency. Every money field Snapchat publishes carries a `_micro`
             * suffix, and `spend` is the one that does not — see the caveat in
             * docs/INTEGRATIONS.md. Reading it as minor units would understate spend
             * ten-thousand-fold, so the assumption is recorded rather than hidden.
             */
            spendMinor: $spend === null ? null : Money::fromMicros($spend, $currency)->minorUnits,
            revenueMinor: $this->moneyFromDecimal($stats, 'conversion_purchases_value', $currency),
            impressions: $this->int($stats, 'impressions'),
            // Snapchat's deduplicated reach.
            reach: $this->int($stats, 'uniques') ?? $this->int($stats, 'total_reach'),
            // A swipe is Snapchat's click.
            clicks: $this->int($stats, 'swipes'),
            /*
             * There is no link-click metric distinct from a swipe. Copying swipes here
             * would make two different CTRs identical for a reason that is ours.
             */
            linkClicks: null,
            /*
             * `view_completion` is a count of completed views, and the quartiles are
             * counts too — unlike Google, which reports quartiles as rates. Nothing is
             * derived here.
             */
            videoViews: $this->int($stats, 'quartile_1'),
            videoCompletions: $this->int($stats, 'view_completion'),
            /*
             * Snapchat publishes no aggregate engagement metric. Screen time and
             * attachment views are kept in metadata rather than collapsed into one
             * number nobody defined.
             */
            engagements: null,
            conversions: $purchases,
            conversionBreakdown: $this->conversions($stats, $currency),
            metadata: array_filter([
                'screen_time_millis' => $stats['screen_time_millis'] ?? null,
                'frequency' => $stats['frequency'] ?? null,
                'quartile_2' => $stats['quartile_2'] ?? null,
                'quartile_3' => $stats['quartile_3'] ?? null,
                'total_installs' => $installs,
                'android_installs' => $stats['android_installs'] ?? null,
                'ios_installs' => $stats['ios_installs'] ?? null,
                'attachment_total_view_time_millis' => $stats['attachment_total_view_time_millis'] ?? null,
                'attachment_uniques' => $stats['attachment_uniques'] ?? null,
                'granularity' => $this->string($raw, 'granularity'),
                'unmapped_stats' => $this->unverifiedStats($stats),
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * ACTIVE/PAUSED, refined by the delivery reasons.
     *
     * `status` is only the switch the advertiser set. An ACTIVE campaign that has hit
     * its spend cap, is outside its schedule or has no eligible ads is not delivering,
     * and only `delivery_status` says so.
     *
     * @param  mixed  $deliveryStatus  an array of reason codes
     */
    public function status(?string $status, mixed $deliveryStatus): EntityStatus
    {
        $reasons = $this->reasonCodes($deliveryStatus);

        foreach ($reasons as $reason) {
            $fromReason = match (true) {
                str_contains($reason, 'REJECTED'), str_contains($reason, 'DISAPPROVED') => EntityStatus::Rejected,
                str_contains($reason, 'PENDING_REVIEW'), str_contains($reason, 'IN_REVIEW') => EntityStatus::InReview,
                str_contains($reason, 'COMPLETED'), str_contains($reason, 'ENDED') => EntityStatus::Completed,
                str_contains($reason, 'BUDGET'),
                str_contains($reason, 'SPEND_CAP'),
                str_contains($reason, 'NO_ACTIVE'),
                str_contains($reason, 'OUTSIDE'),
                str_contains($reason, 'PAUSED_BY') => EntityStatus::NotDelivering,
                default => null,
            };

            if ($fromReason instanceof EntityStatus) {
                return $fromReason;
            }
        }

        return match (strtoupper((string) $status)) {
            'ACTIVE' => EntityStatus::Active,
            'PAUSED' => EntityStatus::Paused,
            default => EntityStatus::Unknown,
        };
    }

    /** An ad's review outcome overrides its switch: a rejected ad cannot deliver. */
    public function adStatus(?string $status, ?string $reviewStatus, mixed $deliveryStatus): EntityStatus
    {
        return match (strtoupper((string) $reviewStatus)) {
            'REJECTED' => EntityStatus::Rejected,
            'PENDING' => EntityStatus::InReview,
            default => $this->status($status, $deliveryStatus),
        };
    }

    /** Snapchat's objective enum. */
    public function objective(?string $raw): CampaignObjective
    {
        return match (strtoupper((string) $raw)) {
            'BRAND_AWARENESS' => CampaignObjective::Awareness,
            'ENGAGEMENT', 'PROMOTE_STORIES' => CampaignObjective::Engagement,
            'VIDEO_VIEW' => CampaignObjective::VideoViews,
            'LEAD_GENERATION' => CampaignObjective::Leads,
            'APP_INSTALL', 'APP_CONVERSION' => CampaignObjective::AppPromotion,
            'WEB_CONVERSION', 'CATALOG_SALES' => CampaignObjective::Sales,
            'PROMOTE_PLACES' => CampaignObjective::StoreTraffic,
            default => CampaignObjective::Other,
        };
    }

    /** Snapchat's creative type onto our formats. */
    public function format(?string $type): CreativeFormat
    {
        return match (strtoupper((string) $type)) {
            'SNAP_AD', 'APP_INSTALL', 'WEB_VIEW', 'DEEP_LINK',
            'AD_TO_CALL', 'AD_TO_MESSAGE', 'REMINDER', 'LEAD_GENERATION' => CreativeFormat::Video,
            'COLLECTION' => CreativeFormat::Collection,
            'COMPOSITE' => CreativeFormat::Carousel,
            default => CreativeFormat::Unknown,
        };
    }

    /** Snapchat's conversion metric names onto our vocabulary. */
    public function conversionType(string $metric): ConversionActionType
    {
        return match ($metric) {
            'conversion_purchases' => ConversionActionType::Purchase,
            'conversion_add_cart' => ConversionActionType::AddToCart,
            'conversion_sign_ups' => ConversionActionType::CompleteRegistration,
            'conversion_page_views' => ConversionActionType::LandingPageView,
            'conversion_save' => ConversionActionType::Other,
            'total_installs', 'android_installs', 'ios_installs' => ConversionActionType::AppInstall,
            default => ConversionActionType::Other,
        };
    }

    /**
     * One conversion record per reported conversion metric.
     *
     * Snapchat reports conversions as named columns rather than an action array, so
     * each column that carries a figure becomes a row. Only the purchase column has a
     * value counterpart, which is why the others carry none.
     *
     * @param  array<string, mixed>  $stats
     * @return list<ConversionRecord>
     */
    private function conversions(array $stats, string $currency): array
    {
        $columns = [
            'conversion_purchases', 'conversion_add_cart', 'conversion_sign_ups',
            'conversion_page_views', 'conversion_save', 'total_installs',
        ];

        $records = [];

        foreach ($columns as $column) {
            $count = $this->float($stats, $column);

            if ($count === null) {
                continue;
            }

            $records[] = new ConversionRecord(
                rawActionType: $column,
                count: $count,
                actionType: $this->conversionType($column),
                valueMinor: $column === 'conversion_purchases'
                    ? $this->moneyFromDecimal($stats, 'conversion_purchases_value', $currency)
                    : null,
                currency: $column === 'conversion_purchases' ? $currency : null,
            );
        }

        return $records;
    }

    /**
     * Installs summed from the per-platform columns when the total is absent.
     *
     * @param  array<string, mixed>  $stats
     */
    private function sumInstalls(array $stats): ?int
    {
        $android = $this->int($stats, 'android_installs');
        $ios = $this->int($stats, 'ios_installs');

        // Null when neither was reported, so "no install data" stays distinct from
        // "no installs happened".
        return $android === null && $ios === null ? null : ($android ?? 0) + ($ios ?? 0);
    }

    /**
     * A decimal money figure in the account currency.
     *
     * `conversion_purchases_value` is the pixel-reported order value, which arrives as
     * a plain decimal rather than micros — unlike every budget field.
     *
     * @param  array<string, mixed>  $stats
     */
    private function moneyFromDecimal(array $stats, string $key, string $currency): ?int
    {
        $value = $this->float($stats, $key);

        return $value === null ? null : Money::fromDecimalString((string) $value, $currency)->minorUnits;
    }

    /**
     * Reason codes from a `delivery_status` array.
     *
     * @return list<string>
     */
    private function reasonCodes(mixed $deliveryStatus): array
    {
        $codes = [];

        foreach (is_array($deliveryStatus) ? $deliveryStatus : [] as $entry) {
            if (is_string($entry)) {
                $codes[] = strtoupper($entry);
            } elseif (is_array($entry) && isset($entry['reason']) && is_string($entry['reason'])) {
                $codes[] = strtoupper($entry['reason']);
            }
        }

        return $codes;
    }

    private function deliveryStatus(mixed $deliveryStatus): ?string
    {
        $codes = $this->reasonCodes($deliveryStatus);

        return $codes === [] ? null : implode(',', $codes);
    }

    private function reviewReasons(mixed $reasons): ?string
    {
        $messages = [];

        foreach (is_array($reasons) ? $reasons : [] as $reason) {
            if (is_string($reason)) {
                $messages[] = $reason;
            }
        }

        return $messages === [] ? null : implode('; ', $messages);
    }

    /**
     * Stats whose names this mapper has not confirmed.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function unverifiedStats(array $stats): array
    {
        return array_diff_key($stats, array_flip(self::VERIFIED_METRICS));
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

    /** @param array<string, mixed> $raw */
    private function string(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $raw */
    private function int(array $raw, string $key): ?int
    {
        $value = $raw[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return is_string($value) && is_numeric($value) ? (int) round((float) $value) : null;
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
