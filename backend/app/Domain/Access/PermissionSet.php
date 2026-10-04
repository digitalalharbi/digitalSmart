<?php

declare(strict_types=1);

namespace App\Domain\Access;

/** The resolved set of permissions a member holds in one workspace. */
final readonly class PermissionSet
{
    /** @var array<string, true> */
    private array $index;

    /** @param iterable<Permission> $permissions */
    public function __construct(iterable $permissions)
    {
        $index = [];

        foreach ($permissions as $permission) {
            $index[$permission->value] = true;
        }

        $this->index = $index;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param list<string> $slugs */
    public static function fromSlugs(array $slugs): self
    {
        $permissions = [];

        foreach ($slugs as $slug) {
            $permission = Permission::tryFrom($slug);

            // An unknown slug is stale data, never an implicit grant.
            if ($permission instanceof Permission) {
                $permissions[] = $permission;
            }
        }

        return new self($permissions);
    }

    public function has(Permission $permission): bool
    {
        return isset($this->index[$permission->value]);
    }

    public function hasAny(Permission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->has($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAll(Permission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->has($permission)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return array_keys($this->index);
    }

    public function isEmpty(): bool
    {
        return $this->index === [];
    }
}
