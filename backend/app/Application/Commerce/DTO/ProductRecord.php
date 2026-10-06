<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

final readonly class ProductRecord
{
    /** @param list<VariantRecord> $variants */
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $sku = null,
        public ?string $status = null,
        /** Minor units, or null where the platform reports no price. Never zero as a stand-in. */
        public ?int $priceMinor = null,
        public ?string $currency = null,
        public ?string $imageUrl = null,
        public array $variants = [],
    ) {}
}
