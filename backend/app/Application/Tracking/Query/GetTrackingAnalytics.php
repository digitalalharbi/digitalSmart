<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\FunnelRepository;
use App\Application\Tracking\Contract\TrackingAnalyticsReadModel;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Tracking\Funnel\FunnelCalculator;
use App\Domain\Tracking\Funnel\FunnelDefinition;
use DateTimeImmutable;

/**
 * What the tracking recorded, read back as a funnel, a breakdown, a lead's path and a follow-up report.
 *
 * Every read first proves the project is one the reader can see; nothing here trusts an id because it was sent.
 */
final readonly class GetTrackingAnalytics
{
    public const array DIMENSIONS = [
        'platform', 'campaign', 'ad_group', 'ad', 'creative', 'device', 'browser', 'referrer', 'utm_source', 'landing', 'date', 'hour',
    ];

    public function __construct(
        private TrackingAnalyticsReadModel $read,
        private FunnelRepository $funnels,
        private TrackingLinkRepository $links,
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return list<array{id: string, project_id: string, name: string, stages: list<array<string, mixed>>}> */
    public function funnels(?string $projectId): array
    {
        $this->guard->ensure(Permission::TrackingView);

        return $this->funnels->list($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $projectId);
    }

    /**
     * @return array<string, mixed>
     */
    public function funnel(string $projectId, ?string $funnelId, ?string $linkId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $this->guard->ensure(Permission::TrackingView);
        [$workspaceId] = $this->scope($projectId);
        [$from, $to] = $this->window($from, $to);

        $name = null;
        $stages = FunnelDefinition::standard();

        if ($funnelId !== null) {
            $stored = $this->funnels->find($workspaceId, $this->visibility->visibleProjectIds(), $funnelId) ?? throw ResourceNotFound::of('Funnel');

            if ($stored['project_id'] !== $projectId) {
                throw ResourceNotFound::of('Funnel');
            }

            $stages = $stored['stages'];
            $name = $stored['name'];
        }

        if ($linkId !== null) {
            $this->links->find($workspaceId, $this->visibility->visibleProjectIds(), $linkId) ?? throw ResourceNotFound::of('Tracking link');
        }

        $result = FunnelCalculator::compute($stages, $this->read->cohort($workspaceId, $projectId, $linkId, $from, $to));

        return [...$result, 'name' => $name, 'is_standard' => $funnelId === null, 'from' => $from->format('Y-m-d'), 'to' => $to->modify('-1 day')->format('Y-m-d')];
    }

    /** @return array{dimension: string, rows: list<array<string, mixed>>} */
    public function breakdown(string $projectId, string $dimension, ?string $linkId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $this->guard->ensure(Permission::TrackingView);

        if (! in_array($dimension, self::DIMENSIONS, true)) {
            throw new TrackingRefused('Unknown breakdown. Choose one of: '.implode(', ', self::DIMENSIONS).'.');
        }

        [$workspaceId] = $this->scope($projectId);
        [$from, $to] = $this->window($from, $to);

        return ['dimension' => $dimension, 'rows' => $this->read->breakdown($workspaceId, $projectId, $linkId, $dimension, $from, $to)];
    }

    /** @return array<string, mixed> */
    public function followUp(string $projectId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, int $slaMinutes): array
    {
        $this->guard->ensure(Permission::TrackingView);
        [$workspaceId] = $this->scope($projectId);
        [$from, $to] = $this->window($from, $to);

        return $this->read->followUp($workspaceId, $projectId, $from, $to, max(1, min($slaMinutes, 10080)));
    }

    /** @return array{events: list<array<string, mixed>>, tracked: bool, note: ?string} */
    public function leadTimeline(string $leadId): array
    {
        $this->guard->ensure(Permission::LeadsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $this->leads->find($workspaceId, $this->visibility->visibleProjectIds(), $leadId) ?? throw ResourceNotFound::of('Lead');

        $events = $this->read->leadTimeline($workspaceId, $leadId);
        $tracked = array_filter($events, static fn (array $e): bool => $e['source'] !== 'lead_pipeline') !== [];

        return [
            'events' => $events,
            'tracked' => $tracked,
            'note' => $tracked ? null : 'No tracked visit is attached to this lead. Earlier touchpoints may have happened; they were not recorded here.',
        ];
    }

    /** @return array{0: string} */
    private function scope(string $projectId): array
    {
        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->links->clientOfProject($workspaceId, $this->visibility->visibleProjectIds(), $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        return [$workspaceId];
    }

    /**
     * Whole days, the end exclusive: the last 30 days by default, ending today.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function window(?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $end = ($to ?? $this->clock->now())->setTime(0, 0)->modify('+1 day');
        $start = ($from ?? $end->modify('-30 days'))->setTime(0, 0);

        if ($start >= $end || $start < $end->modify('-400 days')) {
            throw new TrackingRefused('Choose a period of between one day and 400 days.');
        }

        return [$start, $end];
    }
}
