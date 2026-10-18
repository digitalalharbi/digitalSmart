<?php

declare(strict_types=1);

namespace App\Application\Agents\Contract;

/**
 * The identity agents act as inside a workspace.
 *
 * An agent is a member of the workspace with its own role — read what analytics, budgets and tracking show, put
 * tasks on the board and *request* approvals, and nothing else. It cannot decide an approval or carry out a platform
 * write, because those permissions are simply not in its role; the limit is the same one a person has.
 */
interface AgentActors
{
    /** The agent user's id for this workspace, created on first use. */
    public function ensure(string $workspaceId): string;
}
