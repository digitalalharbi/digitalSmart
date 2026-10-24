<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Where a request for access or an enquiry stands with the people answering it.
 *
 * Only what the people answering decide lives here. What happens to an account request *afterwards* — an invitation made,
 * shared, opened, accepted, a first sign-in — are milestones with their own times on the request, not further statuses: one
 * column cannot say "approved" and "accepted" and "has signed in" at once without losing which came first.
 */
enum AccessRequestStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case WaitingForReply = 'waiting_for_reply';
    case Qualified = 'qualified';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Closed = 'closed';

    /** Still being worked: a decision has not been made and the matter has not been put away. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::WaitingForReply, self::Qualified], true);
    }

    public function canMoveTo(self $next): bool
    {
        return match (true) {
            $this === $next => false,
            $this->isOpen() => $next !== self::New,
            /* An approved request is put away once its invitation is dealt with; nothing else follows. */
            $this === self::Approved => $next === self::Closed,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
