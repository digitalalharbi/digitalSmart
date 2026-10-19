<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\CampaignHealth;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\CampaignOwnership;
use App\Domain\Advertising\EntityStatus;
use DateTimeImmutable;

/**
 * One row of the Campaign Control Center.
 *
 * Carries both states deliberately. `status` is what the platform says; `health` is what this product
 * concluded, and rule 11 keeps them apart because a campaign that is ACTIVE on Meta and critical here is
 * exactly the row somebody is looking for.
 *
 * Every figure is nullable, and a null is never a zero. `results` being null means the provider reported
 * nothing; `roas` being null for a lead campaign means the question does not apply. The two reach the
 * interface as the same `—` and must not be the same value here.
 */
final readonly class CampaignControlRow
{
    /** @param list<int|null> $trend */
    public function __construct(
        public string $campaignId,
        public string $campaignName,
        public string $provider,
        public string $externalAccountId,
        public string $externalAccountName,
        public EntityStatus $status,
        /** The platform's own word, preserved. Rule 11: both exist, neither replaces the other. */
        public ?string $rawStatus,
        public CampaignObjective $objective,
        public CampaignHealth $health,
        public string $healthReason,
        public CampaignOwnership $ownership,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $clientId,
        public ?string $clientName,
        public ?string $currency,
        public ?int $budgetMinor,
        public ?int $spendMinor,
        /** The count this objective is judged by — purchases, leads, link clicks, reach. */
        public ?float $results,
        public string $resultLabel,
        /** Minor units per result, or null where the figure would be invented. */
        public ?float $costPerResultMinor,
        public ?int $revenueMinor,
        public ?float $roas,
        public int $reportedDays,
        public ?DateTimeImmutable $lastSyncedAt,
        /**
         * Average daily spend as a share of a *daily* budget; null for any other budget mode.
         * A lifetime budget has no daily line to measure against, and dividing by it would be invented.
         */
        public ?float $budgetUtilisation = null,
        /** Spend per day for the last fourteen days, oldest first. A null day is "not reported", never zero. */
        public array $trend = [],
        /** The health reason as a translatable code and its values — see `CampaignVerdict`. */
        public string $healthReasonCode = 'CAMPAIGN_UNKNOWN',
        /** @var array<string, int|string> */
        public array $healthReasonParams = [],
        /** When the platform says the campaign stops, where it has an end date at all. */
        public ?DateTimeImmutable $endsAt = null,
    ) {}

    /** @param list<int|null> $trend */
    public function withTrend(array $trend): self
    {
        return new self(
            $this->campaignId, $this->campaignName, $this->provider, $this->externalAccountId,
            $this->externalAccountName, $this->status, $this->rawStatus, $this->objective, $this->health,
            $this->healthReason, $this->ownership, $this->projectId, $this->projectName, $this->clientId,
            $this->clientName, $this->currency, $this->budgetMinor, $this->spendMinor, $this->results,
            $this->resultLabel, $this->costPerResultMinor, $this->revenueMinor, $this->roas,
            $this->reportedDays, $this->lastSyncedAt, $this->budgetUtilisation, $trend,
            $this->healthReasonCode, $this->healthReasonParams, $this->endsAt,
        );
    }
}
