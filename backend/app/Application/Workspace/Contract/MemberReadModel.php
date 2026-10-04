<?php

declare(strict_types=1);

namespace App\Application\Workspace\Contract;

use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Application\Workspace\DTO\MemberView;
use App\Domain\Workspace\WorkspaceId;

interface MemberReadModel
{
    /** @return Paginated<MemberView> */
    public function paginate(
        WorkspaceId $workspaceId,
        ?string $search,
        ?string $roleSlug,
        ?string $status,
        Pagination $pagination,
    ): Paginated;
}
