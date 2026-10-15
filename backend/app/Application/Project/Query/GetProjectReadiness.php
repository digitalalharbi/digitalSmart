<?php

declare(strict_types=1);

namespace App\Application\Project\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Query\GetTrackingHealth;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Project\ReadinessState;
use Illuminate\Support\Facades\DB;

/**
 * Is this project ready to run — answered visually, not as a technical checklist.
 *
 * Six questions, each with one state and one sentence: budget, accounts, tracking, creative, approvals,
 * landing page. Overall is the worst of the ones that can be known. Tracking is read through
 * `GetTrackingHealth` rather than re-deciding what a healthy source is.
 *
 * Reads are scoped by the same visibility everything else uses: a project the actor cannot see is a 404,
 * not a readiness report with the answer redacted.
 */
final readonly class GetProjectReadiness
{
    public function __construct(
        private GetTrackingHealth $tracking,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(string $projectId): array
    {
        $this->guard->ensure(Permission::ProjectsViewAssigned);
        $workspace = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $project = DB::table('projects')->where(['workspace_id' => $workspace, 'id' => $projectId])->whereNull('deleted_at')
            ->first(['id', 'name', 'client_id', 'status']);

        if ($project === null || ($visible !== null && ! in_array($projectId, $visible, true))) {
            throw ResourceNotFound::of('Project');
        }

        $today = $this->clock->now()->format('Y-m-d');
        $clientId = (string) $project->client_id;

        $checks = [
            'budget' => $this->budget($workspace, $projectId, $clientId, $today),
            'accounts' => $this->accounts($workspace, $projectId),
            'tracking' => $this->trackingCheck($projectId),
            'creative' => $this->creative($workspace, $projectId, $clientId),
            'approvals' => $this->approvals($workspace, $projectId),
            'landing_page' => [ReadinessState::Unknown, 'Landing pages are not tracked in digitalSmart yet, so this cannot be confirmed here.'],
        ];

        $states = array_map(static fn (array $c): ReadinessState => $c[0], $checks);
        $known = array_filter($states, static fn (ReadinessState $s): bool => $s !== ReadinessState::Unknown);

        $overall = match (true) {
            in_array(ReadinessState::Blocked, $known, true) => ReadinessState::Blocked,
            in_array(ReadinessState::NeedsAttention, $known, true) => ReadinessState::NeedsAttention,
            default => ReadinessState::Ready,
        };

        return [
            'project' => ['id' => (string) $project->id, 'name' => (string) $project->name, 'status' => (string) $project->status],
            'overall' => ['state' => $overall->value, 'label' => $overall->label()],
            'checks' => array_map(static fn (string $key, array $c): array => [
                'key' => $key, 'state' => $c[0]->value, 'label' => $c[0]->label(), 'reason' => $c[1],
            ], array_keys($checks), array_values($checks)),
            'ready_count' => count(array_filter($states, static fn (ReadinessState $s): bool => $s === ReadinessState::Ready)),
            'total' => count($checks),
        ];
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function budget(string $workspace, string $projectId, string $clientId, string $today): array
    {
        $budgets = DB::table('budgets')->where(['workspace_id' => $workspace, 'status' => 'approved'])
            ->where(static function ($q) use ($projectId, $clientId): void {
                $q->where('project_id', $projectId)->orWhere(static fn ($x) => $x->whereNull('project_id')->where('client_id', $clientId));
            });

        if ((clone $budgets)->where('period_end', '>=', $today)->where('period_start', '<=', $today)->exists()) {
            return [ReadinessState::Ready, 'An approved budget covers today.'];
        }

        if ((clone $budgets)->exists()) {
            return [ReadinessState::NeedsAttention, 'The approved budget does not cover today’s date.'];
        }

        return [ReadinessState::Blocked, 'No approved budget yet.'];
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function accounts(string $workspace, string $projectId): array
    {
        $accounts = DB::table('project_integration_bindings as b')->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where(['b.workspace_id' => $workspace, 'b.project_id' => $projectId]);

        $total = (clone $accounts)->count();

        if ($total === 0) {
            return [ReadinessState::Blocked, 'No advertising account is connected to this project.'];
        }

        $usable = (clone $accounts)->where('a.status', 'active')->where('b.sync_enabled', true)->count();

        return $usable > 0
            ? [ReadinessState::Ready, sprintf('%d %s connected and syncing.', $usable, $usable === 1 ? 'account' : 'accounts')]
            : [ReadinessState::NeedsAttention, 'Accounts are connected but none is active and syncing.'];
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function trackingCheck(string $projectId): array
    {
        if (! $this->guard->allows(Permission::TrackingView)) {
            return [ReadinessState::Unknown, 'Tracking is not visible to your role.'];
        }

        $view = ($this->tracking)(null, $projectId);

        if ($view->sources === []) {
            return [ReadinessState::Blocked, 'Nothing is measuring this project yet.'];
        }

        if ($view->criticalCount() > 0) {
            return [ReadinessState::Blocked, 'Measurement has a critical problem.'];
        }

        return $view->isHealthy()
            ? [ReadinessState::Ready, 'Measurement is reporting.']
            : [ReadinessState::NeedsAttention, 'Measurement is reporting with warnings.'];
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function creative(string $workspace, string $projectId, string $clientId): array
    {
        $requests = DB::table('creative_requests')->where('workspace_id', $workspace)->where('client_id', $clientId)
            ->where(static fn ($q) => $q->where('project_id', $projectId)->orWhereNull('project_id'))
            ->where('state', '!=', 'cancelled');

        if (! (clone $requests)->exists()) {
            return [ReadinessState::NeedsAttention, 'No creative has been briefed.'];
        }

        return (clone $requests)->where('state', 'approved')->exists()
            ? [ReadinessState::Ready, 'Approved creative is available.']
            : [ReadinessState::NeedsAttention, 'Creative is briefed but none is approved yet.'];
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function approvals(string $workspace, string $projectId): array
    {
        $waiting = DB::table('approval_requests')->where(['workspace_id' => $workspace, 'project_id' => $projectId, 'state' => 'pending'])
            ->where('created_at', '<', now()->subDays(7))->count();

        return $waiting > 0
            ? [ReadinessState::NeedsAttention, sprintf('%d %s waiting more than a week.', $waiting, $waiting === 1 ? 'decision' : 'decisions')]
            : [ReadinessState::Ready, 'No decision is stuck.'];
    }
}
