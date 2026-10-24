<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Access\Permission;

/**
 * What a person is being told.
 *
 * The list is deliberately **short**, and the test of whether something belongs on it is not "is this
 * interesting" but "is there somebody with something to do about it". A notification centre that announces
 * every state change teaches people to ignore the bell, and then the one notification that mattered — an
 * approval for a change to live spend, sitting unread — arrives in a stream nobody reads.
 *
 * So: nine kinds, each with one recipient group and one thing for them to do. Four of them are about a client
 * request, and they are four rather than one because the recipient and the action differ: a client being told
 * their request moved has nothing to do, a client being asked for information has, an agency being told a client
 * replied has, and "the agency answered you" goes the other way entirely. Collapsing them would mean a single
 * notification whose recipient depended on a field, and a disclosure decided by a field is a disclosure
 * somebody will get wrong.
 *
 * Each kind also declares the permission its recipient must hold. That is not a convenience: a notification is
 * a **disclosure**. Telling somebody that "Raise the Ramadan daily budget is waiting for you" discloses a
 * client, a campaign and an intention, and a recipient resolver that did not check would leak all three to
 * whoever happened to be in the workspace.
 */
enum NotificationKind: string
{
    /** Somebody put a task in your name. You are the only recipient. */
    case TaskAssigned = 'task_assigned';

    /**
     * A lead was put in your name. You are the only recipient.
     *
     * Its own kind rather than `TaskAssigned` with a different subject, because the two are read differently.
     * A task has a due date and waits; a lead is a person who has just asked for something, and the figure
     * that decides whether the enquiry was worth buying is how long somebody took to answer it. The
     * notification is the only part of this product that can shorten that number.
     */
    case LeadAssigned = 'lead_assigned';

    /**
     * A lead is closing on the response time the project promised, and nobody has tried to reach it.
     *
     * Separate from the assignment: that one says a lead arrived, this one says it is about to be late.
     */
    case LeadSlaApproaching = 'lead_sla_approaching';

    /** The response time elapsed with no attempt. Worse than approaching, so it may repeat sooner and louder. */
    case LeadSlaOverdue = 'lead_sla_overdue';

    /** A lead somebody owns was judged worth pursuing. News for the owner, not a task. */
    case LeadQualified = 'lead_qualified';

    /** An appointment was booked against a lead the recipient owns. */
    case LeadAppointmentBooked = 'lead_appointment_booked';

    /** A lead the recipient owns became a sale. */
    case LeadSaleCompleted = 'lead_sale_completed';

    /** A change is waiting for a decision. Everyone who can actually decide it, and nobody else. */
    case ApprovalRequested = 'approval_requested';

    /** Your request was answered. The person who asked. */
    case ApprovalDecided = 'approval_decided';

    /**
     * An approved change was carried out, or deliberately was not.
     *
     * Goes to whoever decided it, which closes the loop this product exists to keep open: the person who
     * approved a change to live spend is the person who should learn whether it actually happened.
     */
    case ApprovalEnacted = 'approval_enacted';

    /**
     * A client asked for something.
     *
     * The one kind raised by somebody outside the agency, and the reason it needs action: a client request nobody
     * has answered is a client waiting, and that is measured in this product against a shorter patience than
     * anything internal.
     */
    case ClientRequestSubmitted = 'client_request_submitted';

    /**
     * The request you raised moved.
     *
     * Goes to whoever submitted it, which is usually the client. Nothing to do — this is the notification that
     * closes the loop the intake queue opens: a request that was accepted, declined or delivered with nobody
     * told is a client checking an inbox that never says anything.
     */
    case ClientRequestMoved = 'client_request_moved';

    /**
     * The agency needs something from you before it can go on.
     *
     * Its own kind rather than a `ClientRequestMoved` with a different state, because this is the one move that
     * puts the work back on the client — and the whole point of `needsAction` is that a reader can tell the two
     * apart without reading every line.
     */
    case ClientRequestNeedsInformation = 'client_request_needs_information';

