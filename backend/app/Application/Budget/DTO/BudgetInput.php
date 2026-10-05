<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use DateTimeImmutable;

/** A budget as a request asked for it, already validated for shape. */
final readonly class BudgetInput
{
    public function __construct(
        public string $clientId,
        public string $name,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public int $amountMinor,
        public string $currency,
        public ?string $projectId = null,
        /** Internal. Never leaves the workspace — see docs/API.md. */
        public ?string $notes = null,
    ) {}
}
