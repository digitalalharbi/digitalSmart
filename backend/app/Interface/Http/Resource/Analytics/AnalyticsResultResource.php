<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Analytics;

use App\Application\Analytics\DTO\AnalyticsResult;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Domain\Analytics\Metric;

/** A whole analytics surface: totals, series and breakdown, from one query. */
final readonly class AnalyticsResultResource
{
    /** @return array<string, mixed> */
    public static function toArray(AnalyticsResult $result): array
    {
        $request = $result->request;

        return [
            'range' => [
                'start' => $request->range->startDate(),
                'end' => $request->range->endDate(),
                'days' => $request->range->lengthInDays(),
            ],
            'compare_to' => $request->compareTo === null ? null : [
                'start' => $request->compareTo->startDate(),
                'end' => $request->compareTo->endDate(),
            ],
            'metrics' => array_map(
                static fn (Metric $metric): string => $metric->value,
                $request->metrics,
            ),
            /*
             * One source state for the whole surface, taken from the summary so a
             * chart and its scorecard cannot disagree about how much they can be
             * trusted. The client renders a badge from this.
             */
            'source' => $result->source()->value,
            'source_label' => $result->source()->label(),
            'requires_label' => $result->source()->requiresLabel(),
            'is_empty' => $result->isEmpty(),
            'summary' => self::set($result->summary),
            'series' => array_map(
                static fn (SeriesPoint $point): array => [
                    'date' => $point->date->format('Y-m-d'),
                    ...self::set($point->metrics),
                ],
                $result->series,
            ),
            'breakdown' => array_map(
                static fn (BreakdownRow $row): array => [
                    'dimension' => $row->dimension->value,
                    'key' => $row->key,
                    'label' => $row->label,
                    'external_id' => $row->externalId,
                    'status' => $row->status,
                    'parent_key' => $row->parentKey,
                    ...self::set($row->metrics),
                ],
                $result->breakdown,
            ),
            'dimension' => $request->dimension?->value,
        ];
    }

    /** @return array<string, mixed> */
    private static function set(MetricSet $set): array
    {
        return [
            'source' => $set->source->value,
            'currency' => $set->currency,
            'row_count' => $set->rowCount,
            'values' => array_map(
                static fn (object $value): array => MetricValueResource::toArray($value),
                $set->values,
            ),
        ];
    }
}
