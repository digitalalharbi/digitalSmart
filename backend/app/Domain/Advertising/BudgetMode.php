<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

enum BudgetMode: string
{
    case Daily = 'daily';
    case Lifetime = 'lifetime';
    /** The budget is set on the parent (campaign budget optimisation, and equivalents). */
    case Inherited = 'inherited';
    case Unlimited = 'unlimited';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Lifetime => 'Lifetime',
            self::Inherited => 'Set on the campaign',
            self::Unlimited => 'No cap',
            self::Unknown => 'Unknown',
        };
    }
}
