<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Budget\Forecast\Confidence;
use App\Domain\Budget\Forecast\RateCard;
use App\Domain\Integration\Provider;

/** One platform's observed rates, with the sample they came from. */
final readonly class ProviderBenchmark
{
    public function __construct(
        public Provider $provider,
        public RateCard $rates,
        public Confidence $confidence,
        /** Days that actually reported delivery, which is not the same as days in the window. */
        public int $observedDays,
        public ?string $currency,
        public MetricSource $source,
        public ?int $spendMinor = null,
        public ?int $impressions = null,
        public ?int $clicks = null,
        public ?float $conversions = null,
        /** Why no rates could be derived, when none could. */
        public ?string $unavailableReason = null,
    ) {}
}
