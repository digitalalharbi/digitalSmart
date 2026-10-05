<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Budget\AllocationLevel;
use App\Domain\Integration\Provider;

/** One allocation, with the branch beneath it. */
final readonly class AllocationView
{
    /** @param list<self> $children */
    public function __construct(
        public string $id,
        public AllocationLevel $level,
        public Provider $provider,
        public string $label,
        public int $amountMinor,
        public string $currency,
        public ?string $externalAccountId = null,
        public ?string $campaignId = null,
        public ?string $campaignName = null,
        public ?string $adGroupId = null,
        public ?string $adGroupName = null,
        public array $children = [],
    ) {}

    /** What the children of this allocation claim between them. */
    public function childTotalMinor(): int
    {
        $total = 0;

        foreach ($this->children as $child) {
            $total += $child->amountMinor;
        }

        return $total;
    }

    /**
     * The part of this allocation no child has claimed.
     *
     * Reported rather than hidden: an unallocated remainder is a decision not yet
     * made, and seeing it is how someone remembers to make it.
     */
    public function unallocatedMinor(): int
    {
        return $this->amountMinor - $this->childTotalMinor();
    }
}
