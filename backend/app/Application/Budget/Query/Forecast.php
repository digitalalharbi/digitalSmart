<?php

declare(strict_types=1);

namespace App\Application\Budget\Query;

use App\Application\Budget\Contract\BenchmarkReadModel;
use App\Application\Budget\DTO\BenchmarkQuery;
use App\Application\Budget\DTO\BenchmarkSet;
use App\Application\Budget\DTO\ForecastRequest;
use App\Application\Budget\DTO\ForecastView;
use App\Application\Budget\DTO\ProviderBenchmark;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\MetricSource;
use App\Domain\Budget\Forecast\Confidence;
use App\Domain\Budget\Forecast\Projection;
use App\Domain\Budget\Forecast\RateCard;

/**
 * What a budget might buy, or what a target might cost.
 *
 * Both directions, from the workspace's own observed delivery and nothing else. The
 * rules this holds to, all of which cost the feature some apparent polish:
 *
 *   - **No rate, no projection.** A platform with too little delivery produces a reason
 *     rather than a figure. There is no industry benchmark to fall back on, because a
 *     rate nobody in this workspace earned cannot be defended to the client paying for
 *     it.
 *   - **Every figure is `estimated`.** The source label travels with the projection, so
 *     an estimate cannot be read as a measurement anywhere downstream.
 *   - **A band, not a point.** Each figure carries the range the account's own daily
 *     variation implies.
 *   - **The sample is published.** The window, the days of delivery behind it, and a
 *     version string, so a number quoted in a meeting can be reproduced later.
 *
 * Splitting a budget across platforms is the caller's decision, not this query's: it
 * forecasts each platform from the budget it is given, because inventing a split would
 * be a media plan disguised as arithmetic.
 */
final readonly class Forecast
{
    public function __construct(
        private BenchmarkReadModel $benchmarks,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /**
     * @return array{benchmarks: BenchmarkSet, forecasts: list<ForecastView>}
     */
    public function __invoke(ForecastRequest $request): array
    {
        /*
         * Read permission, not manage: trying numbers before asking anyone for money is
         * exactly what a calculator is for.
         */
        $this->guard->ensure(Permission::BudgetsView);

        $set = $this->benchmarks->observe(
            $this->actor->workspaceId()->value,
            new BenchmarkQuery(
                providers: $request->providers,
                days: $request->windowDays,
                clientId: $request->clientId,
                projectId: $request->projectId,
            ),
        );

        $forecasts = [];

        foreach ($set->byProvider as $benchmark) {
            $forecasts[] = $this->forecastFor($benchmark, $request);
        }

        return ['benchmarks' => $set, 'forecasts' => $forecasts];
    }

    private function forecastFor(ProviderBenchmark $benchmark, ForecastRequest $request): ForecastView
    {
        if (! $benchmark->confidence->allowsForecast()) {
            return new ForecastView(
                provider: $benchmark->provider,
                projection: null,
                rates: new RateCard,
                confidence: Confidence::None,
                observedDays: $benchmark->observedDays,
                currency: $benchmark->currency,
                rateSource: $benchmark->source,
                unavailableReason: $benchmark->unavailableReason
                    ?? 'Not enough observed delivery on this platform to estimate anything.',
            );
        }

        $projection = $request->isReverse()
            ? $this->reverse($request, $benchmark->rates)
            : Projection::fromBudget($request->budgetMinor ?? 0, $benchmark->rates);

        return new ForecastView(
            provider: $benchmark->provider,
            projection: $projection,
            rates: $benchmark->rates,
            confidence: $benchmark->confidence,
            observedDays: $benchmark->observedDays,
            currency: $benchmark->currency,
            // An estimate, always, whatever the rates behind it were.
            source: MetricSource::Estimated,
            rateSource: $benchmark->source,
            gaps: $projection->gaps,
        );
    }

    private function reverse(ForecastRequest $request, RateCard $rates): Projection
    {
        $target = $request->targetValue ?? 0.0;

        return match ($request->targetMetric) {
            ForecastRequest::TARGET_CLICKS => Projection::forTargetClicks((int) round($target), $rates),
            ForecastRequest::TARGET_IMPRESSIONS => Projection::forTargetImpressions((int) round($target), $rates),
            default => Projection::forTargetConversions($target, $rates),
        };
    }
}
