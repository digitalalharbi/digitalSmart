<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Application\Commerce\DTO\CustomerMix;
use App\Application\Commerce\DTO\DailyRevenuePoint;
use App\Application\Commerce\DTO\RevenueBreakdownRow;
use App\Application\Commerce\DTO\StoreQuery;
use App\Application\Commerce\DTO\StoreRevenueSummary;

/**
 * Reading what a client's stores sold.
 *
 * Four rules every implementation must honour, and all four exist because the alternative is a figure a
 * client acts on and nobody can defend:
 *
 *  - **Only orders whose payment state recognises revenue are counted.** A pending cash-on-delivery order
 *    is a real order and is not money. It is reported separately, never folded into the total.
 *  - **Revenue is net of refunds.** A month's sales with last month's returns subtracted is the figure a
 *    client is owed; gross is available beside it rather than instead of it.
 *  - **Unattributed revenue is its own row.** Never distributed across campaigns, never omitted so the
 *    percentages add to a hundred. It is usually the largest row, and hiding it is how a channel report
 *    comes to overstate every channel in it.
 *  - **One revenue source per answer.** A query names its authority and reads only orders from it. There
 *    is no method here that totals across sources, because there is no total across sources that means
 *    anything.
 */
interface StoreAnalyticsReadModel
{
    public function summary(StoreQuery $query): StoreRevenueSummary;

    /**
     * How much of the period came from customers new to the store.
     *
     * **New means their first recorded order falls inside the period.** No window, because the window only
     * appears if you ask a different question — "active recently" is reactivation, which has a real product
     * decision behind it and is deliberately not built.
     *
     * The third bucket is the one that makes this publishable: a customer whose first recorded order sits at the
     * edge of what was ever synced may have been buying for years, so they are counted separately rather than
     * called new. See `CustomerRecency` and `CustomerMix`.
     */
    public function customerMix(StoreQuery $query): CustomerMix;

    /**
     * Revenue by marketing channel, from what the orders carried.
     *
     * Grouped on source and medium, with everything that carried nothing in a single unattributed row.
     *
     * @return list<RevenueBreakdownRow>
     */
    public function byChannel(StoreQuery $query): array;

    /**
     * Revenue by campaign.
     *
     * **Only orders whose evidence can name a campaign appear in a named row.** An order carrying a bare
     * `utm_source` names a channel, not a campaign, and putting it in a campaign row would be inventing
     * the split. Those land in the unattributed row with everything else.
     *
     * @return list<RevenueBreakdownRow>
     */
    public function byCampaign(StoreQuery $query): array;

    /**
     * Revenue and units by product.
     *
     * Read from the order lines as they were sold, not from the catalogue as it reads today.
     *
     * @return list<RevenueBreakdownRow>
     */
    public function byProduct(StoreQuery $query): array;

    /**
     * A daily series.
     *
     * @return list<DailyRevenuePoint>
     */
    public function daily(StoreQuery $query): array;
}
