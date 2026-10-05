<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Analytics\Dimension;

/**
 * How a widget renders what it asked for.
 *
 * Each type states which shape of question it can answer, because the two are not
 * interchangeable: a time series needs a date axis, a share-of-total needs
 * categories that add up, and a scatter needs two metrics rather than one. Letting
 * a reader pick a pie chart for a date breakdown would produce a chart that is
 * technically drawable and tells them nothing.
 */
enum WidgetType: string
{
    /** A single figure with its comparison. No dimension. */
    case Scorecard = 'scorecard';
    /** One or two metrics over time. */
    case Line = 'line';
    /** A metric across categories. */
    case Bar = 'bar';
    /** Share of a total across categories. */
    case Share = 'share';
    /** Two metrics against each other, one point per entity. */
    case Scatter = 'scatter';
    /** The figures as a table. Always available, and the honest fallback. */
    case Table = 'table';

    /** Whether this type needs a grouping dimension to mean anything. */
    public function requiresDimension(): bool
    {
        return $this !== self::Scorecard && $this !== self::Line;
    }

    /** Whether it plots over time rather than across categories. */
    public function isTimeSeries(): bool
    {
        return $this === self::Line;
    }

    /** Whether it needs a second metric for its other axis. */
    public function requiresSecondMetric(): bool
    {
        return $this === self::Scatter;
    }

    /**
     * Whether a dimension can sensibly be shown as this type.
     *
     * A share-of-total over dates is the clearest example of a chart that draws
     * without meaning: ninety slices, each a day, summing to a number nobody asked
     * for.
     */
    public function supports(?Dimension $dimension): bool
    {
        if (! $this->requiresDimension()) {
            return $dimension === null || $dimension === Dimension::Date;
        }

        if ($dimension === null) {
            return false;
        }

        return match ($this) {
            self::Share, self::Scatter => $dimension !== Dimension::Date,
            default => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Scorecard => 'Single figure',
            self::Line => 'Trend over time',
            self::Bar => 'Comparison',
            self::Share => 'Share of total',
            self::Scatter => 'Efficiency vs scale',
            self::Table => 'Table',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
