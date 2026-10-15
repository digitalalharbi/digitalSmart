<?php

declare(strict_types=1);

namespace App\Application\Export\Query;

use App\Application\Export\Contract\ExportJobStore;
use App\Application\Export\DTO\ExportView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/** The requester's own exports. Nobody else's, whatever their role. */
final readonly class ListExports
{
    public function __construct(
        private ExportJobStore $jobs,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<ExportView> */
    public function __invoke(int $limit = 20): array
    {
        $this->guard->ensure(Permission::ReportsView);

        return $this->jobs->mine($this->actor->workspaceId()->value, $this->actor->userId()->value, max(1, min($limit, 50)));
    }
}
