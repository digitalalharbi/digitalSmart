<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 * Where a budget sits in the governance hierarchy.
 *
 * Three states rather than a boolean, because "approved" is a commitment about
 * someone else's money: a draft is a proposal that can be reshaped freely, an
 * approved budget is the figure the client agreed to, and an archived one is kept
 * because the spend recorded against it still has to reconcile.
 */
enum BudgetStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether the budget's own amount and period may still be changed.
     *
     * An approved budget's total is not editable in place: changing the figure a
     * client agreed to, silently, is the one thing a budget record exists to prevent.
     * Allocations beneath it stay editable — that is how a plan adapts without the
     * commitment moving.
     */
    public function allowsAmountChange(): bool
    {
        return $this === self::Draft;
    }

    /** Whether allocations under this budget may still be reshaped. */
    public function allowsAllocationChange(): bool
    {
        return $this !== self::Archived;
    }

    /** Whether pacing against this budget means anything. */
    public function isPaced(): bool
    {
        return $this === self::Approved;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
