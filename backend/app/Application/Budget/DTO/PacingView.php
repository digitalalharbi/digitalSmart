<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Budget\Pacing;

/**
 * A budget, how it is pacing, and what it is pacing against.
 *
 * The spend total is carried whole rather than reduced to a number, because the reason
 * a figure is missing matters as much as the figure: "no spend reported yet" and
 * "covers two currencies" lead to different actions, and both would otherwise render
 * as a zero.
 */
final readonly class PacingView
{
    /**
     * @param  list<AllocationPacingView>  $allocations
     * @param  list<array{date: string, spendMinor: int}>  $daily
     */
    public function __construct(
        public BudgetView $budget,
        public SpendTotal $spend,
        public ?Pacing $pacing,
        public array $allocations = [],
        public array $daily = [],
        /** Why there is no pacing, when there is none. */
        public ?string $unavailableReason = null,
    ) {}
}
