<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/**
 * One part of what an order total was made of, summed over the counted orders that reported it.
 *
 * `amountMinor` is null when no counted order reported this part. That is not a zero: a provider that does not publish a discount
 * has not said there was none. `ordersReporting` against `ordersCounted` is how a reader sees whether the figure covers the period
 * or only some of it.
 */
final readonly class LedgerComponent
{
    public function __construct(
        /** subtotal | discount | shipping | tax */
        public string $key,
        public ?int $amountMinor,
        public int $ordersReporting,
        public int $ordersCounted,
    ) {}
}
