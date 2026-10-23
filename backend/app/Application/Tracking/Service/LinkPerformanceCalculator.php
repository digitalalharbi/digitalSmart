<?php

declare(strict_types=1);

namespace App\Application\Tracking\Service;

use App\Application\Tracking\Contract\LinkEconomicsReadModel;
use App\Application\Tracking\Contract\TrackingAnalyticsReadModel;
use App\Application\Tracking\DTO\TrackingLinkView;
use App\Domain\Tracking\Cost\CostMetrics;
use App\Domain\Tracking\Funnel\FunnelCalculator;
use App\Domain\Tracking\Funnel\FunnelDefinition;
use App\Domain\Tracking\Funnel\FunnelSource;
use DateTimeImmutable;

/**
 * One link's funnel, spend, costs and revenue over a window already checked by the caller.
 *
 * The one place these are put together, so the link's own panel, the operations view and a report cannot disagree about
 * what a link cost.
 */
final readonly class LinkPerformanceCalculator
{
    public function __construct(
        private TrackingAnalyticsReadModel $analytics,
        private LinkEconomicsReadModel $economics,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(TrackingLinkView $link, string $workspaceId, DateTimeImmutable $start, DateTimeImmutable $end, bool $allocateByClicks): array
    {
        $funnel = FunnelCalculator::compute(
            FunnelDefinition::stagesFrom(array_map(static fn (FunnelSource $s): array => ['source' => $s->value], FunnelSource::cases())),
            $this->analytics->cohort($workspaceId, $link->projectId, $link->id, $start, $end),
        );
        $count = [];

        foreach ($funnel['stages'] as $stage) {
            $count[(string) $stage['source']] = $stage['count'];
        }

        $spend = $this->economics->spend($workspaceId, $link->id, $start, $end, $allocateByClicks);
        $costs = CostMetrics::compute($spend, [
            'cpc' => $count['link_click'] ?? null,
            'cost_per_landing_view' => $count['landing_view'] ?? null,
            'cost_per_form_start' => $count['form_start'] ?? null,
            'cpl' => $count['lead_created'] ?? null,
            'cost_per_contacted_lead' => $count['lead_contacted'] ?? null,
            'cpql' => $count['lead_qualified'] ?? null,
            'cost_per_appointment' => $count['appointment_booked'] ?? null,
            'cost_per_opportunity' => $count['opportunity_created'] ?? null,
            'cac' => $count['sale_completed'] ?? null,
        ]);

        $revenue = [];

        foreach ($this->economics->revenue($workspaceId, $link->id, $start, $end) as $source => $figure) {
            $revenue[$source] = [...$figure, 'roas' => CostMetrics::roas($spend, $figure['minor'], $figure['currency'])];
        }

        return [
            'link_id' => $link->id,
            'from' => $start->format('Y-m-d'),
            'to' => $end->modify('-1 day')->format('Y-m-d'),
            'binding_level' => $link->adId !== null ? 'ad' : ($link->adGroupId !== null ? 'ad_group' : ($link->campaignId !== null ? 'campaign' : null)),
            'funnel' => $funnel,
            'counts' => $count,
            'spend' => $spend,
            'costs' => $costs,
            'revenue' => $revenue,
        ];
    }
}
