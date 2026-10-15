<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Domain\Integration\Sync\SyncCandidate;
use App\Domain\Workspace\WorkspaceId;

interface SyncCandidateSource
{
    /**
     * Every account some project has asked to keep in sync, with what the planner needs to decide on it.
     *
     * One statement for the whole agency. The planner is a pure function over this list, so the cost of
     * deciding is the cost of reading it once.
     *
     * @return list<SyncCandidate>
     */
    public function candidates(?WorkspaceId $workspaceId = null): array;
}
