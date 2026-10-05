<?php

declare(strict_types=1);

namespace App\Application\Access\Contract;

/**
 * Bringing stored roles and permissions back in line with the code.
 *
 * The permission catalogue lives in an enum and roles are defined in code, but
 * both are *stored* per workspace. That gap is what this closes: without it, a
 * deploy that adds a permission reaches new workspaces only, and every existing
 * one silently lacks the feature with no error to explain why.
 */
interface AccessSynchroniser
{
    /** Upsert the permission catalogue from the enum. */
    public function syncCatalogue(): void;

    /**
     * Re-apply the system role definitions to stored roles.
     *
     * Converging, not additive: a permission the code no longer grants a role is
     * removed, because a role that kept one would be a quiet privilege
     * escalation. Roles a workspace created for itself are left alone.
     *
     * @param  string|null  $workspaceId  one workspace, or null for every one
     * @return list<string> the names of the workspaces brought up to date
     */
    public function syncSystemRoles(?string $workspaceId = null): array;
}
