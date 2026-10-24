<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Application\Platform\Contract\PlatformOperators;
use App\Application\Shared\Exception\AuthorizationDenied;
use App\Domain\Identity\UserId;

/**
 * The platform's own gate. It is not a workspace permission and no workspace role reaches it: an owner of every workspace on the
 * installation is still refused here, and an operator is still a stranger inside any workspace they are not a member of.
 */
final readonly class PlatformAccess
{
    public function __construct(private PlatformOperators $operators) {}

    public function ensure(UserId $userId): void
    {
        if (! $this->operators->isOperator($userId)) {
            throw AuthorizationDenied::notPlatformOperator();
        }
    }
}
