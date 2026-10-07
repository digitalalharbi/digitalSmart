<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Auditable actions.
 *
 * An enum rather than free text, so the log is queryable and a typo cannot
 * silently create a new "action" that no report counts.
 */
enum AuditAction: string
{
    case UserLoggedIn = 'user.logged_in';
    case UserLoginFailed = 'user.login_failed';
    case UserLoggedOut = 'user.logged_out';
    case UserPasswordChanged = 'user.password_changed';

    case ClientCreated = 'client.created';
    case ClientUpdated = 'client.updated';
    case ClientStatusChanged = 'client.status_changed';
    case ClientDeleted = 'client.deleted';

    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectStatusChanged = 'project.status_changed';
    case ProjectDeleted = 'project.deleted';
    case ProjectMemberAssigned = 'project.member_assigned';
    case ProjectMemberRemoved = 'project.member_removed';

    case IntegrationConnected = 'integration.connected';
    case IntegrationCredentialsUpdated = 'integration.credentials_updated';
    case IntegrationDisconnected = 'integration.disconnected';
    case IntegrationAccountsDiscovered = 'integration.accounts_discovered';
    case IntegrationAccountBound = 'integration.account_bound';
    case IntegrationAccountUnbound = 'integration.account_unbound';
    case IntegrationSyncToggled = 'integration.sync_toggled';

    /*
     * Budgets. The spec calls for an immutable change log on them, and this is it:
     * every amount, period and allocation change lands here, append-only, with the
     * before and after. A separate budget-history table would be a second audit trail
     * to keep honest.
     */
    case BudgetCreated = 'budget.created';
    case BudgetUpdated = 'budget.updated';
    case BudgetApproved = 'budget.approved';
    case BudgetArchived = 'budget.archived';
    case BudgetDeleted = 'budget.deleted';
    case BudgetAllocationsChanged = 'budget.allocations_changed';

    /*
     * Measurement. A conversion definition decides what every report on a project counts, so
     * changing one changes history as read by everybody — worth a record with an author.
     */
    case MeasurementSourceSaved = 'measurement.source_saved';
    case MeasurementSourceDeleted = 'measurement.source_deleted';
    case LeadRecorded = 'lead_recorded';
    case LeadStageChanged = 'lead_stage_changed';
    case LeadAssigned = 'lead_assigned';
    case ConversionDefined = 'measurement.conversion_defined';
    case ConversionChanged = 'measurement.conversion_changed';
    case ConversionDeleted = 'measurement.conversion_deleted';

    case CreativeRequested = 'creative.requested';
    case CreativeMoved = 'creative.moved';
    case CreativeVersionAdded = 'creative.version_added';
    /*
     * The entry an approval trail is for. It names the version, not the request: "approved" without a
     * version number is the claim a client disputes three months later and nobody can settle.
     */
    case CreativeDecided = 'creative.decided';

    case WorkspaceMemberInvited = 'workspace.member_invited';
    case WorkspaceMemberRoleChanged = 'workspace.member_role_changed';
    case WorkspaceMemberSuspended = 'workspace.member_suspended';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    /** Security-relevant actions are retained longer and surfaced separately. */
    public function isSecurityEvent(): bool
    {
        return in_array($this, [
            self::UserLoggedIn,
            self::UserLoginFailed,
            self::UserLoggedOut,
            self::UserPasswordChanged,
            self::WorkspaceMemberRoleChanged,
            self::WorkspaceMemberSuspended,
            // Credentials and data-access grants are security events: who
            // connected a platform, and who let a project read an ad account.
            self::IntegrationConnected,
            self::IntegrationCredentialsUpdated,
            self::IntegrationDisconnected,
            self::IntegrationAccountBound,
            self::IntegrationAccountUnbound,
            /*
             * Approving a budget commits a client's money. Not a credential event, but
             * the one budget action that has to be as findable as one.
             */
            self::BudgetApproved,
        ], true);
    }
}
