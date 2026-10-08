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
    case ApprovalsView = 'approvals.view';
    /*
     * Requesting and deciding are separate, and this is the separation the module exists for: somebody who
     * could approve their own spend change has an approvals screen rather than an approvals process. Some
     * kinds need a second permission on top of `approvals.decide` — see `ApprovalKind::decidedWith()`.
     */
    case ApprovalsRequest = 'approvals.request';
    case ApprovalsDecide = 'approvals.decide';
    /*
     * Three permissions, and the split is what makes a client portal possible at all. `view` opens the agency's
     * intake queue, which carries internal notes; `submit` only lets somebody raise a request and read the
     * client-facing side of their own. A client viewer holds `submit` and not `view`, and the absence of `view`
     * is what the read model uses to decide that internal messages are excluded in SQL.
     */
    case ClientRequestsView = 'client_requests.view';
    case ClientRequestsSubmit = 'client_requests.submit';
    case ClientRequestsTriage = 'client_requests.triage';

    // Reporting
    case ReportsView = 'reports.view';
    case ReportsManage = 'reports.manage';
    /*
     * Sharing a report outside the workspace is its own permission, and the one that matters: `manage` builds a
     * document, `share` hands a client's performance data to somebody with a link. They are different acts and the
     * second is the one somebody will be asked about.
     */
    case ReportsShare = 'reports.share';

    // Provider write operations
    /*
     * Asking a platform to change something, for a request that has already been approved.
     *
     * Its own permission, and separate from `approvals.decide` on purpose: deciding that a change *should*
     * happen and being the person who makes it happen are two acts, and this product's whole approvals argument
     * rests on their being two. Held by the roles that run accounts — somebody who would otherwise be making the
     * same change by hand in the platform's own interface.
     */
    case ProviderWritesExecute = 'provider_writes.execute';

    // Client portal
    /*
     * Two permissions, and the split matters more here than anywhere else in this enum.
     *
     * `view` answers "is anything exposed to this client, and what" — a question anybody handling the account
     * asks, often while a client is on the phone. `manage` *changes* what somebody outside the agency can read,
     * which is a disclosure decision and the kind of act somebody is later asked to account for.
     */
    case PortalView = 'portal.view';
    case PortalManage = 'portal.manage';
    /*
     * Held by the client's own people, and by nobody in the agency.
     *
     * It exists because nothing else in this product says "this person belongs to the client rather than to the
     * agency". Project assignment does not: a creative is assigned to a client's project and is plainly not their
     * employee. Inferring it from the absence of some other permission — the trick the intake queue uses, and
     * documents — would make the portal's reader depend on a role's unrelated grants, so that adding a permission
     * to a role could silently make somebody a client.
     *
     * A reader holding this gets the client side of the portal, over the clients whose projects they are assigned
     * to. `portal.view` gets the same pages as a preview. Holding neither means there is nothing to read.
     */
    case PortalRead = 'portal.read';

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
            self::ApprovalsView => 'View the approval queue, and what has been approved but not yet done',
            self::ApprovalsRequest => 'Ask for approval, withdraw a request, and record that a change was made',
            self::ApprovalsDecide => 'Approve or decline a requested change',
            self::ClientRequestsView => 'View the client intake queue, including internal notes on it',
            self::ClientRequestsSubmit => 'Raise a request, and read the client-facing side of it',
            self::ClientRequestsTriage => 'Accept, decline, promise a date for and deliver a client request',
            self::ReportsView => 'View saved reports and run them',
            self::ReportsManage => 'Build, change and delete reports, and take snapshots',
            self::ReportsShare => 'Create and revoke links that let somebody outside the workspace read a report',
            self::ProviderWritesExecute => 'Carry out an approved change on an advertising platform',
            self::PortalView => 'See which surfaces each client\'s portal exposes',
            self::PortalManage => 'Switch a client\'s portal on or off, and change which surfaces it exposes',
            self::PortalRead => 'Read a client portal as the client, for the clients you are assigned to',
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
