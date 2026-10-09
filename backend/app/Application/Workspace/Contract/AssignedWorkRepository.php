<?php

declare(strict_types=1);

namespace App\Application\Workspace\Contract;

use App\Application\Workspace\DTO\HeldWork;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;

/**
 * What a person still has in their name.
 *
 * Four tables carry an assignment — `tasks.assignee_id`, `leads.owner_id`, `client_requests.owner_id` and
 * `creative_requests.owner_id` — and a schema sweep is what found the fourth. This contract exists so that
 * removing somebody from a workspace is not the quiet creation of four kinds of orphan.
 */
interface AssignedWorkRepository
{
    /** What this person currently holds that is still open. */
    public function heldBy(WorkspaceId $workspaceId, UserId $userId): HeldWork;

    /**
     * Take their name off everything open and report what was released.
     *
     * Open work only. A task somebody finished is a record of who did it, and rewriting that to say nobody did
     * would be falsifying history to tidy a column — the opposite of what this method is for.
     */
    public function release(WorkspaceId $workspaceId, UserId $userId): HeldWork;
}
