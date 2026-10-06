<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/** One store the connection can read, as the platform describes it. */
final readonly class StoreRecord
{
    public function __construct(
        public string $externalId,
        public string $name,
        /** The store's reporting currency. Every amount from it is in this. */
        public string $currency,
        public ?string $domain = null,
        public ?string $timezone = null,
    ) {}
}
