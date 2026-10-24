<?php

declare(strict_types=1);

namespace App\Application\Platform\Query;

use App\Application\Platform\Contract\PlatformReadModel;
use App\Application\Platform\DTO\PlatformWorkspaceRow;
use App\Application\Platform\PlatformAccess;
use App\Application\Saas\DTO\WorkspacePlanView;
use App\Application\Saas\Query\GetWorkspacePlan;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Identity\UserId;

/**
 * One tenant in full, for an operator: its counts and states, its plan with usage, and its commercial history. Still no client
 * name, figure, message or credential — operating the platform does not need them.
 */
final readonly class GetPlatformWorkspace
{
    public function __construct(
        private PlatformAccess $access,
        private PlatformReadModel $platform,
        private GetWorkspacePlan $plan,
    ) {}

    /** @return array{row: PlatformWorkspaceRow, plan: WorkspacePlanView, history: list<array{action: string, summary: string|null, at: string}>} */
    public function __invoke(UserId $caller, string $workspaceId): array
    {
        $this->access->ensure($caller);

        $row = $this->platform->find($workspaceId) ?? throw ResourceNotFound::of('Workspace');

        return ['row' => $row, 'plan' => $this->plan->forWorkspace($workspaceId), 'history' => $this->platform->history($workspaceId)];
    }
}
