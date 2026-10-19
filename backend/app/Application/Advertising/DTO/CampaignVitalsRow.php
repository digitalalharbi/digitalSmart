<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\CampaignOwnership;
use App\Domain\Advertising\CampaignVitals;
use App\Domain\Advertising\EntityStatus;
use DateTimeImmutable;

/** Identity and measured facts for one campaign, before any judgement is applied. */
final readonly class CampaignVitalsRow
{
    public function __construct(
        public string $campaignId,
        public string $campaignName,
        public string $provider,
        public string $externalAccountId,
        public string $externalAccountName,
        public EntityStatus $status,
        public ?string $rawStatus,
        public CampaignObjective $objective,
        public CampaignOwnership $ownership,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $clientId,
        public ?string $clientName,
        public ?string $currency,
        public ?int $budgetMinor,
        public string $budgetMode,
        public CampaignVitals $vitals,
        public ?DateTimeImmutable $lastSyncedAt,
        public ?DateTimeImmutable $endsAt = null,
    ) {}
}
