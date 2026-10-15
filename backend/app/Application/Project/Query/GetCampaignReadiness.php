<?php

declare(strict_types=1);

namespace App\Application\Project\Query;

use App\Application\Advertising\Contract\CampaignAssignmentRepository;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Project\Contract\ReadinessFacts;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\CampaignOwnership;
use App\Domain\Project\ReadinessState;

/**
 * Is this campaign in a state to be trusted — placed, on a working account, and measurable.
 *
 * The project's own readiness (budget, tracking, creative, approvals) is *included*, not recomputed: a
 * campaign inside an unready project is unready for the project's reasons, and a second copy of those rules
 * would be free to disagree with the page the reader is sent to.
 *
 * Three questions are the campaign's own, each with one state and one sentence, and "unknown" is a real answer:
 * a campaign that has not spent has not shown whether it can be measured, and saying "ready" about that would be
 * the one claim to avoid.
 */
final readonly class GetCampaignReadiness
{
    public const int WINDOW_DAYS = 28;

    public function __construct(
        private CampaignAssignmentRepository $campaigns,
        private ReadinessFacts $facts,
        private GetProjectReadiness $project,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(string $campaignId): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspace = $this->actor->workspaceId()->value;
        $view = $this->campaigns->find($workspace, $this->visibility->visibleProjectIds(), $campaignId)
            ?? throw ResourceNotFound::of('Campaign');

        $to = $this->clock->now()->modify('-1 day');
        $from = $to->modify(sprintf('-%d days', self::WINDOW_DAYS - 1));
        $facts = $this->facts->campaign($workspace, $campaignId, $from, $to)
            ?? throw ResourceNotFound::of('Campaign');

        $checks = [
            'placed' => $this->placed($view->ownership),
            'account' => $this->account($facts['account_status'], $facts['sync_enabled']),
            'measurable' => $this->measurable($facts),
        ];

        $projectReadiness = null;

        if ($view->projectId !== null) {
            try {
                $projectReadiness = ($this->project)($view->projectId);
            } catch (ResourceNotFound) {
                $projectReadiness = null;
            }
        }

        $states = array_map(static fn (array $c): ReadinessState => $c[0], $checks);

        if ($projectReadiness !== null) {
            $states[] = ReadinessState::from((string) $projectReadiness['overall']['state']);
        }

        $known = array_filter($states, static fn (ReadinessState $s): bool => $s !== ReadinessState::Unknown);
        $overall = match (true) {
            in_array(ReadinessState::Blocked, $known, true) => ReadinessState::Blocked,
            in_array(ReadinessState::NeedsAttention, $known, true) => ReadinessState::NeedsAttention,
            $known === [] => ReadinessState::Unknown,
            default => ReadinessState::Ready,
        };

        return [
            'campaign' => ['id' => $view->campaignId, 'name' => $view->campaignName, 'project_id' => $view->projectId],
            'overall' => ['state' => $overall->value, 'label' => $overall->label()],
            'checks' => array_map(static fn (string $key, array $c): array => [
                'key' => $key, 'state' => $c[0]->value, 'label' => $c[0]->label(), 'reason' => $c[1],
            ], array_keys($checks), array_values($checks)),
            'project' => $projectReadiness === null ? null : [
                'id' => $view->projectId,
                'overall' => $projectReadiness['overall'],
                'ready_count' => $projectReadiness['ready_count'],
                'total' => $projectReadiness['total'],
            ],
        ];
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function placed(CampaignOwnership $ownership): array
    {
        return match ($ownership) {
            CampaignOwnership::Assigned => [ReadinessState::Ready, 'It has been placed in a project.'],
            CampaignOwnership::Implied => [ReadinessState::Ready, 'Its account serves one project, so it belongs there.'],
            CampaignOwnership::Ambiguous => [ReadinessState::NeedsAttention, 'Its account serves several projects and nobody has said which owns it.'],
            CampaignOwnership::Unbound => [ReadinessState::Blocked, 'Its account is not bound to any project, so nothing here can count it.'],
        };
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function account(string $status, bool $syncEnabled): array
    {
        if ($status !== 'active') {
            return [ReadinessState::Blocked, 'Its ad account is not active on the platform.'];
        }

        return $syncEnabled
            ? [ReadinessState::Ready, 'Its ad account is active and syncing.']
            : [ReadinessState::NeedsAttention, 'Its ad account is active but syncing is switched off for every project it serves.'];
    }

    /**
     * @param  array{account_status: string, sync_enabled: bool, spend_minor: ?int, results: ?int, reported_days: int, objective: string}  $facts
     * @return array{0: ReadinessState, 1: string}
     */
    private function measurable(array $facts): array
    {
        if ($facts['reported_days'] === 0) {
            return [ReadinessState::Unknown, 'Nothing has been reported for it in the last four weeks, so it has not shown it can be measured.'];
        }

        if (($facts['spend_minor'] ?? 0) > 0 && ($facts['results'] ?? 0) === 0) {
            return [ReadinessState::NeedsAttention, 'It has spent and nothing has been recorded against its goal — check that the goal is being measured.'];
        }

        return [ReadinessState::Ready, 'Results are being recorded against its goal.'];
    }
}
