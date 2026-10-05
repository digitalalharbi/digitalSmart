<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Budget;

use App\Application\Budget\DTO\BenchmarkSet;
use App\Application\Budget\DTO\ForecastView;
use App\Application\Budget\DTO\ProviderBenchmark;
use App\Domain\Budget\Forecast\Projection;
use App\Domain\Budget\Forecast\RateCard;

final readonly class ForecastResource
{
    /**
     * @param  list<ForecastView>  $forecasts
     * @return array<string, mixed>
     */
    public static function toArray(BenchmarkSet $benchmarks, array $forecasts): array
    {
        return [
            /*
             * The sample, published with the projection. A figure quoted in a meeting can
             * then be reproduced: the same window over the same scope gives the same
             * rates, and a different version means they have moved.
             */
            'benchmarks' => [
                'version' => $benchmarks->version(),
                'source' => 'This workspace’s own delivery. No industry averages are used.',
                'window_start' => $benchmarks->windowStart->format('Y-m-d'),
                'window_end' => $benchmarks->windowEnd->format('Y-m-d'),
                'client_id' => $benchmarks->clientId,
                'project_id' => $benchmarks->projectId,
                'providers' => array_map(
                    static fn (ProviderBenchmark $b): array => self::benchmark($b),
                    array_values($benchmarks->byProvider),
                ),
            ],
            'forecasts' => array_map(static fn (ForecastView $f): array => self::forecast($f), $forecasts),
            /*
             * Said in the payload rather than left to the interface to remember. Every
             * consumer of this endpoint gets the caveat, including the ones written later.
             */
            'disclaimer' => 'Estimates from this workspace’s own past delivery, not guarantees. '
                .'Actual results depend on auction conditions, creative and audience.',
        ];
    }

    /** @return array<string, mixed> */
    private static function benchmark(ProviderBenchmark $benchmark): array
    {
        return [
            'provider' => $benchmark->provider->value,
            'provider_label' => $benchmark->provider->label(),
            'observed_days' => $benchmark->observedDays,
            'currency' => $benchmark->currency,
            'confidence' => $benchmark->confidence->value,
            'confidence_label' => $benchmark->confidence->label(),
            // The rates' own honesty label, which is not the projection's.
            'source' => $benchmark->source->value,
            'spend_minor' => $benchmark->spendMinor,
            'impressions' => $benchmark->impressions,
            'clicks' => $benchmark->clicks,
            'conversions' => $benchmark->conversions,
            'rates' => self::rates($benchmark->rates),
            'unavailable_reason' => $benchmark->unavailableReason,
        ];
    }

    /** @return array<string, mixed> */
    private static function rates(RateCard $rates): array
    {
        return [
            'cpm_minor' => $rates->cpmMinor,
            'cpm_low_minor' => $rates->cpmLowMinor,
            'cpm_high_minor' => $rates->cpmHighMinor,
            'cpc_minor' => $rates->cpcMinor,
            'cpc_low_minor' => $rates->cpcLowMinor,
            'cpc_high_minor' => $rates->cpcHighMinor,
            'ctr' => $rates->ctr,
            'cvr' => $rates->cvr,
            'cvr_low' => $rates->cvrLow,
            'cvr_high' => $rates->cvrHigh,
            'cpa_minor' => $rates->cpaMinor,
        ];
    }

    /** @return array<string, mixed> */
    private static function forecast(ForecastView $forecast): array
    {
        return [
            'provider' => $forecast->provider->value,
            'provider_label' => $forecast->provider->label(),
            'confidence' => $forecast->confidence->value,
            'confidence_label' => $forecast->confidence->label(),
            'observed_days' => $forecast->observedDays,
            'currency' => $forecast->currency,
            // Always `estimated`: a projection is never a measurement.
            'source' => $forecast->source->value,
            'rate_source' => $forecast->rateSource->value,
            'projection' => $forecast->projection === null ? null : self::projection($forecast->projection),
            // What could not be worked out, named rather than left as empty fields.
            'gaps' => $forecast->gaps,
            'unavailable_reason' => $forecast->unavailableReason,
        ];
    }

    /** @return array<string, mixed> */
    private static function projection(Projection $projection): array
    {
        return [
            'impressions' => $projection->impressions,
            'impressions_low' => $projection->impressionsLow,
            'impressions_high' => $projection->impressionsHigh,
            'clicks' => $projection->clicks,
            'clicks_low' => $projection->clicksLow,
            'clicks_high' => $projection->clicksHigh,
            'conversions' => $projection->conversions,
            'conversions_low' => $projection->conversionsLow,
            'conversions_high' => $projection->conversionsHigh,
            'required_budget_minor' => $projection->requiredBudgetMinor,
            'required_budget_low_minor' => $projection->requiredBudgetLowMinor,
            'required_budget_high_minor' => $projection->requiredBudgetHighMinor,
            // Which observed rate each figure came from, in words.
            'assumptions' => $projection->assumptions,
        ];
    }
}