    /**
     * The agency answered you.
     *
     * Only for a **client-visible** message from an agency member. An internal note announces nothing: writing
     * to each other is not answering, and a notification saying "the agency replied" for a note the client
     * cannot read would be the cruellest bug in this module.
     */
    case ClientRequestAnswered = 'client_request_answered';

    /**
     * A client said something on a request.
     *
     * The other half of `ClientRequestSubmitted`, and the gap it left: a request that was answered once and then
     * replied to is a client waiting again, and nothing was telling anybody. Goes to the agency's own readers of
     * the queue.
     */
    case ClientRequestReplied = 'client_request_replied';

    /**
     * A digest — one summary standing in for N alerts — was written for you.
     *
     * Exists so a person who has the bell open sees *one* entry for "8 campaigns need attention" rather than
     * eight. The digest is built as the recipient, so whatever it names they were already entitled to see.
     */
    case DigestReady = 'digest_ready';

    /** A background export finished and is waiting to be downloaded. Goes only to the person who asked. */
    case ExportReady = 'export_ready';

    /**
     * Your daily, weekly or monthly performance digest is ready. The cadence and the headline figures travel in
     * the payload; the digest row holds the rest.
     */
    case PerformanceDigest = 'performance_digest';

    /*
     * Event alerts. Each one is a figure that moved against somebody, or a state a campaign reached, with the
     * evidence in the payload — never a sentence about how it feels. They carry a dedupe key, so "ROAS low"
     * is said once per cooldown and again only when it got worse.
     */

    /** Return on ad spend fell against the earlier period. */
    case RoasDrop = 'roas_drop';

    /** Cost per result — a conversion, a lead — rose against the earlier period. */
    case CostPerResultRise = 'cost_per_result_rise';

    /** Spend jumped against the earlier period. */
    case SpendSpike = 'spend_spike';

    /** A budget is close to its approved amount with the period still running. */
    case BudgetNearLimit = 'budget_near_limit';

    /** Money went out and nothing came back: no leads, no purchases. */
    case NoResults = 'no_results';

    /** A measurement source stopped reporting, or a conversion the client pays for is not being measured. */
    case TrackingFailure = 'tracking_failure';

    /** The platform rejected a campaign or an ad. */
    case CampaignRejected = 'campaign_rejected';

    /** A creative is wearing out: click-through falling with frequency, cost or conversion rate corroborating. */
    case CreativeFatigue = 'creative_fatigue';

    /** A campaign reached its end date. */
    case CampaignEnded = 'campaign_ended';

    /** A live campaign is delivering well below its budget or its earlier pace. */
    case CampaignUnderDelivering = 'campaign_under_delivering';

    /** Somebody outside asked for an account or sent an enquiry. */
    case AccessRequestReceived = 'access_request_received';

    /** A request nobody has answered is close to the response time the workspace promised. */
    case AccessRequestApproaching = 'access_request_approaching';

    /** Past it. */
    case AccessRequestOverdue = 'access_request_overdue';

    /** A request for an account was approved; an invitation is the next step. */
    case AccessRequestApproved = 'access_request_approved';

    /** An invitation was made and nobody has accepted it. */
    case AccessInvitationPending = 'access_invitation_pending';

