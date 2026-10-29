<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Commerce\Contract\StoreAnalyticsReadModel;
use App\Application\Commerce\DTO\CustomerMix;
use App\Application\Commerce\DTO\DailyRevenuePoint;
use App\Application\Commerce\DTO\LedgerComponent;
use App\Application\Commerce\DTO\RevenueBreakdownRow;
use App\Application\Commerce\DTO\RevenueLedger;
use App\Application\Commerce\DTO\StoreComparison;
use App\Application\Commerce\DTO\StoreQuery;
use App\Application\Commerce\DTO\StoreRevenueSummary;
use App\Application\Commerce\DTO\StoreRollupRow;
use App\Application\Commerce\DTO\TrendSeries;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Commerce\CustomerRecency;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\TrendGrain;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a client's stores sold, read in SQL.
 *
 * The honesty rules of this area are expressed as query predicates rather than as filtering afterwards,
 * because a predicate cannot be forgotten by the next caller:
 *
 *  - **`payment_state IN ('paid', 'partially_refunded')`** on every revenue figure. A pending
 *    cash-on-delivery order is a real order and is not money, and in this market there are a lot of them.
 *  - **`total_minor - refunded_minor`** for net revenue, in the database, so no caller can total the gross
 *    column by accident.
 *  - **`revenue_source = ?`** on every query. One authority per answer; there is no cross-source total
 *    because there is no cross-source total that means anything.
 *  - **Unattributed rows are produced, not dropped.** The honest bucket is usually the largest, and a
 *    breakdown that omitted it would make every other row look bigger than it is.
 */
