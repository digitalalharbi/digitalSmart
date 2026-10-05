<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Budget\Forecast\Confidence;
use App\Domain\Budget\Forecast\Projection;
use App\Domain\Budget\Forecast\RateCard;
use App\Domain\Integration\Provider;

/**
 * One platform's projection, with everything needed to argue with it.
 *
 * The rates, the window they came from, how many days of delivery are behind them, the
 * confidence that follows from that, and whatever could not be worked out. A projection
 * without those is a number somebody will quote back as a promise.
 */
final readonly class ForecastView
{
    /** @param list<string> $gaps */
    public function __construct(
        public Provider $provider,
        public ?Projection $projection,
        public RateCard $rates,
        public Confidence $confidence,
        public int $observedDays,
        public ?string $currency,
        /** Always `estimated` for a projection; the rates' own source is separate. */
        public MetricSource $source = MetricSource::Estimated,
        public MetricSource $rateSource = MetricSource::Live,
        public array $gaps = [],
        public ?string $unavailableReason = null,
    ) {}
}
