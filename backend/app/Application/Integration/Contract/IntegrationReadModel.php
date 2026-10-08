<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Application\Integration\DTO\BindingView;
use App\Application\Integration\DTO\ExternalAccountView;
use App\Domain\Integration\Provider;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;

interface IntegrationReadModel
{
    /** @return list<ExternalAccountView> */
    public function accountsForWorkspace(WorkspaceId $workspaceId, ?Provider $provider): array;

    /** @return list<BindingView> */
    public function bindingsForProject(WorkspaceId $workspaceId, ProjectId $projectId): array;

    /*
     * No `bindingCounts()` and no `accountExists()`. Both were declared, implemented and never called.
     *
     * The first was the worse of the two: `accountsForWorkspace` already counts bindings per row in the same
     * query, so a separate map was a second way to compute a number the surface already had — which is not dead
     * code so much as a divergence waiting to be noticed by a client.
     */

    /** @return array<string, int> provider value => number of discovered accounts */
    public function accountCountsByProvider(WorkspaceId $workspaceId): array;
}
