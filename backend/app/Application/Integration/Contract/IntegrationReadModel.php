<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Application\Integration\DTO\BindingView;
use App\Application\Integration\DTO\ExternalAccountView;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\Provider;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;

interface IntegrationReadModel
{
    /** @return list<ExternalAccountView> */
    public function accountsForWorkspace(WorkspaceId $workspaceId, ?Provider $provider): array;

    /** @return list<BindingView> */
    public function bindingsForProject(WorkspaceId $workspaceId, ProjectId $projectId): array;

    /** @return array<string, int> external account id => number of bindings */
    public function bindingCounts(WorkspaceId $workspaceId): array;

    public function accountExists(WorkspaceId $workspaceId, ExternalAccountId $id): bool;

    /** @return array<string, int> provider value => number of discovered accounts */
    public function accountCountsByProvider(WorkspaceId $workspaceId): array;
}
