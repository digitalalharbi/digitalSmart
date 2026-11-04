<?php

declare(strict_types=1);

namespace App\Application\Commerce\Query;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\AnalyticsRequest;
use App\Application\Analytics\Query\GetAnalytics;
use App\Application\Measurement\Query\GetGa4Funnel;
use App\Domain\Analytics\Metric;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Shared\ValueObject\DateRange;
use DateTimeImmutable;

/**
 * One client, one period, every authority side by side — and the labelled difference of each claim from the store.
 *
 * The one place the product does arithmetic across revenue sources, and it is a subtraction, never a sum. The store is the
 * authority for sales because it invoiced them; an ad platform reports what it believes it caused and GA4 reports what a
 * browser said, so each is shown as a *claim* with its distance from the invoiced figure. No total across sources exists
 * here, and a difference is null wherever either side is missing or the currencies differ: a gap computed from an absent
 * figure would read as a finding.
 *
 * It composes the three reads that already exist, so scope, permission and every figure are theirs — a number here cannot
 * disagree with the screen it came from.
 */
final readonly class GetRevenueReconciliation
{
    public function __construct(
        private GetStoreAnalytics $store,
        private GetGa4Funnel $funnel,
        private GetAnalytics $analytics,
    ) {}

    /**
     * @return array{
     *     stores: list<array{authority: string, source: string, currency: string, paid_orders: int, net_revenue_minor: int, refunded_minor: int, pending_orders: int, tracked_orders: int, tracked_revenue_minor: int}>,
     *     store_paid_orders: ?int,
     *     store_net_revenue_minor: ?int,
     *     store_currency: ?string,
     *     claims: list<array{source: string, metric_source: ?string, purchases: int|float|null, revenue_minor: ?int, currency: ?string, purchases_delta: int|float|null, purchases_ratio: ?float, revenue_delta_minor: ?int, unavailable_reason: ?string}>,
     *     ga4: array{days_present: int, days_in_period: int}
     * }
     */
    public function __invoke(string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $stores = [];

        foreach ([RevenueSource::Salla, RevenueSource::Zid] as $authority) {
            $summary = ($this->store)($authority, $from, $until->setTime(23, 59, 59), $clientId)->summary;

            if ($summary->orders === 0 && $summary->pendingOrders === 0) {
                continue;
            }

            $stores[] = [
                'authority' => $authority->value,
                'source' => $summary->source->value,
                'currency' => $summary->currency,
                'paid_orders' => $summary->orders,
                'net_revenue_minor' => $summary->netRevenueMinor,
                'refunded_minor' => $summary->refundedMinor,
                'pending_orders' => $summary->pendingOrders,
                'tracked_orders' => $summary->attributedOrders,
                'tracked_revenue_minor' => $summary->attributedRevenueMinor,
            ];
        }

        // No store read means no authority to measure a claim against — null, never zero orders.
        $orders = $stores === [] ? null : array_sum(array_column($stores, 'paid_orders'));
        $currencies = array_values(array_unique(array_column($stores, 'currency')));
        $currency = count($currencies) === 1 ? $currencies[0] : null;
        $revenue = $currency === null ? null : array_sum(array_column($stores, 'net_revenue_minor'));

        $funnel = ($this->funnel)($from, $until, $clientId);
        $events = null;

        foreach ($funnel['stages'] as $stage) {
            if ($stage['event'] === 'purchase') {
                $events = $stage['count'];
            }
        }

        $platform = ($this->analytics)(new AnalyticsRequest(
            range: new DateRange($from, $until),
            filters: new AnalyticsFilters(clientId: $clientId),
            metrics: [Metric::Purchases, Metric::Revenue],
        ))->summary;
        $purchases = $platform->get(Metric::Purchases);
        $claimed = $platform->get(Metric::Revenue);
        $claimedMinor = $claimed?->value === null ? null : (int) round($claimed->value);

        $tracked = $stores === [] ? null : array_sum(array_column($stores, 'tracked_orders'));

        return [
            'stores' => $stores,
            'store_paid_orders' => $orders,
            'store_net_revenue_minor' => $revenue,
            'store_currency' => $currency,
            'claims' => [
                $this->claim(
                    RevenueSource::PlatformReported->value,
                    $platform->isEmpty() ? null : $platform->source->value,
                    $purchases?->value,
                    $claimedMinor,
                    $claimed?->currency,
                    $orders,
                    $revenue,
                    $currency,
                    $platform->isEmpty() ? 'No advertising figures for this client in the period.' : ($claimed->unavailableReason ?? $purchases?->unavailableReason),
                ),
                $this->claim(
                    RevenueSource::GoogleAnalytics->value,
                    $funnel['source'],
                    $events,
                    null,
                    null,
                    $orders,
                    $revenue,
                    $currency,
                    $events === null ? 'No GA4 purchase events were read for this client in the period.' : 'Purchase events only: this product does not read GA4 revenue.',
                ),
                $this->claim(
                    'tracking_evidence',
                    $stores === [] ? null : $stores[0]['source'],
                    $tracked,
                    $currency === null || $stores === [] ? null : array_sum(array_column($stores, 'tracked_revenue_minor')),
                    $currency,
                    $orders,
                    $revenue,
                    $currency,
                    $stores === [] ? 'No store orders were read for this client in the period.' : null,
                ),
            ],
            'ga4' => ['days_present' => $funnel['days_present'], 'days_in_period' => $funnel['days_in_period']],
        ];
    }

    /**
     * @return array{source: string, metric_source: ?string, purchases: int|float|null, revenue_minor: ?int, currency: ?string, purchases_delta: int|float|null, purchases_ratio: ?float, revenue_delta_minor: ?int, unavailable_reason: ?string}
     */
    private function claim(
        string $source,
        ?string $metricSource,
        int|float|null $purchases,
        ?int $revenueMinor,
        ?string $currency,
        ?int $storeOrders,
        ?int $storeRevenueMinor,
        ?string $storeCurrency,
        ?string $reason,
    ): array {
        $comparable = $purchases !== null && $storeOrders !== null;
        $sameMoney = $revenueMinor !== null && $storeRevenueMinor !== null && $currency !== null && $currency === $storeCurrency;

        return [
            'source' => $source,
            'metric_source' => $metricSource,
            'purchases' => $purchases,
            'revenue_minor' => $revenueMinor,
            'currency' => $currency,
            // Claim minus store: positive means the source claims more than was invoiced.
            'purchases_delta' => $comparable ? $purchases - $storeOrders : null,
            'purchases_ratio' => $comparable && $storeOrders > 0 ? round($purchases / $storeOrders, 3) : null,
            'revenue_delta_minor' => $sameMoney ? $revenueMinor - $storeRevenueMinor : null,
            'unavailable_reason' => $reason,
        ];
    }
}
