<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use DateTimeImmutable;

/**
 * One store's trading over a window, as a row in the agency-wide commerce view.
 *
 * Money stays in the store's own currency and rows are never totalled: two stores in two currencies have no
 * sum, and one in the same currency still belongs to two different clients.
 */
final readonly class StoreRollupRow
{
    public function __construct(
        public string $storeId,
        public string $storeName,
        public string $provider,
        public string $clientId,
        public string $clientName,
        public string $currency,
        public bool $isActive,
        public int $orders,
        public int $revenueMinor,
        public int $refundedMinor,
        public int $netRevenueMinor,
        /** Revenue ÷ orders, null when there are no orders — an average of nothing is not zero. */
        public ?int $averageOrderMinor,
        public int $attributedRevenueMinor,
        public ?DateTimeImmutable $lastSyncedAt,
    ) {}
}
