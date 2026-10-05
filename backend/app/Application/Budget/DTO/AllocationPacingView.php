<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Budget\Pacing;

/**
 * One allocation against the spend that landed inside it.
 *
 * Flat rather than nested: a pacing table is read by scanning for the row in trouble,
 * and nesting makes that a hunt. The level and parent label keep the hierarchy
 * legible without rebuilding it.
 */
final readonly class AllocationPacingView
{
    public function __construct(
        public AllocationView $allocation,
        public SpendTotal $spend,
        public ?Pacing $pacing,
        public ?string $parentLabel = null,
        /** Why this allocation cannot be compared with spend, when it cannot. */
        public ?string $unavailableReason = null,
    ) {}
}
