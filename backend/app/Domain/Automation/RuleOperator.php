<?php

declare(strict_types=1);

namespace App\Domain\Automation;

enum RuleOperator: string
{
    case Above = 'above';
    case Below = 'below';

    public function breached(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Above => $value > $threshold,
            self::Below => $value < $threshold,
        };
    }
}
