<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\CampaignBindingResolver;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Application\Tracking\Service\LinkPerformanceCalculator;
use App\Domain\Access\Permission;
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
        private LinkPerformanceCalculator $calculator,
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

        return ($this->calculator)($link, $workspaceId, $start, $end, $allocateByClicks);
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