    public function label(): string
    {
        return match ($this) {
            self::TaskAssigned => 'A task was assigned to you',
            self::LeadAssigned => 'A lead is waiting for you',
            self::LeadSlaApproaching => 'A lead is close to its response time',
            self::LeadSlaOverdue => 'A lead is past its response time',
            self::LeadQualified => 'A lead was qualified',
            self::LeadAppointmentBooked => 'An appointment was booked',
            self::LeadSaleCompleted => 'A lead became a sale',
            self::ApprovalRequested => 'A change is waiting for your decision',
            self::ApprovalDecided => 'Your request was answered',
            self::ApprovalEnacted => 'An approved change was carried out',
            self::ClientRequestSubmitted => 'A client asked for something',
            self::ClientRequestMoved => 'Your request moved',
            self::ClientRequestNeedsInformation => 'We need something from you',
            self::ClientRequestAnswered => 'Your request was answered',
            self::ClientRequestReplied => 'A client replied on a request',
            self::DigestReady => 'Your digest is ready',
            self::ExportReady => 'Your export is ready',
            self::PerformanceDigest => 'Your performance digest is ready',
            self::RoasDrop => 'ROAS dropped',
            self::CostPerResultRise => 'Cost per result rose',
            self::SpendSpike => 'Spend spiked',
            self::BudgetNearLimit => 'A budget is near its limit',
            self::NoResults => 'Spend with no results',
            self::TrackingFailure => 'Tracking is failing',
            self::CampaignRejected => 'A campaign was rejected',
            self::CreativeFatigue => 'A creative is wearing out',
            self::CampaignEnded => 'A campaign ended',
            self::CampaignUnderDelivering => 'A campaign is under-delivering',
            self::AccessRequestReceived => 'A request arrived',
            self::AccessRequestApproaching => 'A request is close to its response time',
            self::AccessRequestOverdue => 'A request is past its response time',
            self::AccessRequestApproved => 'A request for an account was approved',
            self::AccessInvitationPending => 'An invitation has not been accepted',
        };
    }

    /**
     * The permission a recipient must hold to be told at all.
     *
     * See the note at the top of this enum: the check is a disclosure control, not an optimisation.
     */
    public function requires(): Permission
    {
        return match ($this) {
            self::TaskAssigned => Permission::TasksView,
            /*
             * `leads.view`, which is a stronger condition than it looks: a lead carries a prospect's name and
             * phone number, so the notification naming it may only reach somebody who could have opened the
             * record. See `PermissionAwareRecipientResolver` — the visibility check matters here more than
             * anywhere, because an assignment names one person deliberately.
             */
            self::LeadAssigned,
            self::LeadSlaApproaching,
            self::LeadSlaOverdue,
            self::LeadQualified,
            self::LeadAppointmentBooked,
            self::LeadSaleCompleted => Permission::LeadsView,
            self::ApprovalRequested => Permission::ApprovalsDecide,
            self::ApprovalDecided, self::ApprovalEnacted => Permission::ApprovalsView,
            /*
             * The agency's own view of the queue, which a client viewer does not hold — so a client is never
             * told about another client's request, and never told about their own arriving.
             */
            self::ClientRequestSubmitted, self::ClientRequestReplied => Permission::ClientRequestsView,
            /*
             * `submit` rather than `view`: these three go to the person who raised the request, who is usually a
             * client and deliberately does not hold the agency's view of the queue. The permission a client
             * viewer *does* hold is the one that let them raise it in the first place.
             */
            self::ClientRequestMoved,
            self::ClientRequestNeedsInformation,
            self::ClientRequestAnswered => Permission::ClientRequestsSubmit,
            /* Digests are built from streams the recipient's permissions already admit; this only has to be a floor. */
            self::DigestReady => Permission::AnalyticsView,
            self::ExportReady => Permission::ReportsView,
            self::PerformanceDigest,
            self::RoasDrop,
            self::CostPerResultRise,
            self::SpendSpike,
            self::NoResults,
            self::CampaignRejected,
            self::CampaignEnded,
            self::CampaignUnderDelivering => Permission::AnalyticsView,
            self::BudgetNearLimit => Permission::BudgetsView,
            self::TrackingFailure => Permission::TrackingView,
            self::CreativeFatigue => Permission::CreativeView,
            /* The people who may answer a request for access are the ones who may be told about it. */
            self::AccessRequestReceived,
            self::AccessRequestApproaching,
            self::AccessRequestOverdue,
            self::AccessRequestApproved,
            self::AccessInvitationPending => Permission::UsersInvite,
        };
    }

