<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\CampaignBindingResolver;
use App\Application\Tracking\Contract\LinkEconomicsReadModel;
use App\Application\Tracking\Contract\TrackingAnalyticsReadModel;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Tracking\Cost\CostMetrics;
use App\Domain\Tracking\Funnel\FunnelCalculator;
use App\Domain\Tracking\Funnel\FunnelDefinition;
use App\Domain\Tracking\Funnel\FunnelSource;
use DateTimeImmutable;

/**
 * One tracking link as an operating view: what it brought, what that cost, and what it earned.
 *
 * Counts are the funnel's, so the link, the funnel page and a report cannot disagree. Spend and revenue come from their own
 * read model; this class only divides, and the dividing lives in `CostMetrics`, which refuses to produce a figure from
 * anything it was not given.
 */
final readonly class GetLinkPerformance
{
    public function __construct(
        private TrackingLinkRepository $links,
        private TrackingAnalyticsReadModel $analytics,
        private LinkEconomicsReadModel $economics,
        private CampaignBindingResolver $bindings,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(string $linkId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, bool $allocateByClicks): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $workspaceId = $this->actor->workspaceId()->value;
        $link = $this->links->find($workspaceId, $this->visibility->visibleProjectIds(), $linkId) ?? throw ResourceNotFound::of('Tracking link');

        $end = ($to ?? $this->clock->now())->setTime(0, 0)->modify('+1 day');
        $start = ($from ?? $end->modify('-30 days'))->setTime(0, 0);

        if ($start >= $end || $start < $end->modify('-400 days')) {
            throw new TrackingRefused('Choose a period of between one day and 400 days.');
        }

        $funnel = FunnelCalculator::compute(
            FunnelDefinition::stagesFrom(array_map(static fn (FunnelSource $s): array => ['source' => $s->value], FunnelSource::cases())),
            $this->analytics->cohort($workspaceId, $link->projectId, $linkId, $start, $end),
        );
        $count = [];

        foreach ($funnel['stages'] as $stage) {
            $count[(string) $stage['source']] = $stage['count'];
        }

        $spend = $this->economics->spend($workspaceId, $linkId, $start, $end, $allocateByClicks);
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

        foreach ($this->economics->revenue($workspaceId, $linkId, $start, $end) as $source => $figure) {
            $revenue[$source] = [...$figure, 'roas' => CostMetrics::roas($spend, $figure['minor'], $figure['currency'])];
        }

        return [
            'link_id' => $linkId,
            'from' => $start->format('Y-m-d'),
            'to' => $end->modify('-1 day')->format('Y-m-d'),
            'binding_level' => $link->adId !== null ? 'ad' : ($link->adGroupId !== null ? 'ad_group' : ($link->campaignId !== null ? 'campaign' : null)),
            'funnel' => $funnel,
            'spend' => $spend,
            'costs' => $costs,
            'revenue' => $revenue,
        ];
    }

    /**
     * One level of the campaign picker, for a project the reader can see.
     *
     * @return list<array{id: string, name: string, detail: ?string}>
     */
    public function options(string $projectId, string $level, ?string $parentId, ?string $search): array
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->links->clientOfProject($workspaceId, $this->visibility->visibleProjectIds(), $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        return $this->bindings->options($workspaceId, $projectId, $level, $parentId, $search);
    }
}
