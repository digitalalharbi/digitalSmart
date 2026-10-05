<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Analytics;

use App\Application\Analytics\DTO\MetricValue;
use App\Domain\Analytics\MetricFormat;

/**
 * One figure, serialised with everything the interface needs to render it honestly.
 *
 * `value` is nullable and that nullability is the contract: the client must render
 * a missing figure as missing, with `unavailable_reason` explaining it, rather
 * than coercing it to zero. Money stays in **minor units** all the way to the
 * browser so no rounding happens in transit, and `currency` says how to format it.
 */
final readonly class MetricValueResource
{
    /** @return array<string, mixed> */
    public static function toArray(MetricValue $value): array
    {
        return [
            'metric' => $value->metric->value,
            'label' => $value->metric->label(),
            'value' => $value->value,
            'format' => $value->format->value,
            'precision' => $value->format->precision(),
            // Minor units for currency; a plain number otherwise. Never a string.
            'is_minor_units' => $value->format === MetricFormat::Currency,
            'currency' => $value->currency,
            'source' => $value->source->value,
            'source_label' => $value->source->label(),
            'requires_label' => $value->source->requiresLabel(),
            'unavailable_reason' => $value->unavailableReason,
            'comparison_value' => $value->comparisonValue,
            'change_ratio' => $value->changeRatio(),
            // Whether the change is good depends on the metric's direction: CPA
            // falling is good news, spend falling may not be.
            'change_is_good' => $value->changeIsGood(),
            'lower_is_better' => $value->metric->lowerIsBetter(),
        ];
    }
}
