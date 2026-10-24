<?php

declare(strict_types=1);

namespace App\Application\Platform\Contract;

use App\Application\Platform\DTO\CreatedWorkspace;
use App\Application\Platform\DTO\NewWorkspace;

/**
 * Create a tenant with its roles, its plan and its owner, all or nothing. The owner joins on an existing account when the address
 * already has one; otherwise an account is made with a one-time password that must be replaced on first sign-in.
 */
interface WorkspaceCreator
{
    public function create(NewWorkspace $workspace): CreatedWorkspace;
}
