<?php

declare(strict_types=1);

namespace App\Application\Workspace\Exception;

use RuntimeException;

/**
 * A workspace must always retain at least one active owner.
 *
 * Without this, an administrator can lock everybody out of a tenant — including
 * themselves — with no in-product way back in.
 */
final class LastOwnerProtected extends RuntimeException
{
    public static function cannotDemote(): self
    {
        return new self('This is the workspace\'s last owner. Promote another owner first.');
    }

    public static function cannotSuspend(): self
    {
        return new self('This is the workspace\'s last owner and cannot be suspended or removed.');
    }
}
