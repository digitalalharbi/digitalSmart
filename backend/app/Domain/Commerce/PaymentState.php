<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Where an order stands financially.
 *
 * Deliberately **separate from fulfilment**. Every store platform has both axes, and conflating
 * them into one `status` column is the classic defect of this kind of integration: "delivered but
 * refunded" and "paid but not yet shipped" both become unrepresentable, and the first thing anybody
 * does with a single status is sum revenue by it.
 *
 * Normalised, because a report spanning two platforms cannot use either platform's vocabulary. The
 * mapping from a provider's own strings happens in that provider's adapter, where the provider's
 * documentation is the authority — never here, and never by guessing at a label.
 */
enum PaymentState: string
{
    /** No payment attempted or recorded. */
    case Unpaid = 'unpaid';

    /** Payment started and not settled: cash on delivery, a bank transfer awaiting confirmation. */
    case Pending = 'pending';

    /** Authorised but not captured. Money is promised, not taken. */
    case Authorised = 'authorised';

    case Paid = 'paid';

    case PartiallyRefunded = 'partially_refunded';

    case Refunded = 'refunded';

    /** Cancelled before capture, or charged back. */
    case Voided = 'voided';

    /**
     * Whether an order in this state contributes revenue.
     *
     * `PartiallyRefunded` counts, because the part that was kept is real revenue — the refund is
     * subtracted from the amount, not from the order's existence. `Pending` and `Authorised` do
     * **not** count: cash on delivery that never arrives is not a sale, and counting it would
     * overstate every report in the week before the courier returns.
     */
    public function recognisesRevenue(): bool
    {
        return $this === self::Paid || $this === self::PartiallyRefunded;
    }

    /** Whether money is expected but not yet received. The gap between an order and a sale. */
    public function isAwaitingPayment(): bool
    {
        return $this === self::Pending || $this === self::Authorised || $this === self::Unpaid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Pending => 'Payment pending',
            self::Authorised => 'Authorised',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Voided => 'Voided',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
