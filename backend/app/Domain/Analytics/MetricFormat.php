<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * How a figure should be rendered.
 *
 * Declared by the domain rather than chosen in the interface, so a metric cannot
 * be formatted two different ways on two screens — which, for money and
 * percentages, is how a reader loses trust in a dashboard.
 */
enum MetricFormat: string
{
    case Currency = 'currency';
    case Count = 'count';
    case Percentage = 'percentage';
    case Ratio = 'ratio';

    /** Decimal places the figure is meaningful to. */
    public function precision(): int
    {
        return match ($this) {
            self::Currency => 2,
            self::Count => 0,
            self::Percentage => 2,
            self::Ratio => 2,
        };
    }
}
