<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\EntityStatus;
use DateTimeImmutable;

/**
 * A campaign as an adapter reports it, already normalised.
 *
 * Carries both the normalised value and the provider's own string for status
 * and objective. The raw values are not decoration: when a campaign is "active"
 * but not delivering, the answer is in Meta's `effective_status`, and an
 * operator comparing our screen with the platform's needs to see the same word.
 */
final readonly class CampaignRecord
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $externalId,
        public string $name,
        public EntityStatus $status,
        public ?string $rawStatus = null,
        public ?string $rawEffectiveStatus = null,
        public CampaignObjective $objective = CampaignObjective::Other,
        public ?string $rawObjective = null,
        public BudgetMode $budgetMode = BudgetMode::Unknown,
        public ?int $budgetMinor = null,
        public ?int $spendCapMinor = null,
        public ?string $currency = null,
        public ?string $buyingType = null,
        public ?string $bidStrategy = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?DateTimeImmutable $providerCreatedAt = null,
        public ?DateTimeImmutable $providerUpdatedAt = null,
        public array $metadata = [],
    ) {}
}
