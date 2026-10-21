<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** What a creative's own figures support saying about it. Absent — null — when they support nothing. */
enum CreativeVerdict: string
{
    case Top = 'top';
    case Rising = 'rising';
    case Stable = 'stable';
    case Fatiguing = 'fatiguing';
    case Weak = 'weak';

    public function label(): string
    {
        return match ($this) {
            self::Top => 'Top',
            self::Rising => 'Rising',
            self::Stable => 'Stable',
            self::Fatiguing => 'Fatiguing',
            self::Weak => 'Weak',
        };
    }
}
