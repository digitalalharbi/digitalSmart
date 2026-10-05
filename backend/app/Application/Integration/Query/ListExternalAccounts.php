<?php

declare(strict_types=1);

namespace App\Application\Integration\Query;

use App\Application\Integration\Contract\IntegrationReadModel;
use App\Application\Integration\DTO\ExternalAccountView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;

final readonly class ListExternalAccounts
{
    public function __construct(
        private IntegrationReadModel $readModel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<ExternalAccountView> */
    public function __invoke(?Provider $provider = null): array
    {
        $this->guard->ensure(Permission::WorkspacesView);

        return $this->readModel->accountsForWorkspace($this->actor->workspaceId(), $provider);
    }
}
