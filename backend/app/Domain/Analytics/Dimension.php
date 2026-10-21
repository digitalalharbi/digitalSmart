<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * What a result can be grouped by.
 *
 * Each dimension names the column it groups on, so the read model never
 * interpolates a caller's string into SQL.
 */
enum Dimension: string
{
    case Date = 'date';
    case Provider = 'provider';
    case ExternalAccount = 'external_account';
    case Campaign = 'campaign';
    case AdGroup = 'ad_group';
    case Ad = 'ad';

    /**
     * The platform's creative — the asset an ad runs — as distinct from the ad that runs it.
     *
     * One creative can sit behind several ads, so grouping by it answers "which asset performs best" where grouping by ad
     * answers "which placement of it". It has no column of its own in the fact table: it is reached through the ad, by a join
     * the read model makes, and an ad whose creative is not known is in no creative's figures rather than in a guessed one.
     */
    case Creative = 'creative';

    /** The daily_metrics column this groups on. */
    public function column(): string
    {
        return match ($this) {
            self::Date => 'date',
            self::Provider => 'provider',
            self::ExternalAccount => 'external_account_id',
            self::Campaign => 'campaign_id',
            self::AdGroup => 'ad_group_id',
            self::Ad => 'ad_id',
            self::Creative => 'joined_creative_id',
        };
    }

    /**
     * The level of rows that can answer a grouping by this dimension.
     *
     * Grouping by campaign reads campaign-level rows, not ad rows summed up:
     * reach is deduplicated and attribution moves conversions between levels, so
     * the two disagree and only one of them is the platform's own answer.
     */
    public function level(): ?string
    {
        return match ($this) {
            self::Campaign => 'campaign',
            self::AdGroup => 'ad_group',
            self::Ad, self::Creative => 'ad',
            // Date, provider and account can be answered at any level.
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Date => 'Date',
            self::Provider => 'Platform',
            self::ExternalAccount => 'Ad account',
            self::Campaign => 'Campaign',
            self::AdGroup => 'Ad group',
            self::Ad => 'Ad',
            self::Creative => 'Creative',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
