<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use DateTimeImmutable;

/**
 * One answer about what a client's stores sold.
 *
 * The breakdowns are limited to a number of rows, so they do not add up to the summary — and that is why
 * the summary travels with them rather than being left for a caller to compute. A reader comparing the
 * two sees the difference as "the rest", which is the truth, instead of concluding the total is wrong.
 */
final readonly class StoreAnalyticsView
{
    /**
     * @param  list<RevenueBreakdownRow>  $byChannel
     * @param  list<RevenueBreakdownRow>  $byCampaign
     * @param  list<RevenueBreakdownRow>  $byProduct
     * @param  list<DailyRevenuePoint>  $daily
     */
    public function __construct(
        public StoreRevenueSummary $summary,
        public array $byChannel,
        public array $byCampaign,
        public array $byProduct,
        public array $daily,
        /**
         * How much of the period came from customers new to the store.
         *
         * Carried beside the totals rather than inside the summary, because its denominator is different: the
         * summary counts every order, and this counts only the orders this product can attach to a customer whose
         * history it can read. Two different populations should not share one object.
         */
        public CustomerMix $customerMix,
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
        /** Null where the audience was not given a trend of its own: the client portal reads the daily series. */
        public ?TrendSeries $series = null,
        /** Null unless the previous period was asked for. */
        public ?StoreComparison $comparison = null,
    ) {}
}
