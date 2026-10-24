<?php

declare(strict_types=1);

namespace App\Domain\Saas;

/**
 * Where a workspace stands commercially, and what each state does to it. **No state deletes anything**: billing stopping is a
 * reason to stop work, never a reason to lose a client's data.
 *
 * | State | Sign in, read | Write | Scheduled work | Shown |
 * |---|---|---|---|---|
 * | trial | yes | yes | yes | days left |
 * | active | yes | yes | yes | — |
 * | past_due | yes | yes | yes | a warning to administrators |
 * | grace | yes | yes | yes | a warning to everyone, with the end date |
 * | suspended | yes | no | no | why, and who to contact |
 * | cancelled | yes | no | no | that it has ended; data kept |
 *
 * Nothing moves a workspace between states on its own: there is no billing provider connected, so an end date passing is shown
 * to the operator, who decides. Read access stays in the last two states so a workspace can always see and export what it holds.
 */
enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function allowsWrites(): bool
    {
        return ! in_array($this, [self::Suspended, self::Cancelled], true);
    }

    /** Syncs, rules, agents and digests run only for a workspace that may write. */
    public function runsScheduledWork(): bool
    {
        return $this->allowsWrites();
    }

    /** Whether people in the workspace are shown a notice about it. */
    public function warns(): bool
    {
        return $this !== self::Active;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
