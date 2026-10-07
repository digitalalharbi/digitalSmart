<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Roles every workspace starts with.
 *
 * Workspaces may add their own roles later; these are the templates seeded on
 * creation, and they encode the agency reality the product is built for:
 * managers run projects, buyers execute them, creatives produce for them, and
 * clients see only their own.
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case ProjectManager = 'project-manager';
    case MediaBuyer = 'media-buyer';
    case Creative = 'creative';
    case ClientViewer = 'client-viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::ProjectManager => 'Project manager',
            self::MediaBuyer => 'Media buyer',
            self::Creative => 'Creative',
            self::ClientViewer => 'Client viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full control of the workspace, including its settings and people.',
            self::Admin => 'Manages people, clients and projects across the workspace.',
            self::ProjectManager => 'Runs clients and projects, and assigns the people working on them.',
            self::MediaBuyer => 'Executes the projects they are assigned to.',
            self::Creative => 'Produces work for the projects they are assigned to.',
            self::ClientViewer => 'Sees only the projects shared with them. No internal data.',
        };
    }

    /**
     * Permissions granted by this role.
     *
     * Deliberately explicit rather than hierarchical: a reader can see exactly
     * what a role can do without tracing inheritance.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),

            self::Admin => [
                Permission::WorkspacesView,
                Permission::WorkspacesUpdate,
                Permission::WorkspacesManageMembers,
                Permission::RolesView,
                Permission::RolesManage,
                Permission::UsersView,
                Permission::UsersInvite,
                Permission::UsersUpdate,
                Permission::UsersDeactivate,
                Permission::ClientsView,
                Permission::ClientsCreate,
                Permission::ClientsUpdate,
                Permission::ClientsDelete,
                Permission::ProjectsViewAll,
                Permission::ProjectsViewAssigned,
                Permission::ProjectsCreate,
                Permission::ProjectsUpdate,
                Permission::ProjectsDelete,
                Permission::ProjectsManageMembers,
                Permission::AnalyticsView,
                Permission::AnalyticsExport,
                Permission::SavedViewsManage,
                Permission::BudgetsView,
                Permission::BudgetsManage,
                Permission::BudgetsApprove,
                Permission::TrackingView,
                Permission::TrackingManage,
                Permission::LeadsView,
                Permission::LeadsManage,
                Permission::CreativeView,
                Permission::CreativeManage,
                Permission::CreativeApprove,
                Permission::AuditView,
            ],

            self::ProjectManager => [
                Permission::WorkspacesView,
                Permission::RolesView,
                Permission::UsersView,
                Permission::ClientsView,
                Permission::ClientsCreate,
                Permission::ClientsUpdate,
                Permission::ProjectsViewAll,
                Permission::ProjectsViewAssigned,
                Permission::ProjectsCreate,
                Permission::ProjectsUpdate,
                Permission::ProjectsManageMembers,
                Permission::AnalyticsView,
                Permission::AnalyticsExport,
                Permission::SavedViewsManage,
                /*
                 * A project manager agrees the budget with the client, so approval sits
                 * with the role that has that conversation.
                 */
                Permission::BudgetsView,
                Permission::BudgetsManage,
                Permission::BudgetsApprove,
                Permission::TrackingView,
                Permission::TrackingManage,
                /*
                 * A project manager runs the pipeline conversation with the client, so working and
                 * qualifying leads sits with the role that has it.
                 */
                Permission::LeadsView,
                Permission::LeadsManage,
                /*
                 * Briefs the work and signs it off, because they are the person in the conversation with
                 * the client about whether it is right.
                 */
                Permission::CreativeView,
                Permission::CreativeManage,
                Permission::CreativeApprove,
                Permission::AuditView,
            ],

            self::MediaBuyer => [
                Permission::WorkspacesView,
                Permission::UsersView,
                Permission::ClientsView,
                Permission::ProjectsViewAssigned,
                Permission::ProjectsUpdate,
                Permission::AnalyticsView,
                Permission::AnalyticsExport,
                Permission::SavedViewsManage,
                /*
                 * Allocates and re-plans within a budget, but does not approve one. That
                 * separation is the only thing making "approved" mean anything.
                 */
                Permission::BudgetsView,
                Permission::BudgetsManage,
                /*
                 * A buyer sets up the pixels and the conversions, so they define them too.
                 * Measurement is the work, not an administrative afterthought.
                 */
                Permission::TrackingView,
                Permission::TrackingManage,
                /*
                 * A buyer reads the pipeline, because a cost per *qualified* lead is the only honest
                 * measure of their work — and does not qualify leads themselves. Judging the quality of
                 * what you bought is not a judgement to hand to the buyer.
                 */
                Permission::LeadsView,
                /*
                 * Reads the creative that will run on their campaigns, and signs nothing off. A buyer
                 * approving the work they asked for removes the only check on it.
                 */
                Permission::CreativeView,
            ],

            self::Creative => [
                Permission::WorkspacesView,
                Permission::UsersView,
                Permission::ClientsView,
                Permission::ProjectsViewAssigned,
                /*
                 * The role the creative surface exists for: reads the brief, uploads versions, moves the
                 * work along — and does not approve its own work, which is the whole point of a trail.
                 */
                Permission::CreativeView,
                Permission::CreativeManage,
            ],

            /*
             * Clients see their own projects and nothing else. No user
             * directory, no other clients, no audit trail.
             *
             * Analytics is granted, but it is not workspace-wide: without
             * ProjectsViewAll every analytics query is narrowed to the
             * projects the actor is assigned to, in SQL. The permission opens
             * the screen; the assignment decides what is on it.
             */
            self::ClientViewer => [
                Permission::ProjectsViewAssigned,
                Permission::AnalyticsView,
                /*
                 * No budget permission, deliberately. A budget carries internal notes
                 * and an agency's own allocation thinking; what a client may see is
                 * decided explicitly by the client portal's sharing, not by a role that
                 * happens to open the screen.
                 */
            ],
        };
    }

    /** Owner is protected: a workspace must always retain one. */
    public function isProtected(): bool
    {
        return $this === self::Owner;
    }
}
