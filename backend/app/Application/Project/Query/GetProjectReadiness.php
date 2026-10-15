<?php

declare(strict_types=1);

namespace App\Application\Project\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Query\GetTrackingHealth;
use App\Application\Project\Contract\ReadinessFacts;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Project\ReadinessState;

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
        private ReadinessFacts $facts,
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

        $project = $this->facts->project($workspace, $projectId);

        if ($project === null || ($visible !== null && ! in_array($projectId, $visible, true))) {
            throw ResourceNotFound::of('Project');
        }

        $today = $this->clock->now()->format('Y-m-d');
        $clientId = $project['client_id'];

        $checks = [
            'budget' => $this->budget($workspace, $projectId, $clientId, $today),
            'accounts' => $this->accounts($workspace, $projectId),
            'tracking' => $this->trackingCheck($projectId),
            'creative' => $this->creative($workspace, $projectId, $clientId),
            'data_quality' => $this->dataQuality($workspace, $projectId),
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
            'project' => ['id' => $project['id'], 'name' => $project['name'], 'status' => $project['status']],
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
        $budget = $this->facts->approvedBudget($workspace, $projectId, $clientId, $today);

        if ($budget['covers_today']) {
            return [ReadinessState::Ready, 'An approved budget covers today.'];
        }

        if ($budget['any']) {
            return [ReadinessState::NeedsAttention, 'The approved budget does not cover today’s date.'];
        }

        return [ReadinessState::Blocked, 'No approved budget yet.'];
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function accounts(string $workspace, string $projectId): array
    {
        $accounts = $this->facts->boundAccounts($workspace, $projectId);

        if ($accounts['bound'] === 0) {
            return [ReadinessState::Blocked, 'No advertising account is connected to this project.'];
        }

        $usable = $accounts['usable'];

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
        $creative = $this->facts->creative($workspace, $projectId, $clientId);

        if (! $creative['briefed']) {
            return [ReadinessState::NeedsAttention, 'No creative has been briefed.'];
        }

        return $creative['approved']
            ? [ReadinessState::Ready, 'Approved creative is available.']
            : [ReadinessState::NeedsAttention, 'Creative is briefed but none is approved yet.'];
    }

    /**
     * Whether the figures this project will report can be trusted, which is a different question from whether
     * they exist. Nothing bound is `unknown`, not `ready`: with no accounts there is nothing to have verified.
     *
     * @return array{0: ReadinessState, 1: string}
     */
    private function dataQuality(string $workspace, string $projectId): array
    {
        $q = $this->facts->dataQuality($workspace, $projectId);

        if ($q['accounts'] === 0) {
            return [ReadinessState::Unknown, 'Nothing is bound yet, so there is no data to check.'];
        }

        $problems = [];

        if ($q['missing_currency'] > 0) {
            $problems[] = sprintf('%d %s no currency', $q['missing_currency'], $q['missing_currency'] === 1 ? 'account has' : 'accounts have');
        }

        if ($q['missing_timezone'] > 0) {
            $problems[] = sprintf('%d %s no timezone', $q['missing_timezone'], $q['missing_timezone'] === 1 ? 'account has' : 'accounts have');
        }

        if ($q['timezones'] > 1) {
            $problems[] = sprintf('its accounts span %d timezones, so daily figures do not line up', $q['timezones']);
        }

        if ($q['unplaced'] > 0) {
            $problems[] = sprintf('%d %s not been placed in a project', $q['unplaced'], $q['unplaced'] === 1 ? 'campaign has' : 'campaigns have');
        }

        return $problems === []
            ? [ReadinessState::Ready, 'Currency, timezone and campaign ownership are all known.']
            : [ReadinessState::NeedsAttention, ucfirst(implode('; ', $problems)).'.'];
    }

    /** @return array{0: ReadinessState, 1: string} */
    private function approvals(string $workspace, string $projectId): array
    {
        $waiting = $this->facts->stuckApprovals($workspace, $projectId, $this->clock->now()->modify('-7 days'));

        return $waiting > 0
            ? [ReadinessState::NeedsAttention, sprintf('%d %s waiting more than a week.', $waiting, $waiting === 1 ? 'decision' : 'decisions')]
            : [ReadinessState::Ready, 'No decision is stuck.'];
    }
}
