<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/**
 * One line of an order, as it was sold.
 *
 * The name and price are the ones on the order, not the ones in today's catalogue. An item that
 * read the catalogue would rewrite last quarter's revenue whenever somebody changed a price.
 */
final readonly class OrderItemRecord
{
    public function __construct(
        public string $name,
        public int $quantity,
        public int $unitPriceMinor,
        public int $totalMinor,
        public string $currency,
        public ?string $externalId = null,
        public ?string $sku = null,
        public ?string $productExternalId = null,
        public ?string $variantExternalId = null,
        /** Positive: the amount taken off. Never a negative addition. */
        public int $discountMinor = 0,
    ) {}
}
