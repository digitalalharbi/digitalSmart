<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Integration\Provider;
use DateTimeImmutable;

/**
 * The rates available to a forecast, and the exact sample behind them.
 *
 * `version` is published with every projection so a figure quoted in a meeting can be
 * reproduced later: the same window over the same scope gives the same rates, and a
 * different version means the numbers have moved.
 */
final readonly class BenchmarkSet
{
    /** @param array<string, ProviderBenchmark> $byProvider keyed by provider value */
    public function __construct(
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
        public array $byProvider = [],
        public ?string $clientId = null,
        public ?string $projectId = null,
    ) {}

    public function for(Provider $provider): ?ProviderBenchmark
    {
        return $this->byProvider[$provider->value] ?? null;
    }

    /**
     * A stable identifier for this sample.
     *
     * The window, the scope and each provider's observed day count — everything that
     * would change the rates. Deliberately readable rather than a hash: an operator
     * comparing two forecasts can see *what* differs, not just that something did.
     */
    public function version(): string
    {
        $parts = [
            'own-data',
            $this->windowStart->format('Ymd').'-'.$this->windowEnd->format('Ymd'),
        ];

        if ($this->projectId !== null) {
            $parts[] = 'project';
        } elseif ($this->clientId !== null) {
            $parts[] = 'client';
        } else {
            $parts[] = 'workspace';
        }

        $samples = [];

        foreach ($this->byProvider as $value => $benchmark) {
            $samples[] = $value.':'.$benchmark->observedDays;
        }

        sort($samples);

        return implode('/', $parts).($samples === [] ? '/empty' : '/'.implode(',', $samples));
    }
}
