<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Application\Commerce\DTO\CustomerMix;
use App\Application\Commerce\DTO\DailyRevenuePoint;
use App\Application\Commerce\DTO\RevenueBreakdownRow;
use App\Application\Commerce\DTO\StoreRevenueSummary;
use App\Application\Commerce\DTO\StoreRollupRow;
use App\Domain\Commerce\RevenueSource;

/**
 * What a client's stores sold over the report's period, according to one authority.
 *
 * The summary is the store read model's own and nothing is recomputed: revenue is net of refunds, pending orders are
 * counted beside the total rather than in it, and the authority travels with every figure so a reader can say whose
 * claim it is.
 */
final readonly class StoreFigures
{
    public function __construct(
        public RevenueSource $revenueSource,
        public StoreRevenueSummary $summary,
        /**
         * The same authority's figures for the comparison period, or null.
         *
         * Null when no comparison was asked for or the periods cannot be compared — never a guess. It is what
         * "refund rate rose" and "orders improved" are read against, so without it neither is said.
         */
        public ?StoreRevenueSummary $previous = null,
        /** @var list<DailyRevenuePoint> days that had orders; a day without any is a gap, not a zero */
        public array $daily = [],
        /** @var list<StoreRollupRow> only when the client has more than one store, all in one currency */
        public array $stores = [],
        /** @var list<RevenueBreakdownRow> as sold, by name, before refunds */
        public array $products = [],
        /** @var list<RevenueBreakdownRow> what the orders carried, with the unattributed row */
        public array $channels = [],
        public ?CustomerMix $customerMix = null,
    ) {}

    /** @return list<StoreFigure> */
    public function figures(): array
    {
        $summary = $this->summary;

        return [
            new StoreFigure('net_revenue', 'Net revenue', 'money', $summary->netRevenueMinor, $summary->currency),
            new StoreFigure('orders', 'Orders', 'count', $summary->orders),
            new StoreFigure('aov', 'Average order value', 'money', $summary->averageOrderValueMinor(), $summary->currency),
            new StoreFigure('refunded', 'Refunded', 'money', $summary->refundedMinor, $summary->currency),
            new StoreFigure('customers', 'Customers', 'count', $summary->customers),
            new StoreFigure('pending_orders', 'Placed, not yet paid', 'count', $summary->pendingOrders),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->summary->orders === 0 && $this->summary->pendingOrders === 0;
    }
}
