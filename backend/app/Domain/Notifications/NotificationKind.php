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
 * So: four kinds, each with one recipient group and one thing for them to do.
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

    public function label(): string
    {
        return match ($this) {
            self::TaskAssigned => 'A task was assigned to you',
            self::ApprovalRequested => 'A change is waiting for your decision',
            self::ApprovalDecided => 'Your request was answered',
            self::ApprovalEnacted => 'An approved change was carried out',
            self::ClientRequestSubmitted => 'A client asked for something',
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
            self::ApprovalRequested => Permission::ApprovalsDecide,
            self::ApprovalDecided, self::ApprovalEnacted => Permission::ApprovalsView,
            /*
             * The agency's own view of the queue, which a client viewer does not hold — so a client is never
             * told about another client's request, and never told about their own arriving.
             */
            self::ClientRequestSubmitted => Permission::ClientRequestsView,
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
            || $this === self::ApprovalRequested
            || $this === self::ClientRequestSubmitted;
    }

    public function subject(): NotificationSubject
    {
        return match ($this) {
            self::TaskAssigned => NotificationSubject::Task,
            self::ApprovalRequested, self::ApprovalDecided, self::ApprovalEnacted => NotificationSubject::Approval,
            self::ClientRequestSubmitted => NotificationSubject::ClientRequest,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
