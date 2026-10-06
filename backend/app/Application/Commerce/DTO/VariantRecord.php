<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

final readonly class VariantRecord
{
    public function __construct(
        public string $externalId,
        public ?string $name = null,
        public ?string $sku = null,
        public ?int $priceMinor = null,
        public ?string $currency = null,
        /** Null means the platform does not track stock for it, which is not zero stock. */
        public ?int $stock = null,
    ) {}
}
