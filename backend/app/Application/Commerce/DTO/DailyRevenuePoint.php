<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/** One day of a store revenue series. */
final readonly class DailyRevenuePoint
{
    public function __construct(
        /** `Y-m-d`, cut on the store's own day boundary. */
        public string $date,
        public int $orders,
        public int $netRevenueMinor,
        public int $refundedMinor,
        /** The window cut through this bucket, so it holds fewer days than its neighbours and must not be read as a fall. */
        public bool $isPartial = false,
    ) {}
}