final readonly class EloquentStoreAnalyticsReadModel implements StoreAnalyticsReadModel
{
    /** The payment states that are money. Mirrors `PaymentState::recognisesRevenue()`. */
    private const array REVENUE_STATES = [
        PaymentState::Paid->value,
        PaymentState::PartiallyRefunded->value,
    ];

    /** The states where money is expected and has not arrived. */
    private const array PENDING_STATES = [
        PaymentState::Pending->value,
        PaymentState::Authorised->value,
        PaymentState::Unpaid->value,
    ];

    public function summary(StoreQuery $query): StoreRevenueSummary
    {
        $counted = $this->scope($query)->whereIn('o.payment_state', self::REVENUE_STATES);

        /** @var object{orders: int|string|null, revenue: int|string|null, refunded: int|string|null, customers: int|string|null, refunded_orders: int|string|null, attributed_orders: int|string|null, attributed_revenue: int|string|null}|null $totals */
        $totals = (clone $counted)->selectRaw(
            'count(*) as orders, '
            .'coalesce(sum(o.total_minor), 0) as revenue, '
            .'coalesce(sum(o.refunded_minor), 0) as refunded, '
            .'count(distinct o.store_customer_id) as customers, '
            .'count(*) filter (where o.refunded_minor > 0) as refunded_orders, '
            /*
             * Attributed means "carried something", which is a weaker claim than "names a campaign" and
             * the right one for a coverage figure: it answers "how much of this total can be traced to
             * marketing at all".
             */
            ."count(*) filter (where o.attribution_evidence <> 'none') as attributed_orders, "
            ."coalesce(sum(o.total_minor - o.refunded_minor) filter (where o.attribution_evidence <> 'none'), 0) as attributed_revenue"
        )->first();

        /** @var object{orders: int|string|null, revenue: int|string|null}|null $pending */
        $pending = $this->scope($query)
            ->whereIn('o.payment_state', self::PENDING_STATES)
            ->selectRaw('count(*) as orders, coalesce(sum(o.total_minor), 0) as revenue')
            ->first();

        /** @var object{units: int|string|null}|null $units */
        $units = (clone $counted)
            ->join('store_order_items as i', 'i.store_order_id', '=', 'o.id')
            ->selectRaw('coalesce(sum(i.quantity), 0) as units')
            ->first();

        $revenue = (int) ($totals->revenue ?? 0);
        $refunded = (int) ($totals->refunded ?? 0);

        return new StoreRevenueSummary(
            revenueSource: $query->revenueSource,
            source: $this->weakestSource($query),
            currency: $this->currencyFor($query),
            orders: (int) ($totals->orders ?? 0),
            revenueMinor: $revenue,
            refundedMinor: $refunded,
            // Computed here from two sums rather than trusted from one column, so the two always agree.
            netRevenueMinor: $revenue - $refunded,
            units: (int) ($units->units ?? 0),
            customers: (int) ($totals->customers ?? 0),
            pendingOrders: (int) ($pending->orders ?? 0),
            pendingRevenueMinor: (int) ($pending->revenue ?? 0),
            attributedOrders: (int) ($totals->attributed_orders ?? 0),
            attributedRevenueMinor: (int) ($totals->attributed_revenue ?? 0),
            refundedOrders: (int) ($totals->refunded_orders ?? 0),
        );
    }

    /** @return list<StoreRollupRow> */
    public function byStore(StoreQuery $query): array
    {
        $stores = DB::table('stores as s')
            ->join('clients as c', 'c.id', '=', 's.client_id')
            ->where('s.workspace_id', $query->workspaceId);

        if ($query->clientId !== null) {
            $stores->where('s.client_id', $query->clientId);
        }

        if ($query->visibleProjectIds !== null) {
            if ($query->visibleProjectIds === []) {
                return [];
            }

            $stores->whereIn('s.client_id', static function (Builder $sub) use ($query): void {
                $sub->select('client_id')->from('projects')
                    ->where('workspace_id', $query->workspaceId)->whereIn('id', $query->visibleProjectIds ?? []);
            });
        }

        $sales = DB::table('store_orders as o')
            ->join('stores as os', 'os.id', '=', 'o.store_id')
            ->where('o.workspace_id', $query->workspaceId)
            ->where('o.revenue_source', $query->revenueSource->value);
        $this->withinPeriod($sales, $query, 'os');
        $sales->whereIn('o.payment_state', self::REVENUE_STATES)
            ->groupBy('o.store_id')
            ->selectRaw(
                'o.store_id, count(*) as orders, coalesce(sum(o.total_minor), 0) as revenue, '
                .'coalesce(sum(o.refunded_minor), 0) as refunded, '
                ."coalesce(sum(o.total_minor - o.refunded_minor) filter (where o.attribution_evidence <> 'none'), 0) as attributed",
            );

        $rows = $stores->leftJoinSub($sales, 't', 't.store_id', '=', 's.id')
            ->orderByRaw('coalesce(t.revenue, 0) desc')->orderBy('s.name')
            ->limit($query->limit)
            ->get(['s.id', 's.name', 's.provider', 's.client_id', 'c.name as client_name', 's.currency', 's.is_active', 's.last_synced_at',
                't.orders', 't.revenue', 't.refunded', 't.attributed']);

        $out = [];

        foreach ($rows as $r) {
            $orders = (int) $r->orders;
            $revenue = (int) $r->revenue;
            $refunded = (int) $r->refunded;
            $out[] = new StoreRollupRow(
                storeId: (string) $r->id,
                storeName: (string) $r->name,
                provider: (string) $r->provider,
                clientId: (string) $r->client_id,
                clientName: (string) $r->client_name,
                currency: (string) $r->currency,
                isActive: (bool) $r->is_active,
                orders: $orders,
                revenueMinor: $revenue,
                refundedMinor: $refunded,
                netRevenueMinor: $revenue - $refunded,
                averageOrderMinor: $orders > 0 ? intdiv($revenue, $orders) : null,
                attributedRevenueMinor: (int) $r->attributed,
                lastSyncedAt: is_string($r->last_synced_at) ? new DateTimeImmutable($r->last_synced_at) : null,
            );
        }

        return $out;
    }

    /** @return list<RevenueBreakdownRow> */
    public function byChannel(StoreQuery $query): array
    {
        $rows = $this->scope($query)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->selectRaw(
                /*
                 * Aliased `channel_*` rather than `source`, which would collide with the orders table's
                 * own `source` column — the honesty label. PostgreSQL resolves a GROUP BY name to the
                 * real column in preference to the alias, so the shorter name silently grouped by the
                 * wrong thing and the query failed. Worth the longer name.
                 */
                "coalesce(nullif(o.utm_source, ''), '') as channel_source, "
                ."coalesce(nullif(o.utm_medium, ''), '') as channel_medium, "
                .'count(*) as orders, '
                .'coalesce(sum(o.total_minor), 0) as revenue, '
                .'coalesce(sum(o.refunded_minor), 0) as refunded, '
                /*
                 * The weakest evidence in the group. The rank is `100 - confidence`, so the weakest link is
                 * the **highest** rank — `max`, not `min`. Getting that backwards labels every group with
                 * its strongest evidence, which is exactly the flattering this figure must not do, and it
                 * would be invisible on the page.
                 *
                 * The ranking is built from the enum rather than by sorting the column as text, which would
                 * put `coupon_code` above `utm_parameters` alphabetically.
                 */
                .'max('.$this->evidenceRankExpression().') as weakest_rank'
            )
            ->groupBy('channel_source', 'channel_medium')
            ->orderByDesc(DB::raw('sum(o.total_minor - o.refunded_minor)'))
            ->limit($query->limit)
            ->get();

        $breakdown = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $source = (string) ($values['channel_source'] ?? '');
            $medium = (string) ($values['channel_medium'] ?? '');

            $breakdown[] = $this->row(
                label: $this->channelLabel($source, $medium),
                values: $values,
            );
        }

        return $breakdown;
    }

    /** @return list<RevenueBreakdownRow> */
    public function byCampaign(StoreQuery $query): array
    {
        $named = $this->scope($query)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            /*
             * Only evidence that can name a campaign. An order carrying a bare `utm_source` names a
             * channel, and putting it in a campaign row would be inventing the split — the exact thing
             * the attribution rules exist to prevent.
             */
            ->whereIn('o.attribution_evidence', $this->campaignNamingEvidence())
            ->whereRaw($this->campaignNameExpression().' IS NOT NULL')
            ->selectRaw(
                /*
                 * A coupon-only order has no `utm_campaign`; the campaign it names is the code somebody
                 * minted for it, which is why `OrderAttribution::campaignName()` falls back to it. The same
                 * fallback has to happen here or those orders would vanish from the breakdown entirely —
                 * silently, because they would simply be absent rather than wrong.
                 */
                $this->campaignNameExpression().' as campaign, '
                .'count(*) as orders, '
                .'coalesce(sum(o.total_minor), 0) as revenue, '
                .'coalesce(sum(o.refunded_minor), 0) as refunded, '
                .'max('.$this->evidenceRankExpression().') as weakest_rank'
            )
            ->groupBy('campaign')
            ->orderByDesc(DB::raw('sum(o.total_minor - o.refunded_minor)'))
            ->limit($query->limit)
            ->get();

        $rows = [];

        foreach ($named as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $rows[] = $this->row((string) ($values['campaign'] ?? ''), $values);
        }

        /*
         * Everything a campaign cannot be named for, as one row. Produced deliberately and last: it is
         * usually the largest, and a report that omitted it so the percentages added to a hundred would
         * overstate every campaign above it.
         */
        $rest = $this->unnamedCampaignRow($query);

        if ($rest !== null) {
            $rows[] = $rest;
        }

        return $rows;
    }

    /** @return list<RevenueBreakdownRow> */
    public function byProduct(StoreQuery $query): array
    {
        $rows = $this->scope($query)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->join('store_order_items as i', 'i.store_order_id', '=', 'o.id')
            ->selectRaw(
                /*
                 * Grouped on the name as sold rather than on the product id, because a line whose product
                 * has since been deleted still has a name and still sold. Grouping on the id would drop
                 * those into a single null row and quietly understate the product that caused it.
                 */
                'i.name as label, '
                .'max(i.store_product_id::text) as product_id, '
                .'count(distinct o.id) as orders, '
                .'coalesce(sum(i.total_minor), 0) as revenue, '
                .'coalesce(sum(i.quantity), 0) as units'
            )
            ->groupBy('i.name')
            ->orderByDesc(DB::raw('sum(i.total_minor)'))
            ->limit($query->limit)
            ->get();

        $breakdown = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $revenue = (int) ($values['revenue'] ?? 0);

            $breakdown[] = new RevenueBreakdownRow(
                label: (string) ($values['label'] ?? ''),
                orders: (int) ($values['orders'] ?? 0),
                revenueMinor: $revenue,
                /*
                 * A line-level refund is not recorded: a refund is against the order, and splitting it
                 * across lines would be a guess at which item came back. Reported as zero here and
                 * visible on the summary, where it is a fact rather than an allocation.
                 */
                refundedMinor: 0,
                netRevenueMinor: $revenue,
                units: (int) ($values['units'] ?? 0),
                externalId: isset($values['product_id']) && is_string($values['product_id'])
                    ? $values['product_id']
                    : null,
            );
        }

        return $breakdown;
    }

    /** @return list<DailyRevenuePoint> */
    public function daily(StoreQuery $query): array
    {
        $rows = $this->scope($query)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->selectRaw(
                'to_char('.self::localDay('o.placed_at').", 'YYYY-MM-DD') as date, "
                .'count(*) as orders, '
                .'coalesce(sum(o.total_minor - o.refunded_minor), 0) as net_revenue, '
                .'coalesce(sum(o.refunded_minor), 0) as refunded'
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $points = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $points[] = new DailyRevenuePoint(
                date: (string) ($values['date'] ?? ''),
                orders: (int) ($values['orders'] ?? 0),
                netRevenueMinor: (int) ($values['net_revenue'] ?? 0),
                refundedMinor: (int) ($values['refunded'] ?? 0),
            );
        }

        return $points;
    }

    public function series(StoreQuery $query): TrendSeries
    {
        $day = self::localDay('o.placed_at');
        $bucket = match ($query->grain) {
            TrendGrain::Day => $day,
            // ISO weeks, beginning on Monday, cut in the store's own calendar like everything else.
            TrendGrain::Week => "date_trunc('week', {$day})::date",
            TrendGrain::Month => "date_trunc('month', {$day})::date",
        };

        $rows = $this->scope($query)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->selectRaw(
                "to_char({$bucket}, 'YYYY-MM-DD') as bucket, "
                .'count(*) as orders, '
                .'coalesce(sum(o.total_minor - o.refunded_minor), 0) as net_revenue, '
                .'coalesce(sum(o.refunded_minor), 0) as refunded'
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $from = $query->from->format('Y-m-d');
        $until = $query->until->format('Y-m-d');
        $points = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $start = (string) ($values['bucket'] ?? '');
            $last = match ($query->grain) {
                TrendGrain::Day => $start,
                TrendGrain::Week => (new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d'),
                TrendGrain::Month => (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d'),
            };

            $points[] = new DailyRevenuePoint(
                date: $start,
                orders: (int) ($values['orders'] ?? 0),
                netRevenueMinor: (int) ($values['net_revenue'] ?? 0),
                refundedMinor: (int) ($values['refunded'] ?? 0),
                // A day is never partial. A week or month is, when the period begins after its first day or ends before its last.
                isPartial: $query->grain !== TrendGrain::Day && ($start < $from || $last > $until),
            );
        }

        return new TrendSeries($query->grain, $points);
    }

    public function comparison(StoreQuery $query): StoreComparison
    {
        $first = $query->from->setTime(0, 0);
        $days = (int) $first->diff($query->until->setTime(0, 0))->days + 1;
        $previousUntil = $first->modify('-1 day');
        $previousFrom = $previousUntil->modify('-'.($days - 1).' days');

        $previous = new StoreQuery(
            workspaceId: $query->workspaceId,
            revenueSource: $query->revenueSource,
            from: $previousFrom,
            until: $previousUntil->setTime(23, 59, 59),
            visibleProjectIds: $query->visibleProjectIds,
            clientId: $query->clientId,
            storeId: $query->storeId,
        );

        /** @var object{orders: int|string|null, net: int|string|null}|null $totals */
        $totals = $this->scope($previous)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->selectRaw('count(*) as orders, coalesce(sum(o.total_minor - o.refunded_minor), 0) as net')
            ->first();
        $orders = (int) ($totals->orders ?? 0);
        $net = (int) ($totals->net ?? 0);

        $reason = null;

        if ($orders === 0) {
            // Nothing in the period before: either the store sold nothing then, or it was not being read yet. Different statements.
            $history = new StoreQuery(
                workspaceId: $query->workspaceId,
                revenueSource: $query->revenueSource,
                from: new DateTimeImmutable('1970-01-01'),
                until: $previousUntil->setTime(23, 59, 59),
                visibleProjectIds: $query->visibleProjectIds,
                clientId: $query->clientId,
                storeId: $query->storeId,
            );

            $reason = $this->scope($history)->whereIn('o.payment_state', self::REVENUE_STATES)->exists()
                ? StoreComparison::NOTHING_SOLD
                : StoreComparison::BEFORE_THE_DATA;
        }

        return new StoreComparison(
            previousFrom: $previousFrom->format('Y-m-d'),
            previousUntil: $previousUntil->format('Y-m-d'),
            previousOrders: $orders,
            previousNetRevenueMinor: $net,
            previousAverageOrderValueMinor: $orders > 0 ? intdiv($net, $orders) : null,
            reason: $reason,
        );
    }

    public function ledger(StoreQuery $query): RevenueLedger
    {
        $parts = ['subtotal', 'discount', 'shipping', 'tax'];
        $all = implode(' and ', array_map(static fn (string $p): string => "o.{$p}_minor is not null", $parts));
        $off = 'o.total_minor - (o.subtotal_minor - o.discount_minor + o.shipping_minor + o.tax_minor)';

        /*
         * `count(column)` counts non-null values, which is exactly "how many orders reported this part". A part nobody reported sums
         * to null, not zero. The reconciliation looks only at orders that reported all four: an order missing one cannot be checked,
         * and counting it as exact would make a sparse provider look like a clean one.
         */
        $select = ['count(*) as orders', 'coalesce(sum(o.total_minor), 0) as total', 'coalesce(sum(o.refunded_minor), 0) as refunded'];

        foreach ($parts as $part) {
            $select[] = "sum(o.{$part}_minor) as {$part}_sum";
            $select[] = "count(o.{$part}_minor) as {$part}_n";
        }

        $select[] = "count(*) filter (where {$all}) as checked";
        $select[] = "count(*) filter (where {$all} and ({$off}) <> 0) as off";
        $select[] = "sum(abs({$off})) filter (where {$all}) as unexplained";

        /** @var object|null $row */
        $row = $this->scope($query)->whereIn('o.payment_state', self::REVENUE_STATES)->selectRaw(implode(', ', $select))->first();
        $values = (array) $row;
        $orders = (int) ($values['orders'] ?? 0);
        $checked = (int) ($values['checked'] ?? 0);

        return new RevenueLedger(
            currency: $this->currencyFor($query),
            orders: $orders,
            totalMinor: (int) ($values['total'] ?? 0),
            refundedMinor: (int) ($values['refunded'] ?? 0),
            components: array_map(static function (string $part) use ($values, $orders): LedgerComponent {
                $reporting = (int) ($values["{$part}_n"] ?? 0);

                return new LedgerComponent($part, $reporting > 0 ? (int) ($values["{$part}_sum"] ?? 0) : null, $reporting, $orders);
            }, $parts),
            ordersChecked: $checked,
            ordersOff: (int) ($values['off'] ?? 0),
            unexplainedMinor: $checked > 0 ? (int) ($values['unexplained'] ?? 0) : null,
        );
    }

    public function customerMix(StoreQuery $query): CustomerMix
    {
        /*
         * The earliest order this product holds for the stores in scope. Everything hangs off it: a customer whose
         * first recorded order is *at* that boundary may have been buying for years before the integration
         * existed, and calling them new would make every freshly connected store look like a triumph.
         *
         * Read from the same scope rather than from the stores table, because what matters is the earliest order
         * actually synced and not when somebody created the connection.
         */
        $earliest = DB::table('store_orders as o')
            ->join('stores as s', 's.id', '=', 'o.store_id')
            ->where('o.workspace_id', $query->workspaceId)
            ->where('o.revenue_source', $query->revenueSource->value)
            ->when($query->clientId !== null, fn (Builder $b): Builder => $b->where('s.client_id', $query->clientId))
            ->when($query->storeId !== null, fn (Builder $b): Builder => $b->where('o.store_id', $query->storeId))
            ->min('o.placed_at');

        $counted = $this->scope($query)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            /*
             * An order with no customer record cannot be classified at all — a guest checkout the provider did not
             * attach to anybody. Left out of the mix rather than bucketed, because "we have no customer here" is
             * not the same claim as "we cannot tell when this customer started".
             */
            ->whereNotNull('o.store_customer_id')
            ->join('store_customers as c', 'c.id', '=', 'o.store_customer_id');

        /*
         * Grouped in SQL by the three cases, so the classification is one predicate rather than a loop over rows
         * that a later caller could filter differently. `first_order_at` is nullable — a customer record a
         * provider sent without one — and that is unknown too.
         */
        $boundary = is_string($earliest) ? $earliest : null;

        $rows = (clone $counted)
            ->selectRaw(
                'case '
                .'when c.first_order_at is null then ? '
                /*
                 * At or before the earliest order this product ever read. `<=` rather than `<`: a customer whose
                 * first recorded order *is* the earliest order in the data is exactly the ambiguous case.
                 */
                .($boundary === null ? '' : 'when c.first_order_at <= ? then ? ')
                .'when '.self::localDay('c.first_order_at').' >= ?::date then ? '
                .'else ? end as recency, '
                .'count(distinct o.store_customer_id) as customers, '
                .'coalesce(sum(o.total_minor - o.refunded_minor), 0) as revenue',
                $boundary === null
                    ? [
                        CustomerRecency::Unknown->value,
                        $query->from->format('Y-m-d'),
                        CustomerRecency::New->value,
                        CustomerRecency::Returning->value,
                    ]
                    : [
                        CustomerRecency::Unknown->value,
                        $boundary,
                        CustomerRecency::Unknown->value,
                        $query->from->format('Y-m-d'),
                        CustomerRecency::New->value,
                        CustomerRecency::Returning->value,
                    ],
            )
            ->groupBy('recency')
            ->get();

        $customers = [];
        $revenue = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $key = (string) ($values['recency'] ?? '');

            $customers[$key] = (int) ($values['customers'] ?? 0);
            $revenue[$key] = (int) ($values['revenue'] ?? 0);
        }

        return new CustomerMix(
            newCustomers: $customers[CustomerRecency::New->value] ?? 0,
            returningCustomers: $customers[CustomerRecency::Returning->value] ?? 0,
            unknownCustomers: $customers[CustomerRecency::Unknown->value] ?? 0,
            newRevenueMinor: $revenue[CustomerRecency::New->value] ?? 0,
            returningRevenueMinor: $revenue[CustomerRecency::Returning->value] ?? 0,
            unknownRevenueMinor: $revenue[CustomerRecency::Unknown->value] ?? 0,
        );
    }

    /**
     * A stored instant (UTC) as the calendar day it falls on in its store's own time zone.
     *
     * `stores.timezone` is validated where it is written (`EloquentStoreDirectory`), so it is a real zone name or null; null is UTC.
     * `$column` is always one of this class's own column names, never request input.
     */
    private static function localDay(string $column): string
    {
        return "(({$column} AT TIME ZONE 'UTC') AT TIME ZONE coalesce(s.timezone, 'UTC'))::date";
    }

    /**
     * Keep only orders placed on a day inside the period **in the store's own calendar**.
     *
     * The period is two dates. For a Riyadh store 2 October runs from 21:00 UTC on 1 October, so cutting at UTC midnight put the first
     * three hours of every day on the day before. The coarse bounds beside the exact predicate are only for the index on `placed_at`:
     * no zone is further than 14 hours from UTC, so a day either side of the period can never hide a matching order.
     *
     * @param  string  $stores  the alias of the stores table the zone is read from
     */
    private function withinPeriod(Builder $builder, StoreQuery $query, string $stores = 's'): Builder
    {
        $local = str_replace('s.timezone', "{$stores}.timezone", self::localDay('o.placed_at'));

        return $builder
            ->where('o.placed_at', '>=', $query->from->modify('-1 day'))
            ->where('o.placed_at', '<=', $query->until->modify('+1 day'))
            ->whereRaw("{$local} between ?::date and ?::date", [$query->from->format('Y-m-d'), $query->until->format('Y-m-d')]);
    }

    /**
     * The base query: this workspace, this authority, this window, these stores.
     *
     * Visibility is applied in SQL rather than by filtering results, so a store outside the actor's
     * reach is never in the set to begin with.
     */
    private function scope(StoreQuery $query): Builder
    {
        $builder = DB::table('store_orders as o')
            ->join('stores as s', 's.id', '=', 'o.store_id')
            ->where('o.workspace_id', $query->workspaceId)
            // One authority per answer. Without this a Salla figure and a Zid figure would sum.
            ->where('o.revenue_source', $query->revenueSource->value);
        $this->withinPeriod($builder, $query);

        if ($query->clientId !== null) {
            $builder->where('s.client_id', $query->clientId);
        }

        if ($query->storeId !== null) {
            $builder->where('o.store_id', $query->storeId);
        }

        if ($query->visibleProjectIds !== null) {
            if ($query->visibleProjectIds === []) {
                return $builder->whereRaw('1 = 0');
            }

            /*
             * A store belongs to a client, and a person reaches a client through a project. So the
             * visible set is the clients of the actor's projects — the same rule the measurement sources
             * use, expressed the same way so the two surfaces cannot disagree about who can see what.
             */
            $builder->whereIn('s.client_id', function (Builder $sub) use ($query): void {
                $sub->select('client_id')
                    ->from('projects')
                    ->where('workspace_id', $query->workspaceId)
                    ->whereIn('id', $query->visibleProjectIds ?? []);
            });
        }

        return $builder;
    }

    /**
     * The honesty label for the whole answer: the weakest any contributing row carries.
     *
     * A summary spanning a sandbox store and a live one is a sandbox figure, and a summary over partially
     * read data is partial. Taking the best label would flatter the total.
     */
    private function weakestSource(StoreQuery $query): MetricSource
    {
        $sources = $this->scope($query)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->distinct()
            ->pluck('o.source');

        $labels = [];

        foreach ($sources as $value) {
            $source = is_string($value) ? MetricSource::tryFrom($value) : null;

            if ($source instanceof MetricSource) {
                $labels[] = $source;
            }
        }

        return $labels === [] ? MetricSource::Synced : MetricSource::weakest($labels);
    }

    /**
     * The currency to report in.
     *
     * Taken from the stores in scope, and **only when they agree**. A client selling in two currencies
     * has no single total, and inventing one by summing minor units across them would produce a figure
     * that is wrong by whatever the exchange rate is. Mixed scope reports the first store's currency and
     * the caller is expected to narrow — see the use case, which says so out loud.
     */
    private function currencyFor(StoreQuery $query): string
    {
        $currencies = $this->scope($query)->distinct()->pluck('s.currency');
        $first = $currencies->first();

        return is_string($first) ? $first : 'SAR';
    }

    /**
     * The evidence levels that can name a campaign, as the domain defines them.
     *
     * Derived from the enum rather than listed, so adding a level cannot leave this query behind.
     *
     * @return list<string>
     */
    private function campaignNamingEvidence(): array
    {
        return array_values(array_map(
            static fn (AttributionEvidence $evidence): string => $evidence->value,
            array_filter(
                AttributionEvidence::cases(),
                static fn (AttributionEvidence $evidence): bool => $evidence->identifiesCampaign(),
            ),
        ));
    }

    /**
     * The campaign an order names, in SQL.
     *
     * Mirrors `OrderAttribution::campaignName()`: the UTM campaign where there is one, otherwise the
     * coupon code — which names a campaign only in the sense that somebody minted it for one, and whose
     * confidence says exactly that.
     */
    private function campaignNameExpression(): string
    {
        return "coalesce(nullif(o.utm_campaign, ''), nullif(o.coupon_code, ''))";
    }

    /**
     * A SQL expression ranking evidence by confidence, strongest first.
     *
     * Built from the enum so the ordering lives in one place. Sorting the column as text would put
     * `coupon_code` above `utm_parameters` alphabetically, which would quietly label a group with the
     * wrong confidence.
     */
    private function evidenceRankExpression(): string
    {
        $cases = [];

        foreach (AttributionEvidence::cases() as $evidence) {
            // Higher confidence becomes a lower rank, so the highest rank is the weakest link.
            $cases[] = sprintf("when o.attribution_evidence = '%s' then %d", $evidence->value, 100 - $evidence->confidence());
        }

        return 'case '.implode(' ', $cases).' else 100 end';
    }

    /**
     * Everything a campaign cannot be named for, as one row.
     *
     * Null when there is nothing in it, because an empty "unattributed" row on a fully attributed report
     * is noise. Present and usually large otherwise, which is the honest picture.
     */
    private function unnamedCampaignRow(StoreQuery $query): ?RevenueBreakdownRow
    {
        /** @var object{orders: int|string|null, revenue: int|string|null, refunded: int|string|null}|null $row */
        $row = $this->scope($query)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->where(function (Builder $scope): void {
                $scope->whereNotIn('o.attribution_evidence', $this->campaignNamingEvidence())
                    ->orWhereRaw($this->campaignNameExpression().' IS NULL');
            })
            ->selectRaw(
                'count(*) as orders, '
                .'coalesce(sum(o.total_minor), 0) as revenue, '
                .'coalesce(sum(o.refunded_minor), 0) as refunded'
            )
            ->first();

        $orders = (int) ($row->orders ?? 0);

        if ($orders === 0) {
            return null;
        }

        $revenue = (int) ($row->revenue ?? 0);
        $refunded = (int) ($row->refunded ?? 0);

        return new RevenueBreakdownRow(
            label: 'Unattributed',
            orders: $orders,
            revenueMinor: $revenue,
            refundedMinor: $refunded,
            netRevenueMinor: $revenue - $refunded,
            // Null evidence, so a reader and a chart both see this is the "we do not know" bucket.
            evidence: null,
        );
    }

    /** @param array<string, mixed> $values */
    private function row(string $label, array $values): RevenueBreakdownRow
    {
        $revenue = (int) ($values['revenue'] ?? 0);
        $refunded = (int) ($values['refunded'] ?? 0);

        return new RevenueBreakdownRow(
            label: $label,
            orders: (int) ($values['orders'] ?? 0),
            revenueMinor: $revenue,
            refundedMinor: $refunded,
            netRevenueMinor: $revenue - $refunded,
            evidence: $this->evidenceFromRank($values['weakest_rank'] ?? null),
        );
    }

    /** Turn the rank back into the evidence it came from. */
    private function evidenceFromRank(mixed $rank): ?AttributionEvidence
    {
        if (! is_numeric($rank)) {
            return null;
        }

        $confidence = 100 - (int) $rank;

        foreach (AttributionEvidence::cases() as $evidence) {
            if ($evidence->confidence() === $confidence) {
                return $evidence;
            }
        }

        return null;
    }

    private function channelLabel(string $source, string $medium): string
    {
        if ($source === '' && $medium === '') {
            // The honest bucket, named rather than blank.
            return RevenueBreakdownRow::UNATTRIBUTED;
        }

        return $medium === '' ? $source : $source.' / '.$medium;
    }
}
