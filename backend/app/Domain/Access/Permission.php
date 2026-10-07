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

    // Analytics
    case AnalyticsView = 'analytics.view';
    case AnalyticsExport = 'analytics.export';
    case SavedViewsManage = 'saved_views.manage';

    // Budgets
    case BudgetsView = 'budgets.view';
    case BudgetsManage = 'budgets.manage';
    /*
     * Separate from managing, deliberately. Drafting a plan and committing a client to
     * a figure are different acts: a media buyer should be able to reshape a plan all
     * day and still not be the person who says "the client agreed to this".
     */
    case BudgetsApprove = 'budgets.approve';

    // Measurement and tracking
    case LeadsView = 'leads.view';
    case LeadsManage = 'leads.manage';
    case TrackingView = 'tracking.view';
    case TrackingManage = 'tracking.manage';

    // Creative operations
    case CreativeView = 'creative.view';
    case CreativeManage = 'creative.manage';
    /*
     * Separate from managing, for the same reason approving a budget is: producing the work and signing
     * it off are different acts, and the separation is the only thing that makes "approved" mean
     * anything on an approval trail.
     */
    case CreativeApprove = 'creative.approve';

    // Operations
    case TasksView = 'tasks.view';
    /*
     * One permission for creating, moving and assigning. Deliberately not split: unlike approving a
     * budget or signing off a version, nothing about moving a card is a judgement somebody else needs
     * protecting from. A board where only a manager may tick something done is a board kept by hand.
     */
    case TasksManage = 'tasks.manage';

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
            self::TrackingView => 'View what measures a client\'s work, and whether it is healthy',
            self::TrackingManage => 'Define conversions and map the events that mean them',
            self::BudgetsView => 'View budgets and how they are pacing',
            self::BudgetsManage => 'Create budgets and allocate them across platforms and campaigns',
            self::BudgetsApprove => 'Approve a client budget, fixing the figure that was agreed',
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
            self::LeadsView => 'View the lead pipeline',
            self::LeadsManage => 'Work and qualify leads',
            self::CreativeView => 'View creative requests, versions and their approval trail',
            self::CreativeManage => 'Brief creative work, move it along and upload versions',
            self::CreativeApprove => 'Approve a version, or send it back with a reason',
            self::TasksView => 'View the operations board and what it is carrying',
            self::TasksManage => 'Create tasks, move them, assign them and set their dates',
            self::AnalyticsView => 'View performance analytics',
            self::AnalyticsExport => 'Export analytics data',
            self::SavedViewsManage => 'Create and share saved views',
            self::AuditView => 'Read the audit log',
        };
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
