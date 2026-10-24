<?php

declare(strict_types=1);

namespace App\Application\Saas\DTO;

use App\Domain\Saas\Feature;
use App\Domain\Saas\Limit;

/**
 * What one workspace has, after its plan and any override: the features it may use and the bound on each limit.
 *
 * `limits` holds every limit; `null` means not bounded. `featureSource` and `limitSource` say whether each value came from the
 * plan or from an override, so a page can show why.
 */
final readonly class Entitlements
{
    /**
     * @param  array<string, bool>  $features  feature value → enabled
     * @param  array<string, int|null>  $limits  limit value → bound
     * @param  array<string, string>  $featureSource  feature value → 'plan' | 'override'
     * @param  array<string, string>  $limitSource  limit value → 'plan' | 'override'
     */
    public function __construct(
        public string $planCode,
        public string $planName,
        public array $features,
        public array $limits,
        public array $featureSource,
        public array $limitSource,
    ) {}

    public function has(Feature $feature): bool
    {
        return $feature->isEverywhere() || ($this->features[$feature->value] ?? false);
    }

    public function limit(Limit $limit): ?int
    {
        return $this->limits[$limit->value] ?? null;
    }
}
