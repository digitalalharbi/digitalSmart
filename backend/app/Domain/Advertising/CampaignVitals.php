<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

use DateTimeImmutable;

/**
 * The facts a campaign's health is read from.
 *
 * Every count is nullable for the same reason the fact table's are: a provider that reported nothing and
 * a provider that reported zero are different statements, and conflating them turns a reporting gap into
 * a performance story. `conversions` being null means nobody told us; being `0.0` means none happened.
 */
final readonly class CampaignVitals
{
    public function __construct(
        public EntityStatus $status,
        public CampaignObjective $objective,
        /** Days inside the window on which the provider reported anything at all. */
        public int $reportedDays,
        public ?int $spendMinor,
        public ?float $results,
        public ?int $revenueMinor,
        /** The same two figures over the window immediately before this one, for comparison. */
        public ?int $previousSpendMinor,
        public ?float $previousResults,
        public ?DateTimeImmutable $lastSyncedAt,
    ) {}

    /** Cost per result, in minor units, or null when the question does not apply or cannot be answered. */
    public function costPerResult(): ?float
    {
        if (! $this->objective->hasCostPerResult()) {
            return null;
        }

        if ($this->spendMinor === null || $this->results === null || $this->results <= 0.0) {
            return null;
        }

        return $this->spendMinor / $this->results;
    }

    public function previousCostPerResult(): ?float
    {
        if (! $this->objective->hasCostPerResult()) {
            return null;
        }

        if ($this->previousSpendMinor === null
            || $this->previousResults === null
            || $this->previousResults <= 0.0) {
            return null;
        }

        return $this->previousSpendMinor / $this->previousResults;
    }

    /**
     * Return on ad spend, or null.
     *
     * Null for every objective that does not measure revenue, whatever is in the revenue column. A lead
     * campaign that happens to carry a revenue figure from a mis-mapped conversion must not be given a
     * return the campaign cannot support.
     */
    public function roas(): ?float
    {
        if (! $this->objective->measuresRevenue()) {
            return null;
        }

        if ($this->revenueMinor === null || $this->spendMinor === null || $this->spendMinor <= 0) {
            return null;
        }

        return $this->revenueMinor / $this->spendMinor;
    }
}
