<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

/**
 * What to forecast, in which direction.
 *
 * Both directions in one shape because they are the same arithmetic read from either
 * end: a budget to project forward from, or a target to work back from.
 */
final readonly class ForecastRequest
{
    public const string TARGET_CONVERSIONS = 'conversions';

    public const string TARGET_CLICKS = 'clicks';

    public const string TARGET_IMPRESSIONS = 'impressions';

    /** @param list<string> $providers empty means every provider with observed delivery */
    public function __construct(
        public array $providers = [],
        /** Budget → results. Minor units. */
        public ?int $budgetMinor = null,
        /** Results → budget. */
        public ?string $targetMetric = null,
        public ?float $targetValue = null,
        public int $windowDays = 90,
        public ?string $clientId = null,
        public ?string $projectId = null,
    ) {}

    public function isReverse(): bool
    {
        return $this->targetMetric !== null && $this->targetValue !== null;
    }
}
