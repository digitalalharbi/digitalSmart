<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

final readonly class ExternalAccountView
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $providerLabel,
        public string $externalId,
        public string $name,
        public ?string $currency,
        public ?string $timezone,
        public string $status,
        public bool $isBindable,
        /** How many projects currently read this account. */
        public int $bindingCount,
        public ?string $discoveredAt,
    ) {}
}
