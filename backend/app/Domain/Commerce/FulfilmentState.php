<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Where an order stands physically.
 *
 * The other half of an order's state, kept apart from payment for the reason given there. An
 * agency reads this axis to answer operational questions — how much is sitting unshipped — and the
 * payment axis to answer financial ones. A single status column would make both unanswerable.
 */
enum FulfilmentState: string
{
    case Unfulfilled = 'unfulfilled';

    case Processing = 'processing';

    case Shipped = 'shipped';

    case Delivered = 'delivered';

    /** Came back. Distinct from a refund, which is what may or may not follow. */
    case Returned = 'returned';

    case Cancelled = 'cancelled';

    /**
     * Whether this order is finished, one way or another.
     *
     * Used to decide what a sync still needs to re-read: an order that is delivered or cancelled
     * will not change again, while anything else can.
     */
    public function isSettled(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled || $this === self::Returned;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Not fulfilled',
            self::Processing => 'Being prepared',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Returned => 'Returned',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
