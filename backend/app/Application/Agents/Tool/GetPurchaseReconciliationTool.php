<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Commerce\Query\GetStoreAnalytics;
use App\Application\Measurement\Query\GetGa4Funnel;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Commerce\RevenueSource;

/**
 * A client's GA4 purchase events beside the paid orders its stores recorded over the same days.
 *
 * Counts only, and never a verdict: an event is not an order. Cash on delivery, consent, blockers and a
 * thank-you page reloaded all separate the two, so this tool states both figures and where each came from and
 * leaves the judgement — and its tolerance — to the caller.
 */
final readonly class GetPurchaseReconciliationTool implements ToolHandler
{
    public function __construct(
        private GetGa4Funnel $funnel,
        private GetStoreAnalytics $store,
        private Clock $clock,
    ) {}

    public function __invoke(array $input): array
    {
        $days = (int) ($input['days'] ?? 28);
        $until = $this->clock->now()->modify('-1 day')->setTime(0, 0);
        $from = $until->modify('-'.($days - 1).' days');
        $clientId = (string) $input['client_id'];

        $funnel = ($this->funnel)($from, $until, $clientId);
        $purchase = null;

        foreach ($funnel['stages'] as $stage) {
            if ($stage['event'] === 'purchase') {
                $purchase = $stage['count'];
            }
        }

        $orders = 0;
        $stores = [];

        foreach ([RevenueSource::Salla, RevenueSource::Zid] as $authority) {
            $summary = ($this->store)($authority, $from, $until, $clientId)->summary;

            if ($summary->orders > 0) {
                $orders += $summary->orders;
                $stores[] = ['authority' => $authority->value, 'source' => $summary->source->value, 'orders' => $summary->orders];
            }
        }

        return [
            'days' => $days,
            'ga4_purchase_events' => $purchase,
            'ga4_source' => $funnel['source'],
            'ga4_days_present' => $funnel['days_present'],
            'days_in_period' => $funnel['days_in_period'],
            'store_paid_orders' => $orders,
            'stores' => $stores,
        ];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_purchase_reconciliation',
            description: 'One client\'s GA4 purchase events and paid store orders over a window ending yesterday. Counts only; an event is not an order.',
            action: 'READ_ANALYTICS',
            permissions: [Permission::AnalyticsView],
            risk: 'read',
            requiresApproval: false,
            properties: [
                'client_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The client.'],
                'days' => ['type' => 'integer', 'min' => 7, 'max' => 90, 'description' => 'Window ending yesterday (default 28).'],
            ],
            required: ['client_id'],
        );
    }
}
