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
    ) {}
}
