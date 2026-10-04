<?php

declare(strict_types=1);

namespace App\Application\Audit\Contract;

use App\Application\Audit\DTO\AuditEntryView;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Workspace\WorkspaceId;

interface AuditReadModel
{
    /**
     * @param  list<string>  $actions
     * @return Paginated<AuditEntryView>
     */
    public function paginate(
        WorkspaceId $workspaceId,
        ?string $search,
        array $actions,
        ?string $actorId,
        ?bool $securityOnly,
        Pagination $pagination,
    ): Paginated;
}
