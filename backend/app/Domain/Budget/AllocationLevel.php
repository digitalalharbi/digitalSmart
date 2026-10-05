<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 * The four rungs of the budget governance hierarchy.
 *
 * Approved client budget → platform allocation → campaign allocation → ad group
 * allocation → actual spend. Each level narrows the one above it, and the level is
 * explicit rather than inferred from which foreign key happens to be set, so a
 * half-filled row cannot pass for a different kind of allocation.
 */
enum AllocationLevel: string
{
    case Platform = 'platform';
    case Campaign = 'campaign';
    case AdGroup = 'ad_group';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform',
            self::Campaign => 'Campaign',
            self::AdGroup => 'Ad group',
        };
    }

    /** The level an allocation at this level must sit under, if any. */
    public function parent(): ?self
    {
        return match ($this) {
            self::Platform => null,
            self::Campaign => self::Platform,
            self::AdGroup => self::Campaign,
        };
    }

    /**
     * Whether this level requires a provider to be named.
     *
     * Every level does: an allocation that does not say which platform it is for
     * cannot be compared with any actual spend, which makes it a number in a
     * spreadsheet rather than a budget.
     */
    public function requiresProvider(): bool
    {
        return true;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
