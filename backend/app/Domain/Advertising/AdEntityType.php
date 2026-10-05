<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * The level a metric row is attributed to.
 *
 * Metrics are stored once per level rather than only at the leaf and rolled up,
 * because providers do not guarantee that a parent's figures equal the sum of
 * its children — deduplicated reach is the obvious case, and attribution
 * windows shift conversions between levels. Summing ad-level reach to get
 * campaign reach produces a number no platform would agree with.
 */
enum AdEntityType: string
{
    case Account = 'account';
    case Campaign = 'campaign';
    case AdGroup = 'ad_group';
    case Ad = 'ad';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Account',
            self::Campaign => 'Campaign',
            self::AdGroup => 'Ad set',
            self::Ad => 'Ad',
        };
    }

    /** The level above this one, for drill-up navigation. */
    public function parent(): ?self
    {
        return match ($this) {
            self::Account => null,
            self::Campaign => self::Account,
            self::AdGroup => self::Campaign,
            self::Ad => self::AdGroup,
        };
    }

    /**
     * Whether this level's figures may be summed from the level below.
     *
     * Never for reach or frequency, which are deduplicated by the platform.
     */
    public function allowsRollUp(): bool
    {
        return $this !== self::Account;
    }
}
