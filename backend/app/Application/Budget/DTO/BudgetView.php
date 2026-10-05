<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Budget\BudgetStatus;
use DateTimeImmutable;

/** A budget as the application reports it, with its allocation tree. */
final readonly class BudgetView
{
    /** @param list<AllocationView> $allocations platform-level roots */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public string $name,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public int $amountMinor,
        public string $currency,
        public BudgetStatus $status,
        public ?string $projectId = null,
        public ?string $projectName = null,
        /** Internal. Stripped from anything a client can read. */
        public ?string $notes = null,
        public ?string $approvedByName = null,
        public ?DateTimeImmutable $approvedAt = null,
        public array $allocations = [],
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /** What the platform level claims between them. */
    public function allocatedMinor(): int
    {
        $total = 0;

        foreach ($this->allocations as $allocation) {
            $total += $allocation->amountMinor;
        }

        return $total;
    }

    public function unallocatedMinor(): int
    {
        return $this->amountMinor - $this->allocatedMinor();
    }
}
