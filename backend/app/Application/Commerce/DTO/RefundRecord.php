<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use DateTimeImmutable;

final readonly class RefundRecord
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public DateTimeImmutable $refundedAt,
        public ?string $externalId = null,
        public ?string $reason = null,
    ) {}
}
