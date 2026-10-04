<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Every capability the platform can grant.
 *
 * This enum is the single source of truth: permissions are seeded from it, roles
 * reference it, and the authorization guard checks it. A permission that exists
 * here but is never enforced is a lie, so each one is asserted by a test.
 */
enum Permission: string
{
    // Workspace
    case WorkspacesView = 'workspaces.view';
    case WorkspacesUpdate = 'workspaces.update';
    case WorkspacesManageMembers = 'workspaces.manage_members';

    // Access control
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    // People
    case UsersView = 'users.view';
    case UsersInvite = 'users.invite';
    case UsersUpdate = 'users.update';
    case UsersDeactivate = 'users.deactivate';

    // Clients
    case ClientsView = 'clients.view';
    case ClientsCreate = 'clients.create';
    case ClientsUpdate = 'clients.update';
    case ClientsDelete = 'clients.delete';

    // Projects
    case ProjectsViewAll = 'projects.view_all';
    case ProjectsViewAssigned = 'projects.view_assigned';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';
    case ProjectsManageMembers = 'projects.manage_members';

    // Auditability
    case AuditView = 'audit.view';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    public function description(): string
    {
        return match ($this) {
            self::WorkspacesView => 'View workspace settings',
            self::WorkspacesUpdate => 'Change workspace settings',
            self::WorkspacesManageMembers => 'Add, suspend and remove workspace members',
            self::RolesView => 'View roles and their permissions',
            self::RolesManage => 'Create and change roles',
            self::UsersView => 'View people in the workspace',
            self::UsersInvite => 'Invite new people',
            self::UsersUpdate => 'Change a person\'s profile or role',
            self::UsersDeactivate => 'Deactivate a person',
            self::ClientsView => 'View clients',
            self::ClientsCreate => 'Create clients',
            self::ClientsUpdate => 'Change clients',
            self::ClientsDelete => 'Archive or delete clients',
            self::ProjectsViewAll => 'View every project in the workspace',
            self::ProjectsViewAssigned => 'View projects they are assigned to',
            self::ProjectsCreate => 'Create projects',
            self::ProjectsUpdate => 'Change projects',
            self::ProjectsDelete => 'Archive or delete projects',
            self::ProjectsManageMembers => 'Assign people to projects',
            self::AuditView => 'Read the audit log',
        };
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
