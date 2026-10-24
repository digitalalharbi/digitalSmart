<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * What can be written on a request's timeline.
 *
 * The four contact types record what a **person says they did**. "Email sent" is somebody's note that they sent one, from their
 * own mail; nothing here sends, so nothing here can claim a message was delivered.
 */
enum AccessEventType: string
{
    case Created = 'created';
    case OwnerAssigned = 'owner_assigned';
    case StatusChanged = 'status_changed';
    case EmailSent = 'email_sent';
    case CallAttempted = 'call_attempted';
    case Contacted = 'contacted';
    case WhatsAppContacted = 'whatsapp_contacted';
    case Note = 'note';
    case FollowUpScheduled = 'follow_up_scheduled';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case DuplicateLinked = 'duplicate_linked';
    case InvitationCreated = 'invitation_created';
    case InvitationShared = 'invitation_shared';
    case InvitationOpened = 'invitation_opened';
    case InvitationAccepted = 'invitation_accepted';
    case FirstLogin = 'first_login';

    /** Whether it is a try at reaching the person, which is what the response time is about. */
    public function isAttempt(): bool
    {
        return in_array($this, [self::EmailSent, self::CallAttempted, self::Contacted, self::WhatsAppContacted], true);
    }

    /** Whether somebody got through, as opposed to trying. */
    public function isContact(): bool
    {
        return $this === self::Contacted || $this === self::WhatsAppContacted;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<self> the ones a person may record by hand */
    public static function contactActions(): array
    {
        return [self::EmailSent, self::CallAttempted, self::Contacted, self::WhatsAppContacted];
    }
}
