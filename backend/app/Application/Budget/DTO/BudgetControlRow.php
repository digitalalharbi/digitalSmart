<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Budget\Pacing;
use DateTimeImmutable;

/**
 * One approved budget as the finance surface reads it.
 *
 * `spentMinor` is null — never zero — when nothing was reported, or when the spend covers two currencies
 * or mixes sandbox with live and therefore cannot be totalled. `unavailableReason` says which.
 */
final readonly class BudgetControlRow
{
    /** @param list<string> $risks one or more of overspend|underpace|near_limit|unallocated|platform_over|campaign_over */
    public function __construct(
        public string $budgetId,
        public string $name,
        public string $clientId,
        public string $clientName,
        public ?string $projectId,
        public ?string $projectName,
        public string $currency,
        public int $approvedMinor,
        public int $allocatedMinor,
        public ?int $spentMinor,
        public ?Pacing $pacing,
        public ?string $unavailableReason,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public array $risks,
    ) {}

    public function remainingMinor(): ?int
    {
        return $this->spentMinor === null ? null : $this->approvedMinor - $this->spentMinor;
    }
}
