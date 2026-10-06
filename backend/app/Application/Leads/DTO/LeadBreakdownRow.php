<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Commerce\AttributionEvidence;

/**
 * Leads grouped by campaign.
 *
 * Carries the **weakest** evidence in the group, as the revenue breakdowns do, and for the same reason: a
 * row is only as defensible as its flimsiest link. One vocabulary across leads and orders, so a
 * cross-surface report cannot compare two different standards of proof.
 */
final readonly class LeadBreakdownRow
{
    public function __construct(
        public string $label,
        public int $total,
        public int $qualified,
        public int $won,
        public int $closed,
        public ?AttributionEvidence $evidence = null,
    ) {}

    public function confidence(): ?int
    {
        return $this->evidence?->confidence();
    }

    public function isUnattributed(): bool
    {
        return $this->evidence === null || $this->evidence === AttributionEvidence::None;
    }

    public function winRate(): ?float
    {
        return $this->closed > 0 ? round(($this->won / $this->closed) * 100, 1) : null;
    }
}
