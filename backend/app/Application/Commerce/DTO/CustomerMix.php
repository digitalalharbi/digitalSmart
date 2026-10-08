<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/**
 * How much of a period's revenue came from customers new to the store.
 *
 * Three buckets, and the third is why this is publishable. `unknown` counts customers whose first recorded order
 * sits at the edge of what was ever synced — they may have been buying for years, and a figure that silently
 * called them new would make every freshly connected store look like a wild success.
 *
 * `newShare()` returns **null** while the unknown bucket is large enough to change the answer. A share computed
 * over a denominator that is mostly "we cannot tell" is a number somebody will put in a report, and this product's
 * position is that a rate without a denominator worth quoting is not a rate.
 */
final readonly class CustomerMix
{
    /**
     * How much of the total may be unclassified before the share is withheld.
     *
     * A fifth, and **at** a fifth the share is already withheld rather than only above it. Below that the share
     * moves by less than the rounding somebody reads it at; from there on the unknown bucket is part of the answer
     * rather than a footnote to it, and "a fifth of this figure is guesswork" is not a figure to put in front of a
     * client. The boundary is pinned by its own test, because a reader will ask what happens exactly at a fifth.
     */
    private const float UNKNOWN_CEILING = 0.2;

    public function __construct(
        public int $newCustomers,
        public int $returningCustomers,
        public int $unknownCustomers,
        public int $newRevenueMinor,
        public int $returningRevenueMinor,
        public int $unknownRevenueMinor,
    ) {}

    public function totalCustomers(): int
    {
        return $this->newCustomers + $this->returningCustomers + $this->unknownCustomers;
    }

    public function totalRevenueMinor(): int
    {
        return $this->newRevenueMinor + $this->returningRevenueMinor + $this->unknownRevenueMinor;
    }

    /**
     * The share of revenue from new customers, or null where it cannot be defended.
     *
     * Null for two reasons, and they are different: nothing was sold at all, or too much of what was sold
     * belongs to customers this product cannot classify. The surface says which.
     */
    public function newShare(): ?float
    {
        $total = $this->totalRevenueMinor();

        if ($total <= 0) {
            return null;
        }

        if ($this->unknownRevenueMinor / $total >= self::UNKNOWN_CEILING) {
            return null;
        }

        return $this->newRevenueMinor / $total;
    }

    /** Why there is no share, in a sentence an interface can show. Null when there is one. */
    public function unavailableReason(): ?string
    {
        $total = $this->totalRevenueMinor();

        if ($total <= 0) {
            return 'Nothing was sold in this period, so there is no mix to report.';
        }

        if ($this->unknownRevenueMinor / $total >= self::UNKNOWN_CEILING) {
            return 'Too much of this period belongs to customers who were buying before this store was '
                .'connected, so a new-customer share would be measuring how long the integration has been '
                .'running rather than how the store is doing. It becomes available as the store accumulates '
                .'its own history.';
        }

        return null;
    }
}
