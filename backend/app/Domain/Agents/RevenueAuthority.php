<?php

declare(strict_types=1);

namespace App\Domain\Agents;

/**
 * Whose revenue figure counts for a project.
 *
 * The platforms claim revenue, the store invoices it, the CRM closes it; they are different numbers and a
 * recommendation has to say which one it is judging against. One is chosen per project, and anything an agent says
 * about return names it.
 */
enum RevenueAuthority: string
{
    case PlatformReported = 'platform_reported';
    case Ga4 = 'ga4';
    case Store = 'store';
    case Crm = 'crm';
    case Attributed = 'attributed';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::PlatformReported => 'Platform reported',
            self::Ga4 => 'GA4',
            self::Store => 'Store',
            self::Crm => 'CRM',
            self::Attributed => 'Attributed',
            self::Manual => 'Manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