    /** The tab it is filed under. */
    public function category(): NotificationCategory
    {
        return match ($this) {
            self::TaskAssigned,
            self::LeadAssigned,
            self::LeadSlaApproaching,
            self::LeadSlaOverdue,
            self::LeadQualified,
            self::LeadAppointmentBooked,
            self::LeadSaleCompleted,
            self::ClientRequestSubmitted,
            self::ClientRequestMoved,
            self::ClientRequestNeedsInformation,
            self::ClientRequestAnswered,
            self::ClientRequestReplied,
            self::AccessRequestReceived,
            self::AccessRequestApproaching,
            self::AccessRequestOverdue,
            self::AccessRequestApproved,
            self::AccessInvitationPending => NotificationCategory::Work,
            self::ApprovalRequested, self::ApprovalDecided, self::ApprovalEnacted => NotificationCategory::Approvals,
            self::DigestReady, self::ExportReady => NotificationCategory::Reports,
            self::PerformanceDigest,
            self::RoasDrop,
            self::CostPerResultRise,
            self::SpendSpike,
            self::NoResults,
            self::CampaignRejected,
            self::CampaignEnded,
            self::CampaignUnderDelivering => NotificationCategory::Performance,
            self::BudgetNearLimit => NotificationCategory::Budget,
            self::TrackingFailure => NotificationCategory::Tracking,
            self::CreativeFatigue => NotificationCategory::Creative,
        };
    }

    /**
     * Whether the recipient is expected to do something, as opposed to being told something happened.
     *
     * Drives the one number in the notification centre that is worth leading with. "Twelve unread" says
     * nothing; "two waiting on you" is a to-do list.
     */
    public function needsAction(): bool
    {
        return $this === self::TaskAssigned
            /* Somebody is waiting for a reply, and the clock on them started when they enquired. */
            || $this === self::LeadAssigned
            || $this === self::LeadSlaApproaching
            || $this === self::LeadSlaOverdue
            || $this === self::AccessRequestReceived
            || $this === self::AccessRequestApproaching
            || $this === self::AccessRequestOverdue
            || $this === self::AccessInvitationPending
            || $this === self::ApprovalRequested
            || $this === self::ClientRequestSubmitted
            || $this === self::ClientRequestReplied
            /*
             * The one client-facing kind with something to do. "Your request was accepted" is news; "we need
             * something from you" is a to-do list with the client's name on it, and the figure the portal leads
             * with has to be able to tell them apart.
             */
            || $this === self::ClientRequestNeedsInformation
            /* Every alert but "it ended" asks for a decision; the digest is reading, not doing. */
            || $this === self::RoasDrop
            || $this === self::CostPerResultRise
            || $this === self::SpendSpike
            || $this === self::BudgetNearLimit
            || $this === self::NoResults
            || $this === self::TrackingFailure
            || $this === self::CampaignRejected
            || $this === self::CreativeFatigue
            || $this === self::CampaignUnderDelivering;
    }

    public function subject(): NotificationSubject
    {
        return match ($this) {
            self::TaskAssigned => NotificationSubject::Task,
            self::LeadAssigned,
            self::LeadSlaApproaching,
            self::LeadSlaOverdue,
            self::LeadQualified,
            self::LeadAppointmentBooked,
            self::LeadSaleCompleted => NotificationSubject::Lead,
            self::ApprovalRequested, self::ApprovalDecided, self::ApprovalEnacted => NotificationSubject::Approval,
            self::ClientRequestSubmitted,
            self::ClientRequestMoved,
            self::ClientRequestNeedsInformation,
            self::ClientRequestAnswered,
            self::ClientRequestReplied => NotificationSubject::ClientRequest,
            self::DigestReady, self::PerformanceDigest => NotificationSubject::Digest,
            self::ExportReady => NotificationSubject::Export,
            self::RoasDrop,
            self::CostPerResultRise,
            self::SpendSpike,
            self::NoResults,
            self::CampaignRejected,
            self::CampaignEnded,
            self::CampaignUnderDelivering => NotificationSubject::Campaign,
            self::BudgetNearLimit => NotificationSubject::Budget,
            self::TrackingFailure => NotificationSubject::MeasurementSource,
            self::CreativeFatigue => NotificationSubject::Creative,
            self::AccessRequestReceived,
            self::AccessRequestApproaching,
            self::AccessRequestOverdue,
            self::AccessRequestApproved,
            self::AccessInvitationPending => NotificationSubject::AccessRequest,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
