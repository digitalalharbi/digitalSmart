<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * What a notification points at.
 *
 * A notification is a **pointer with a sentence on it**, never a copy of the thing. The sentence is the record
 * of what was announced and does not change afterwards; the subject is resolved on every read, so a
 * notification about something that has since been deleted says so rather than offering a link that goes
 * nowhere. Two tables, no foreign key, the same trade `TaskSubjectType` explains.
 */
enum NotificationSubject: string
{
    case Task = 'task';

    case Approval = 'approval';

    case ClientRequest = 'client_request';

    public function table(): string
    {
        return match ($this) {
            self::Task => 'tasks',
            self::Approval => 'approval_requests',
            self::ClientRequest => 'client_requests',
        };
    }

    public function labelColumn(): string
    {
        return 'title';
    }

    /** Where a reader should be sent. The surface, not a deep link: both surfaces open on a selection. */
    public function path(): string
    {
        return match ($this) {
            self::Task => '/tasks',
            self::Approval => '/approvals',
            self::ClientRequest => '/client-requests',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Task',
            self::Approval => 'Approval request',
            self::ClientRequest => 'Client request',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
