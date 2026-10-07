<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * Whether the approved change has actually been made.
 *
 * This enum exists because **approval is not execution**, and conflating them is how an agency tells a client
 * a campaign was stopped while it keeps spending. This product does not write to the ad platforms yet — see
 * `ApprovalKind::isEnactedByThisProduct()` — so an approved change waits for a person, and only that person
 * saying "done" records that it happened.
 *
 * The figure this makes possible is the one an agency never has: **how much approved work nobody has carried
 * out**. An approval nobody acted on is a decision that did nothing, and it is invisible in every tool that
 * treats the approval as the end of the story.
 */
enum EnactmentState: string
{
    /** Nothing to carry out: the request is not approved, or it changed nothing outside this product. */
    case NotRequired = 'not_required';

    /** Approved, and waiting for somebody to go and make the change. */
    case AwaitingHand = 'awaiting_hand';

    /** Somebody said they made it, and when. */
    case Confirmed = 'confirmed';

    /** Approved, and deliberately not carried out. Carries a note, because it is a second decision. */
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Nothing to carry out',
            self::AwaitingHand => 'Waiting to be carried out',
            self::Confirmed => 'Carried out',
            self::Abandoned => 'Not carried out',
        };
    }

    /** Whether this is approved work still waiting on somebody. The figure the queue leads with. */
    public function isOutstanding(): bool
    {
        return $this === self::AwaitingHand;
    }

    public function isSettled(): bool
    {
        return $this === self::Confirmed || $this === self::Abandoned;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
