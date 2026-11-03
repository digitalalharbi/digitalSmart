<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/**
 * The same number of days immediately before the period, and what changed.
 *
 * A change against nothing has no percentage: "up from nothing" is a number somebody will quote. So when the period before has no
 * sales the change is absent and `reason` says which of two things is true — the store simply sold nothing then, or the period
 * predates the first order this product holds (the store was not being read), which are very different statements about the business.
 */
final readonly class StoreComparison
{
    public const string NOTHING_SOLD = 'nothing_sold_before';

    public const string BEFORE_THE_DATA = 'before_the_data';

    public function __construct(
        /** `Y-m-d` */
        public string $previousFrom,
        public string $previousUntil,
        public int $previousOrders,
        public int $previousNetRevenueMinor,
        /** Null when nothing counted: a denominator of zero has no average. */
        public ?int $previousAverageOrderValueMinor,
        /** Null when a comparison is possible. */
        public ?string $reason,
    ) {}

    /** @return array{orders_pct: float|null, net_revenue_pct: float|null, average_order_value_pct: float|null} */
    public function changeFrom(StoreRevenueSummary $current): array
    {
        if ($this->reason !== null) {
            return ['orders_pct' => null, 'net_revenue_pct' => null, 'average_order_value_pct' => null];
        }

        return [
            'orders_pct' => self::pct($current->orders, $this->previousOrders),
            'net_revenue_pct' => self::pct($current->netRevenueMinor, $this->previousNetRevenueMinor),
            'average_order_value_pct' => $current->averageOrderValueMinor() === null || $this->previousAverageOrderValueMinor === null
                ? null
                : self::pct($current->averageOrderValueMinor(), $this->previousAverageOrderValueMinor),
        ];
    }

    private static function pct(int $current, int $previous): ?float
    {
        return $previous <= 0 ? null : round((($current - $previous) / $previous) * 100, 1);
    }
}
