<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\RevenueSource;

/**
 * What a client's stores sold over a period, according to one named authority.
 *
 * Every figure here is net of refunds and counts only orders whose payment state recognises revenue.
 * The two labels are not decoration: `revenueSource` says *who* is claiming this, and `source` says how
 * fresh and how complete the underlying rows are. A total without both is a number that cannot be
 * defended when a client's own dashboard disagrees with it — and it will.
 */
final readonly class StoreRevenueSummary
{
    public function __construct(
        public RevenueSource $revenueSource,
        public MetricSource $source,
        public string $currency,
        /** Orders that count towards revenue. Pending and voided ones are excluded. */
        public int $orders,
        /** Gross of refunds. */
        public int $revenueMinor,
        public int $refundedMinor,
        /** `revenueMinor` less `refundedMinor`. The figure a client should be shown. */
        public int $netRevenueMinor,
        public int $units,
        public int $customers,
        /** Orders placed but not yet paid for. Real orders, and not yet money. */
        public int $pendingOrders,
        public int $pendingRevenueMinor,
        /** How many of the counted orders carried any attribution evidence at all. */
        public int $attributedOrders,
        public int $attributedRevenueMinor,
        /** How many of the counted orders have had any refund against them. A refund is not a cancellation. */
        public int $refundedOrders = 0,
    ) {}

    /**
     * Average order value, or null.
     *
     * Null rather than zero when nothing counted. A denominator of zero has no average, and rendering
     * one as "0.00" invites a reader to compare it with last month's — which is the whole reason this
     * returns null instead.
     */
    public function averageOrderValueMinor(): ?int
    {
        return $this->orders > 0 ? intdiv($this->netRevenueMinor, $this->orders) : null;
    }

    /**
     * Refunded money as a share of gross revenue, 0–100, or null.
     *
     * Gross, because that is what the refunds were taken from. Null rather than zero where nothing was sold: a rate
     * over no sales is not a clean record.
     */
    public function refundRate(): ?float
    {
        return $this->revenueMinor > 0 ? round(($this->refundedMinor / $this->revenueMinor) * 100, 1) : null;
    }

    /**
     * The share of counted revenue that can be traced to marketing at all, 0–100, or null.
     *
     * Published so nobody reads a campaign breakdown without knowing how much of the total it covers. A
     * channel report over 30% coverage is a different document from one over 95%, and the figure that
     * tells them apart belongs beside it rather than in a footnote.
     */
    public function attributionCoverage(): ?float
    {
        if ($this->netRevenueMinor <= 0) {
            return null;
        }

        return round(($this->attributedRevenueMinor / $this->netRevenueMinor) * 100, 1);
    }
}
