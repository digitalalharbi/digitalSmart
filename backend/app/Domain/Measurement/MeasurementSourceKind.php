<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

/**
 * What kind of thing is doing the measuring.
 *
 * The kind decides what a figure from it can be compared with. An analytics property and a
 * store both report purchases, and they will disagree: one counts what the browser told it,
 * the other counts what was invoiced. Keeping the kind on the source is what lets a report
 * say *which* it is showing instead of adding them.
 */
enum MeasurementSourceKind: string
{
    case Analytics = 'analytics';
    case TagManager = 'tag_manager';
    case AdPlatform = 'ad_platform';
    case Store = 'store';
    case Crm = 'crm';
    case Website = 'website';
    /** A figure somebody typed, because no system reports it. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Analytics => 'Analytics',
            self::TagManager => 'Tag manager',
            self::AdPlatform => 'Ad platform',
            self::Store => 'Store',
            self::Crm => 'CRM',
            self::Website => 'Website',
            self::Manual => 'Manual',
        };
    }

    /**
     * Whether this kind of source is the authority on money.
     *
     * Only a store and a CRM are. An analytics property reports what a browser said a
     * purchase was worth, which is a measurement of a page rather than of a sale, and an ad
     * platform reports what it believes it caused.
     */
    public function isRevenueAuthority(): bool
    {
        return $this === self::Store || $this === self::Crm;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
