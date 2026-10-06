<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Leads\LeadSourceKind;

/** How many leads one route produced, and how they turned out. */
final readonly class LeadSourceRow
{
    public function __construct(
        public LeadSourceKind $sourceKind,
        public ?string $sourceDetail,
        public int $total,
        public int $qualified,
        public int $won,
        public int $closed,
        public int $wonValueMinor = 0,
    ) {}

    /** Wins over closed leads, or null. An open lead is not a loss. */
    public function winRate(): ?float
    {
        return $this->closed > 0 ? round(($this->won / $this->closed) * 100, 1) : null;
    }

    /**
     * Whether figures from this route were measured rather than entered.
     *
     * A manual entry is a real lead and must not be presented as a campaign result.
     */
    public function isMeasured(): bool
    {
        return $this->sourceKind->isMeasured();
    }
}
