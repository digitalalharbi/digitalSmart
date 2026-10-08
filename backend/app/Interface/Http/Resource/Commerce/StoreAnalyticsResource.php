<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Commerce;

use App\Application\Commerce\DTO\DailyRevenuePoint;
use App\Application\Commerce\DTO\RevenueBreakdownRow;
use App\Application\Commerce\DTO\StoreAnalyticsView;
use App\Domain\Commerce\CustomerRecency;

final readonly class StoreAnalyticsResource
{
    /** @return array<string, mixed> */
    public static function view(StoreAnalyticsView $view): array
    {
        $summary = $view->summary;

        return [
            'from' => $view->from->format('Y-m-d'),
            'until' => $view->until->format('Y-m-d'),
            'summary' => [
                /*
                 * Both labels travel with every total. `revenue_source` says who is claiming this —
                 * Salla, GA4, a CRM — and `source` says how fresh and how complete the rows behind it
                 * are. A figure without both cannot be defended when a client's own dashboard disagrees,
                 * and it will.
                 */
                'revenue_source' => $summary->revenueSource->value,
                'revenue_source_label' => $summary->revenueSource->label(),
                'is_invoiced' => $summary->revenueSource->isInvoiced(),
                'is_modelled' => $summary->revenueSource->isModelled(),
                'source' => $summary->source->value,
                'source_label' => $summary->source->label(),
                'currency' => $summary->currency,
                'orders' => $summary->orders,
                'revenue_minor' => $summary->revenueMinor,
                'refunded_minor' => $summary->refundedMinor,
                // The figure a client should be shown: sales less what came back.
                'net_revenue_minor' => $summary->netRevenueMinor,
                /*
                 * Null when nothing counted. A denominator of zero has no average, and rendering one as
                 * "0.00" invites a reader to compare it with last month's.
                 */
                'average_order_value_minor' => $summary->averageOrderValueMinor(),
                'units' => $summary->units,
                'customers' => $summary->customers,
                /*
                 * Real orders that are not yet money — mostly cash on delivery. Reported beside the total
                 * rather than inside it, because counting them would overstate every report in the week
                 * before the courier either collects or comes back.
                 */
                'pending_orders' => $summary->pendingOrders,
                'pending_revenue_minor' => $summary->pendingRevenueMinor,
                /*
                 * How much of this total can be traced to marketing at all. Published so nobody reads a
                 * channel breakdown without knowing what share of the money it covers: a report at 30%
                 * coverage is a different document from one at 95%.
                 */
                'attributed_orders' => $summary->attributedOrders,
                'attributed_revenue_minor' => $summary->attributedRevenueMinor,
                'attribution_coverage' => $summary->attributionCoverage(),
            ],
            'by_channel' => array_map(static fn (RevenueBreakdownRow $row): array => self::row($row), $view->byChannel),
            'by_campaign' => array_map(static fn (RevenueBreakdownRow $row): array => self::row($row), $view->byCampaign),
            'by_product' => array_map(static fn (RevenueBreakdownRow $row): array => self::row($row), $view->byProduct),
            'daily' => array_map(static fn (DailyRevenuePoint $point): array => [
                'date' => $point->date,
                'orders' => $point->orders,
                'net_revenue_minor' => $point->netRevenueMinor,
                'refunded_minor' => $point->refundedMinor,
            ], $view->daily),
            /*
             * The new-versus-returning mix, with its three buckets and the reason there is no share when there is
             * none. `new_share` is **null** rather than computed whenever too much of the period belongs to
             * customers who were buying before this store was connected — a share over a denominator that is
             * mostly "we cannot tell" is a figure somebody puts in a report.
             */
            'customer_mix' => [
                'new_customers' => $view->customerMix->newCustomers,
                'returning_customers' => $view->customerMix->returningCustomers,
                'unknown_customers' => $view->customerMix->unknownCustomers,
                'new_revenue_minor' => $view->customerMix->newRevenueMinor,
                'returning_revenue_minor' => $view->customerMix->returningRevenueMinor,
                'unknown_revenue_minor' => $view->customerMix->unknownRevenueMinor,
                'new_share' => $view->customerMix->newShare(),
                'unavailable_reason' => $view->customerMix->unavailableReason(),
                /*
                 * Published so a reader can see the third bucket exists even when it is empty. A split that only
                 * mentioned "we cannot tell" when it was large would teach a reader it never happens.
                 */
                'buckets' => array_map(static fn (CustomerRecency $recency): array => [
                    'recency' => $recency->value,
                    'label' => $recency->label(),
                ], CustomerRecency::cases()),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function row(RevenueBreakdownRow $row): array
    {
        return [
            'label' => $row->label,
            'orders' => $row->orders,
            'revenue_minor' => $row->revenueMinor,
            'refunded_minor' => $row->refundedMinor,
            'net_revenue_minor' => $row->netRevenueMinor,
            'units' => $row->units,
            /*
             * The **weakest** evidence anything in this row rests on, with its confidence. A row crediting
             * a campaign on a coupon code is a different claim from one crediting it on a click
             * identifier, and a report that rendered both identically would invite a client to act on the
             * weaker one.
             */
            'evidence' => $row->evidence?->value,
            'evidence_label' => $row->evidence?->label(),
            'confidence' => $row->confidence(),
            // So a chart can mark the honest "we do not know" bucket rather than drawing it as a channel.
            'is_unattributed' => $row->isUnattributed(),
            'external_id' => $row->externalId,
        ];
    }
}
