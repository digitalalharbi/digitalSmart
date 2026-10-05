<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\EntityStatus;
use DateTimeImmutable;

/** Meta's ad set, Google's ad group, X's line item, LinkedIn's campaign. */
final readonly class AdGroupRecord
{
    /**
     * @param  array<string, mixed>  $targeting
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $externalId,
        public string $campaignExternalId,
        public string $name,
        public EntityStatus $status,
        public ?string $rawStatus = null,
        public ?string $rawEffectiveStatus = null,
        public ?string $optimizationGoal = null,
        public ?string $billingEvent = null,
        public ?int $bidAmountMinor = null,
        public BudgetMode $budgetMode = BudgetMode::Unknown,
        public ?int $budgetMinor = null,
        public ?string $currency = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?DateTimeImmutable $providerCreatedAt = null,
        public ?DateTimeImmutable $providerUpdatedAt = null,
        public array $targeting = [],
        public array $metadata = [],
    ) {}
}
