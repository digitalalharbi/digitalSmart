<?php

declare(strict_types=1);

namespace App\Application\Platform\Contract;

use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;

/** Who may operate the platform. A flag on the person, never a role in a workspace. */
interface PlatformOperators
{
    public function isOperator(UserId $userId): bool;

    /** Grant or withdraw. Returns the person's id, or null when nobody has that address. */
    public function set(Email $email, bool $operator): ?string;
}
