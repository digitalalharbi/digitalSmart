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
                Permission::TasksView,
                Permission::TasksManage,
                Permission::ApprovalsView,
                Permission::ApprovalsRequest,
                Permission::ApprovalsDecide,
                Permission::ClientRequestsView,
                Permission::ClientRequestsSubmit,
                Permission::ClientRequestsTriage,
                Permission::ReportsView,
                Permission::ReportsManage,
                Permission::ReportsShare,
                Permission::PortalView,
                Permission::PortalManage,
                Permission::ProviderWritesExecute,
                Permission::CampaignBlueprintsView,
                Permission::CampaignBlueprintsCreate,
                Permission::CampaignBlueprintsUpdate,
                Permission::CampaignBlueprintsSubmit,
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
                /*
                 * Runs the board. The role that decides what the week is for is the role that puts the
                 * work on it.
                 */
                Permission::TasksView,
                Permission::TasksManage,
                /*
                 * Decides. The role in the conversation with the client about what their money does is the
                 * role that signs off changing it.
                 */
                Permission::ApprovalsView,
                Permission::ApprovalsRequest,
                Permission::ApprovalsDecide,
                Permission::ClientRequestsView,
                Permission::ClientRequestsSubmit,
                Permission::ClientRequestsTriage,
                /*
                 * Builds reports and sends them, and decides what the client's portal exposes. The role in the
                 * conversation with the client is the role that decides what that client is shown.
                 */
                Permission::ReportsView,
                Permission::ReportsManage,
                Permission::ReportsShare,
                Permission::PortalView,
                Permission::PortalManage,
                Permission::ProviderWritesExecute,
                Permission::CampaignBlueprintsView,
                Permission::CampaignBlueprintsCreate,
                Permission::CampaignBlueprintsUpdate,
                Permission::CampaignBlueprintsSubmit,
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
                /*
                 * Works the board rather than only reading it: a buyer who cannot tick their own task done
                 * is a buyer whose manager keeps the board by hand, and then the board is fiction.
                 */
                Permission::TasksView,
                Permission::TasksManage,
                /*
                 * Asks, and does not decide. A buyer approving their own change to a client's spend removes
                 * the only check on it — the same reason they may plan a budget and not approve one. They do
                 * record that they made the change, because they are the person who goes and makes it.
                 */
                Permission::ApprovalsView,
                Permission::ApprovalsRequest,
                /*
                 * Reads the intake queue and answers on it, and does not triage. Agreeing to work for a client
                 * and committing a date is the conversation a project manager owns.
                 */
                Permission::ClientRequestsView,
                Permission::ClientRequestsSubmit,
                /*
                 * Builds reports and does not share them. A buyer reporting on their own performance to a client,
                 * with nobody reading it first, is the one review this module exists to keep.
                 */
                Permission::ReportsView,
                Permission::ReportsManage,
                /*
                 * Sees what a client's portal exposes and does not change it. A buyer needs to know whether the
                 * client can already read this week's figures before answering a question about them; widening
                 * what somebody outside the agency can read is a different act, and one the account's owner takes.
                 */
                Permission::PortalView,
                /*
                 * And carries out approved changes. This is the role that would otherwise be making the same
                 * change by hand in the platform's own interface; the control that matters is the approval it
                 * cannot proceed without, not the keyboard it is typed on.
                 */
                Permission::ProviderWritesExecute,
                Permission::CampaignBlueprintsView,
                Permission::CampaignBlueprintsCreate,
                Permission::CampaignBlueprintsUpdate,
                Permission::CampaignBlueprintsSubmit,
            ],

            self::Creative => [
                /* A creative reads the plan their work is for; writing it is the media side's. */
                Permission::CampaignBlueprintsView,
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
                Permission::TasksView,
                Permission::TasksManage,
                /*
                 * Asks for a publish and records having done it. Approving their own work would undo the
                 * separation the creative trail is built on.
                 */
                Permission::ApprovalsView,
                Permission::ApprovalsRequest,
                /*
                 * No client-request access, deliberately. A creative's work arrives as a brief in the Creative
                 * Hub and as tasks on the board; the intake queue carries every client's commercial asks and the
                 * agency's internal reasoning about them, which is a wider view than the role needs. Narrowing a
                 * role to what it works from is the only version of least privilege that survives contact with a
                 * growing product.
                 */
            ],

            /*
             * Clients see their own projects and nothing else. No user
             * directory, no other clients, no audit trail.
             *
             * **No analytics permission.** It was granted until Phase 22, and
             * removing it is the point of that phase: a role that happens to
             * open a screen is not a decision anybody took about a particular
             * client. What a client may read is now the client portal's four
             * module grants, each one exposed deliberately, by a named person,
             * with an audit entry — and each one revocable without touching a
             * role that every client shares.
             *
             * The figures themselves are unchanged. The portal reads the same
             * analytics model with the same source states; it is the
             * authorization that moved.
             */
            self::ClientViewer => [
                Permission::ProjectsViewAssigned,
                /*
                 * What makes this role a client rather than a member of staff, stated rather than inferred. It
                 * is the only role that holds it, and the portal's client side is the only thing it opens.
                 */
                Permission::PortalRead,
                /*
                 * Submit, and deliberately **not** view. This is the one permission a client holds that writes
                 * anything, and it is the whole reason the intake queue exists: an intake only agency staff can
                 * write to is just another task list.
                 *
                 * The absence of `client_requests.view` is load-bearing. The read model treats a reader without
                 * it as client-facing and excludes internal notes **in SQL**, so an agency's note about a client
                 * is never selected for that reader at all — not filtered out afterwards.
                 */
                Permission::ClientRequestsSubmit,
                /*
                 * No task permission either. An agency's board carries its own reasons for things being
                 * blocked and its own estimates of how long work takes; a client seeing them is an
                 * internal conversation leaking. What a client may see is Phase 22's client portal,
                 * decided explicitly rather than by a role that happens to open a screen.
                 */
                /*
                 * No budget permission, deliberately. A budget carries internal notes
                 * and an agency's own allocation thinking; what a client may see is
                 * decided explicitly by the client portal's sharing, not by a role that
                 * happens to open the screen.
                 */
            ],
        };
    }

    /**
     * Every role's slug.
     *
     * Added when the add-a-colleague form needed to validate against the list: a free-string role would have made
     * a typo a `404` from the role lookup rather than a message on the field somebody typed it into.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Owner is protected: a workspace must always retain one. */
    public function isProtected(): bool
    {
        return $this === self::Owner;
    }
}
