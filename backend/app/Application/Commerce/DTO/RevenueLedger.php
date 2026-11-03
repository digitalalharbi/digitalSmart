<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/**
 * What the counted orders were charged, what came back, and what the totals were made of.
 *
 * `totalMinor` is the provider's own order total — what the customer was charged, including shipping and tax where the provider
 * includes them — and is the figure the summary already counts. The components are reported *beside* it, never subtracted from it:
 * shipping, tax and fees follow each provider's rules, so forcing "gross − discounts − refunds" into one equation would state a net
 * this product cannot defend. Where a provider reports all four parts, `reconciliation` says whether they add up to its total, and by
 * how much they do not.
 */
final readonly class RevenueLedger
{
    /** @param list<LedgerComponent> $components */
    public function __construct(
        public string $currency,
        public int $orders,
        public int $totalMinor,
        public int $refundedMinor,
        public array $components,
        /** Counted orders that reported all four parts, and so can be checked. */
        public int $ordersChecked,
        /** Of those, how many do not add up to their total. */
        public int $ordersOff,
        /** The sum of how far off they are, absolute, so opposite errors cannot cancel. Null when nothing could be checked. */
        public ?int $unexplainedMinor,
    ) {}

    public function netMinor(): int
    {
        return $this->totalMinor - $this->refundedMinor;
    }

    /** Null when no order reported enough to check: "nothing to reconcile" is not "reconciled". */
    public function reconciles(): ?bool
    {
        return $this->ordersChecked === 0 ? null : $this->ordersOff === 0;
    }
}
