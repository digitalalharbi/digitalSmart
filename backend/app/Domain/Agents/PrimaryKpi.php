<?php

declare(strict_types=1);

namespace App\Domain\Agents;

enum PrimaryKpi: string
{
    case Roas = 'roas';
    case Cpa = 'cpa';
    case Cpl = 'cpl';
    case Leads = 'leads';
    case Purchases = 'purchases';
    case Reach = 'reach';
    case Clicks = 'clicks';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
