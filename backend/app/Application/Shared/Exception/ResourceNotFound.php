<?php

declare(strict_types=1);

namespace App\Application\Shared\Exception;

use RuntimeException;

/**
 * The resource does not exist, or is not visible to the actor.
 *
 * Both cases deliberately produce the same 404: confirming that a record exists
 * in another workspace is itself a disclosure, since competitors can be clients
 * of the same installation.
 */
final class ResourceNotFound extends RuntimeException
{
    public static function of(string $resource): self
    {
        return new self(sprintf('%s not found.', $resource));
    }
}
