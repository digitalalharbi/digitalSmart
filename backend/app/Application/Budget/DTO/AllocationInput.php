<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Budget\AllocationLevel;
use App\Domain\Integration\Provider;

/**
 * One node of a requested allocation tree.
 *
 * A tree rather than a flat list with parent ids, because the shape *is* the rule: a
 * campaign allocation nested under a platform allocation cannot accidentally be
 * reparented to the budget, and the sums are checked branch by branch on the way down.
 */
final readonly class AllocationInput
{
    /** @param list<self> $children */
    public function __construct(
        public AllocationLevel $level,
        public Provider $provider,
        public string $label,
        public int $amountMinor,
        public ?string $externalAccountId = null,
        public ?string $campaignId = null,
        public ?string $adGroupId = null,
        public array $children = [],
    ) {}

    /** The total this node's children claim. */
    public function childTotalMinor(): int
    {
        $total = 0;

        foreach ($this->children as $child) {
            $total += $child->amountMinor;
        }

        return $total;
    }
}
