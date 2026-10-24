<?php

declare(strict_types=1);

namespace App\Application\Platform\Query;

use App\Application\Platform\Contract\PlatformReadModel;
use App\Application\Platform\DTO\PlatformWorkspaceRow;
use App\Application\Platform\PlatformAccess;
use App\Domain\Identity\UserId;

final readonly class ListPlatformWorkspaces
{
    public function __construct(
        private PlatformAccess $access,
        private PlatformReadModel $platform,
    ) {}

    /** @return array{rows: list<PlatformWorkspaceRow>, total: int} */
    public function __invoke(UserId $caller, ?string $search, ?string $status, int $page, int $perPage = 25): array
    {
        $this->access->ensure($caller);

        return $this->platform->workspaces($search, $status, max(1, $page), min(100, max(1, $perPage)));
    }
}
