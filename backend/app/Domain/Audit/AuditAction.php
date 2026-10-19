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
    /* The workspace's own language-model key or model names changed — recorded as a change, never the key. */
    case ModelCredentialsUpdated = 'model_credentials.updated';
    case IntegrationCredentialsUpdated = 'integration.credentials_updated';
    case IntegrationDisconnected = 'integration.disconnected';
    case IntegrationAccountsDiscovered = 'integration.accounts_discovered';
    case IntegrationAccountBound = 'integration.account_bound';
    case IntegrationAccountUnbound = 'integration.account_unbound';
    case IntegrationSyncToggled = 'integration.sync_toggled';
    case IntegrationConnectionReconnected = 'integration.connection_reconnected';
    case IntegrationAccountIgnored = 'integration.account_ignored';
    case IntegrationAccountRestored = 'integration.account_restored';
    case IntegrationBackfillRequested = 'integration.backfill_requested';

    /*
     * Which project owns a campaign. A data-access grant in everything but name: the campaign's spend joins
     * that project's figures, and the people on it gain sight of a campaign they could not see before.
     */
    case CampaignProjectAssigned = 'campaign.project_assigned';

    case ClientOffboarded = 'client.offboarded';
    case TagApplied = 'tag.applied';
    case TagRemoved = 'tag.removed';

    /*
     * Connecting a store to a client is a data-access decision as much as a setup one: from then on, every
     * order that merchant reports counts towards that client's revenue, and the people on that client's
     * projects can read its customers. Its own action, with the merchant id, because "who decided these orders
     * belong to Alhambra Retail" has to be answerable after the person who decided it has left.
     */
    case StoreConnected = 'store.connected';
    /* A read of somebody else's platform on the workspace's credentials, with what it brought back. */
    case StoreRead = 'store.read';

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
    /*
     * Merging two leads is the one lead operation with no undo: afterwards the events are on one record and a
     * split would have to invent a division nobody wrote down. The entry names the absorbed lead by id, because
     * from then on that is the only way to find it.
     */
    case LeadsMerged = 'leads_merged';
    /*
     * What somebody said a lead is worth, recorded with who said it. The figure feeds the pipeline's own
     * won-value total, so "where did this number come from" has to have an answer that outlives the person.
     */
    case LeadValued = 'lead_valued';
    case ConversionDefined = 'measurement.conversion_defined';
    case ConversionChanged = 'measurement.conversion_changed';
    case ConversionDeleted = 'measurement.conversion_deleted';

    case CreativeRequested = 'creative.requested';
    case CreativeMoved = 'creative.moved';
    /*
     * Who is making the work, which the board could show and nothing could change: the column was written once
     * by the composer and read for ever afterwards.
     */
    case CreativeOwnerChanged = 'creative.owner_changed';
    case CreativeVersionAdded = 'creative.version_added';
    /*
     * The entry an approval trail is for. It names the version, not the request: "approved" without a
     * version number is the claim a client disputes three months later and nobody can settle.
     */
    case CreativeDecided = 'creative.decided';

    case TaskOpened = 'task.opened';
    case TaskMoved = 'task.moved';
    /*
     * Its own action rather than part of `TaskUpdated`. Handing work to somebody is the entry a person
     * goes looking for when they want to know who was asked, and a generic "updated" buries it.
     */
    case TaskAssigned = 'task.assigned';
    case AutomationRuleSaved = 'automation_rule.saved';
    case OperatingRulesSaved = 'operating_rules.saved';
    case AgentToolCalled = 'agent.tool_called';
    case AgentProposed = 'agent.proposed';
    case AutomationRuleDeleted = 'automation_rule.deleted';
    case TaskUpdated = 'task.updated';

    case ApprovalRequested = 'approval.requested';
    case ApprovalDecided = 'approval.decided';
    case ApprovalAmended = 'approval.amended';
    case ApprovalWithdrawn = 'approval.withdrawn';
    /* A one-time link to decide an approval was sent to one person — never the link itself. */
    case ApprovalLinkIssued = 'approval.link_issued';
    /* A one-time link was used, or refused: expired, already used, changed proposal, permission gone. */
    case ApprovalLinkUsed = 'approval.link_used';
    /*
     * Its own action, and the one this module exists for: approval is not execution. The entry records that a
     * person says they made the change, with no claim that the product verified it.
     */
    case ApprovalEnacted = 'approval.enacted';

    case ClientRequestSubmitted = 'client_request.submitted';
    case ClientRequestMoved = 'client_request.moved';
    /*
     * Its own action. A promised date is the thing a client quotes back, so "who committed us to this" has to be
     * answerable from the record rather than from somebody's memory of a meeting.
     */
    case ClientRequestPromised = 'client_request.promised';
    case ClientRequestAnswered = 'client_request.answered';
    /*
     * Its own action, because a file is the one thing on a request that can be dangerous as well as
     * confidential: the entry carries the audience, the sniffed type and the size, which are the three fields an
     * argument about a leaked or a malicious upload turns on.
     */
    case ClientRequestAttached = 'client_request.attached';
    /* A download is a read, and a read of somebody else's file is the read worth recording. */
    case ClientRequestAttachmentRead = 'client_request.attachment_read';
    case ClientRequestOwnerChanged = 'client_request.owner_changed';

    case ReportSaved = 'report.saved';
    case ReportDeleted = 'report.deleted';
    case ReportSnapshotTaken = 'report.snapshot_taken';
    /*
     * Its own action, and the most important one in this module: a share hands a client's performance data to
     * whoever holds a link. "Who sent this out, to whom, and when does it stop working" has to be answerable.
     */
    case ReportShared = 'report.shared';
    case ReportShareRevoked = 'report.share_revoked';

    /*
     * Exposing a surface to a client is a disclosure, and the entry is the only thing that can answer "who let
     * them see that" after the person who did it has left. Withdrawal is its own action rather than an exposure
     * with a flag: the two questions asked of this trail are "when could they see it" and "when did that stop",
     * and an entry that answers one by implication answers neither.
     */
    /*
     * Asking a platform to change something. Three actions rather than one, because the three questions asked of
     * this trail are different: what did we send, what did the platform confirm when we read it back, and what
     * went wrong. An entry that answered the first by implication would be an entry nobody could use after an
     * outcome of `unknown`, which is the only outcome that matters.
     */
    case ProviderWriteAttempted = 'provider.write_attempted';
    case ProviderWriteConfirmed = 'provider.write_confirmed';
    case ProviderWriteFailed = 'provider.write_failed';

    case PortalEnabled = 'portal.enabled';
    case PortalSuspended = 'portal.suspended';
    case PortalResumed = 'portal.resumed';
    case PortalModuleExposed = 'portal.module_exposed';
    case PortalModuleWithdrawn = 'portal.module_withdrawn';

    case WorkspaceMemberInvited = 'workspace.member_invited';
    case WorkspaceMemberRoleChanged = 'workspace.member_role_changed';
    case WorkspaceMemberSuspended = 'workspace.member_suspended';
    /*
     * Its own action. A removal was being recorded as a suspension, which is a different thing: one is somebody
     * expected back whose work waits for them, the other is somebody gone whose open work is released. An audit
     * trail that calls the second the first answers "what happened to Noura's twelve tasks" wrongly.
     */
    case WorkspaceMemberRemoved = 'workspace.member_removed';

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
            self::IntegrationConnectionReconnected,
            self::IntegrationAccountIgnored,
            self::CampaignProjectAssigned,
            self::ClientOffboarded,
            /*
             * Beside them for the same reason: it decides whose revenue a merchant's orders become, and it
             * grants the people on one client's projects sight of that store's customers.
             */
            self::StoreConnected,
            /*
             * Approving a budget commits a client's money. Not a credential event, but
             * the one budget action that has to be as findable as one.
             */
            self::BudgetApproved,
            /*
             * And these are data-access grants in everything but name. A share link hands a client's performance
             * data to whoever holds it; a portal module hands a surface to somebody outside the agency. Both
             * belong beside "who connected a platform" rather than beside "who renamed a report".
             */
            self::ReportShared,
            self::ReportShareRevoked,
            /*
             * A write to a client's live campaign is the most consequential thing this product does. It belongs
             * beside "who connected a platform" rather than beside "who renamed a report" — including the
             * attempt, because an attempt that produced an unknown outcome is the entry somebody needs most.
             */
            self::ProviderWriteAttempted,
            self::ProviderWriteConfirmed,
            self::ProviderWriteFailed,
            self::PortalEnabled,
            self::PortalSuspended,
            self::PortalResumed,
            self::PortalModuleExposed,
            self::PortalModuleWithdrawn,
        ], true);
    }
}
