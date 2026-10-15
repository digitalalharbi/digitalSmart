<?php

declare(strict_types=1);

namespace App\Application\Integration\Query;

use App\Application\Integration\Contract\ConnectionDirectory;
use App\Application\Integration\Contract\OperationsReadModel;
use App\Application\Integration\Contract\SyncCandidateSource;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Integration\Sync\SyncClass;
use App\Domain\Integration\Sync\SyncPlanner;

/**
 * Is the integration layer healthy, in two registers.
 *
 * `summary` is for anybody who can look at integrations: healthy, needs reconnect, sync trouble, stale
 * accounts, last and next sync, in plain counts. `advanced` is for the people who can act on it — queue depth,
 * per-provider error rate, the failing runs with their codes. Rule 113: a project manager is told "an account
 * needs attention", an administrator may open the HTTP code and the provider's own words.
 *
 * Staleness is not a second definition. It is the planner's own *recovery* class — an active account silent
 * for three cadences — so the monitor and the scheduler can never disagree about which accounts are stuck.
 */
final readonly class GetIntegrationOperations
{
    public const array QUEUES = ['sync-recovery', 'sync', 'sync-low', 'sync-backfill', 'commerce', 'webhooks'];

    public function __construct(
        private ConnectionDirectory $connections,
        private OperationsReadModel $operations,
        private SyncCandidateSource $candidates,
        private SyncPlanner $planner,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesView);

        $workspace = $this->actor->workspaceId();
        $now = $this->clock->now();
        $advanced = $this->actor->permissions()->has(Permission::WorkspacesUpdate);

        $connections = $this->connections->connections($workspace->value, false);
        $runs = $this->operations->runs($workspace->value, $now->modify('-24 hours'), 20);

        $candidates = $this->candidates->candidates($workspace);
        $plan = $this->planner->plan($candidates, $now);

        $stale = array_values(array_filter(
            $plan,
            static fn ($planned): bool => $planned->class === SyncClass::Recovery,
        ));

        $nextDue = null;

        foreach ($candidates as $candidate) {
            if (! $candidate->hasLiveProject || $candidate->lastSuccessAt === null) {
                continue;
            }

            $cadence = ($candidate->activeCampaigns > 0 ? SyncClass::Live : SyncClass::Dormant)->cadenceMinutes() ?? 60;
            $due = $candidate->lastSuccessAt->modify(sprintf('+%d minutes', $cadence));
            $nextDue = $nextDue === null || $due < $nextDue ? $due : $nextDue;
        }

        $states = ['healthy' => 0, 'needs_reconnect' => 0, 'sync_issue' => 0, 'awaiting_access' => 0];

        foreach ($connections as $connection) {
            $states[$connection->state->value]++;
        }

        $failedAccounts = array_unique(array_column($runs['failures'], 'account_id'));

        $summary = [
            'connections' => $states,
            'accounts_with_sync_trouble' => count($failedAccounts),
            'stale_accounts' => count($stale),
            'accounts_syncing' => count($candidates),
            'last_sync_at' => $runs['last_finished_at'],
            'next_sync_at' => $nextDue === null ? null : max($nextDue, $now)->format(DATE_ATOM),
        ];

        if (! $advanced) {
            return ['summary' => $summary, 'advanced' => null];
        }

        $depth = $this->operations->queueDepth(self::QUEUES);

        return [
            'summary' => $summary,
            'advanced' => [
                'queue_depth' => $depth,
                'providers' => array_map(static fn (array $p): array => $p + [
                    'error_rate' => $p['runs'] === 0 ? null : round($p['failed'] / $p['runs'], 3),
                ], $runs['providers']),
                'failures' => $runs['failures'],
                'stale' => array_map(static fn ($p): array => [
                    'account_id' => $p->candidate->externalAccountId,
                    'provider' => $p->candidate->provider->value,
                    'last_success_at' => $p->candidate->lastSuccessAt?->format(DATE_ATOM),
                ], $stale),
                'window_hours' => 24,
            ],
        ];
    }
}
