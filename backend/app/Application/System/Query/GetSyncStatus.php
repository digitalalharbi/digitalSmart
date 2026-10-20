<?php

declare(strict_types=1);

namespace App\Application\System\Query;

use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\System\Contract\DeploymentRecord;
use App\Application\System\Contract\RepositoryStatusSource;
use App\Domain\Access\Permission;

/**
 * Do GitHub, `main` and this installation agree — as three dots, for the people who run the workspace.
 *
 * Internal by design: it needs `workspaces.view`, and it names no token, no run id and no log. Local checkout
 * state (the branch on somebody's laptop) is deliberately absent — a deployed application cannot know it, and
 * `scripts/sync-status.sh` is where it lives.
 *
 * Deployment is `waiting` while nothing was ever deployed, which is the honest state until a server exists, and
 * `behind` (amber) when `main` has moved past what is live.
 */
final readonly class GetSyncStatus
{
    public function __construct(
        private RepositoryStatusSource $repository,
        private DeploymentRecord $deployment,
        private AuthorizationGuard $guard,
    ) {}

    /** @return array{main: array{state: string, label: string, sha: string|null}, deploy: array{state: string, label: string, sha: string|null}} */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesView);

        $run = $this->repository->latestRun('main', 'CI');
        $main = match ($run['state'] ?? null) {
            'green' => ['state' => 'green', 'label' => 'green'],
            'amber' => ['state' => 'amber', 'label' => 'running'],
            'red' => ['state' => 'red', 'label' => 'failed'],
            default => ['state' => 'grey', 'label' => 'unknown'],
        } + ['sha' => $run['sha'] ?? null];

        $deployed = $this->deployment->deployedSha();

        if ($deployed === null) {
            $deploy = ['state' => 'grey', 'label' => 'waiting', 'sha' => null];
        } elseif ($main['sha'] === null) {
            $deploy = ['state' => 'grey', 'label' => 'unknown', 'sha' => $deployed];
        } elseif (str_starts_with($main['sha'], $deployed) || str_starts_with($deployed, $main['sha'])) {
            $deploy = ['state' => 'green', 'label' => 'live', 'sha' => $deployed];
        } else {
            $deploy = ['state' => 'amber', 'label' => 'behind main', 'sha' => $deployed];
        }

        return ['main' => $main, 'deploy' => $deploy];
    }
}
