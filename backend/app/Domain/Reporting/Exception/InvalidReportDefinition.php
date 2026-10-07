<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exception;

use App\Domain\Analytics\Metric;
use App\Domain\Reporting\SectionKind;
use RuntimeException;

/** A report somebody could not read, or could not trust. */
final class InvalidReportDefinition extends RuntimeException
{
    /** @param list<string> $allowed */
    private function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly array $allowed = [],
    ) {
        parent::__construct($message);
    }

    public static function nameRequired(): self
    {
        return new self(
            'A report needs a name. It is what somebody will look for in a month.',
            'name_required',
        );
    }

    /**
     * A report with no sections.
     *
     * Refused rather than saved empty. A saved report that renders nothing is one somebody opens, assumes is
     * broken, and rebuilds — and the rebuilt one is the second report with the same name.
     */
    public static function sectionsRequired(): self
    {
        return new self(
            'A report needs at least one section. An empty one reads as a broken one.',
            'sections_required',
        );
    }

    public static function metricsRequired(SectionKind $kind): self
    {
        return new self(
            sprintf('A %s needs at least one metric.', mb_strtolower($kind->label())),
            'metrics_required',
        );
    }

    /** More metrics than the shape can carry without becoming unreadable. */
    public static function tooManyMetrics(SectionKind $kind): self
    {
        return new self(
            sprintf(
                '%s carries at most %d metrics. Past that the shape stops being readable — split it into two '
                .'sections instead.',
                $kind->label(),
                $kind->metricCap(),
            ),
            'too_many_metrics',
        );
    }

    /**
     * A metric that cannot honestly appear in this shape.
     *
     * Today that means reach or frequency in a ranking: both count deduplicated people, so a reader adding the rows
     * double-counts anyone who appears in two of them. The read model already refuses the total; this refuses the
     * column, which is where the invitation to add was.
     */
    public static function metricNotAllowedHere(SectionKind $kind, Metric $metric): self
    {
        return new self(
            sprintf(
                '%s cannot appear in %s. It counts deduplicated people, so the rows do not add up to anything — '
                .'and a column of them invites exactly that.',
                $metric->label(),
                mb_strtolower($kind->label()),
            ),
            'metric_not_allowed_here',
            [$metric->value],
        );
    }

    public static function dimensionRequired(SectionKind $kind): self
    {
        return new self(
            sprintf('%s needs a dimension to group by.', $kind->label()),
            'dimension_required',
        );
    }

    /** A custom window with no dates. */
    public static function datesRequired(): self
    {
        return new self(
            'A report over a specific period needs both of its dates.',
            'dates_required',
        );
    }
}
