<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Advertising\MetricSource;

/**
 * Everything one analytics surface needs, computed together.
 *
 * Together on purpose: a scorecard whose total disagrees with the sum of its own
 * chart is how a reader stops believing a dashboard. One request, one set of
 * filters, one answer.
 */
final readonly class AnalyticsResult
{
    /**
     * @param  list<SeriesPoint>  $series
     * @param  list<BreakdownRow>  $breakdown
     */
    public function __construct(
        public AnalyticsRequest $request,
        public MetricSet $summary,
        public array $series = [],
        public array $breakdown = [],
    ) {}

    public static function empty(AnalyticsRequest $request): self
    {
        $values = [];

        foreach ($request->metrics as $metric) {
            $values[$metric->value] = MetricValue::unavailable(
                $metric,
                'No data for the selected period and filters.',
            );
        }

        return new self(
            request: $request,
            summary: new MetricSet($values, MetricSource::Live, null, 0),
        );
    }

    /** Whether there is anything at all to show. */
    public function isEmpty(): bool
    {
        return $this->summary->isEmpty();
    }

    /**
     * The source state the interface must label the whole surface with.
     *
     * Taken from the summary, which already carries its weakest input's state, so
     * a chart and its scorecard cannot disagree about how trustworthy they are.
     */
    public function source(): MetricSource
    {
        return $this->summary->source;
    }
}
