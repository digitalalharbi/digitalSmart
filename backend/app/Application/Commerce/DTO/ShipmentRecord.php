<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use DateTimeImmutable;

final readonly class ShipmentRecord
{
    public function __construct(
        public ?string $externalId = null,
        public ?string $carrier = null,
        public ?string $trackingNumber = null,
        public ?string $status = null,
        public ?DateTimeImmutable $shippedAt = null,
        public ?DateTimeImmutable $deliveredAt = null,
    ) {}
}
