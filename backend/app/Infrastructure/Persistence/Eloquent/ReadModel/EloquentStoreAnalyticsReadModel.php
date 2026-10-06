<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Commerce\Contract\StoreAnalyticsReadModel;
use App\Application\Commerce\DTO\DailyRevenuePoint;
use App\Application\Commerce\DTO\RevenueBreakdownRow;
use App\Application\Commerce\DTO\StoreQuery;
use App\Application\Commerce\DTO\StoreRevenueSummary;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Commerce\PaymentState;
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

        /** @var object{orders: int|string|null, revenue: int|string|null, refunded: int|string|null, customers: int|string|null, attributed_orders: int|string|null, attributed_revenue: int|string|null}|null $totals */
        $totals = (clone $counted)->selectRaw(
            'count(*) as orders, '
            .'coalesce(sum(o.total_minor), 0) as revenue, '
            .'coalesce(sum(o.refunded_minor), 0) as refunded, '
            .'count(distinct o.store_customer_id) as customers, '
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
        );
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
                "to_char(o.placed_at, 'YYYY-MM-DD') as date, "
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
            ->where('o.revenue_source', $query->revenueSource->value)
            ->where('o.placed_at', '>=', $query->from)
            ->where('o.placed_at', '<=', $query->until);

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
            return 'Unattributed';
        }

        return $medium === '' ? $source : $source.' / '.$medium;
    }
}
