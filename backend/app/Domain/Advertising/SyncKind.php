<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * What a sync run was fetching.
 *
 * Structure and performance are separate kinds because they behave differently:
 * structure is read forward with a pagination cursor and rarely changes,
 * insights must be re-read backwards as attribution settles.
 */
enum SyncKind: string
{
    case Accounts = 'accounts';
    case Campaigns = 'campaigns';
    case AdGroups = 'ad_groups';
    case Ads = 'ads';
    case Creatives = 'creatives';
    case Insights = 'insights';
    case Conversions = 'conversions';

    /**
     * Structure kinds, in the order their dependencies require.
     *
     * Ads come last because an ad references both an ad group and a creative.
     *
     * @return list<self>
     */
    public static function structureOrder(): array
    {
        return [self::Campaigns, self::AdGroups, self::Creatives, self::Ads];
    }

    /** Whether this kind is read over a date range rather than paginated once. */
    public function isTimeSeries(): bool
    {
        return $this === self::Insights || $this === self::Conversions;
    }

    public function label(): string
    {
        return match ($this) {
            self::Accounts => 'Accounts',
            self::Campaigns => 'Campaigns',
            self::AdGroups => 'Ad groups',
            self::Ads => 'Ads',
            self::Creatives => 'Creatives',
            self::Insights => 'Performance',
            self::Conversions => 'Conversions',
        };
    }
}
