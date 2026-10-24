<?php

declare(strict_types=1);

namespace App\Application\Shared\Exception;

use App\Domain\Access\Permission;
use RuntimeException;

/**
 * The actor is authenticated but not allowed.
 *
 * The Interface layer maps this to 403 — except where disclosing existence
 * would itself leak across a tenant boundary, where ResourceNotFound is thrown
 * instead.
 */
final class AuthorizationDenied extends RuntimeException
{
    private function __construct(string $message, public readonly ?string $permission = null)
    {
        parent::__construct($message);
    }

    public static function missingPermission(Permission $permission): self
    {
        return new self(
            sprintf('This action requires the "%s" permission.', $permission->value),
            $permission->value,
        );
    }

    /** @param list<Permission> $permissions */
    public static function missingAnyPermission(array $permissions): self
    {
        $slugs = array_map(static fn (Permission $p): string => $p->value, $permissions);

        return new self(sprintf(
            'This action requires one of the following permissions: %s.',
            implode(', ', $slugs),
        ));
    }

    /** The platform console is for platform operators; membership of any workspace grants nothing there. */
    public static function notPlatformOperator(): self
    {
        return new self('Only a platform operator can do this.');
    }

    /** The caller holds no active membership of any workspace. */
    public static function noActiveWorkspace(): self
    {
        return new self('You do not have an active membership of any workspace.');
    }

    public static function notAssignedToProject(): self
    {
        return new self('You are not assigned to this project.');
    }
}
