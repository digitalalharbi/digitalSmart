<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Analytics;

use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

/**
 * What can be asked for, and what each thing means.
 *
 * Served rather than duplicated in the client. A metric's label, format and
 * direction are domain facts; shipping a second copy of them in TypeScript is how
 * a dashboard ends up formatting CPA as a count on one screen and money on
 * another.
 */
final class MetricCatalogueController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'metrics' => array_map(static fn (Metric $metric): array => [
                    'value' => $metric->value,
                    'label' => $metric->label(),
                    'format' => $metric->format()->value,
                    'precision' => $metric->format()->precision(),
                    'is_derived' => $metric->isDerived(),
                    'is_monetary' => $metric->isMonetary(),
                    // False for reach, so the interface can explain why a figure
                    // disappears when more than one entity is selected.
                    'additive_across_entities' => $metric->isAdditiveAcrossEntities(),
                    'lower_is_better' => $metric->lowerIsBetter(),
                    'depends_on' => array_map(
                        static fn (Metric $dependency): string => $dependency->value,
                        $metric->dependsOn(),
                    ),
                ], Metric::cases()),

                'dimensions' => array_map(static fn (Dimension $dimension): array => [
                    'value' => $dimension->value,
                    'label' => $dimension->label(),
                    'level' => $dimension->level(),
                ], Dimension::cases()),

                'providers' => array_map(static fn (Provider $provider): array => [
                    'value' => $provider->value,
                    'label' => $provider->label(),
                ], Provider::cases()),

                /*
                 * So the client can render a source badge without hard-coding the
                 * vocabulary — and so a new source state cannot be added to the
                 * backend without the badge knowing about it.
                 */
                'sources' => array_map(static fn (MetricSource $source): array => [
                    'value' => $source->value,
                    'label' => $source->label(),
                    'requires_label' => $source->requiresLabel(),
                    'is_actual' => $source->isActual(),
                ], MetricSource::cases()),
            ],
        ]);
    }
}
