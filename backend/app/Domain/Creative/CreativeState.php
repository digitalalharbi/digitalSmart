<?php

declare(strict_types=1);

namespace App\Domain\Creative;

use App\Domain\Creative\Exception\InvalidCreativeTransition;

/**
 * Where a piece of creative work has got to.
 *
 * The states are a **board somebody works**, so they are ordered and the legal moves between them are
 * declared rather than left to whoever writes the next endpoint. Two rules are worth stating out loud,
 * because both have an obvious wrong version:
 *
 *  - **Approved is not a resting place a new version inherits.** Approval attaches to a version, never
 *    to the request, so uploading a revision moves the request back into review. An agency that let a
 *    client's approval carry over to a later cut has published something nobody signed off.
 *  - **Changes requested must say what changes.** The transition refuses without a reason, for the same
 *    reason a disqualified lead does: the note is the only record of why somebody rejected the work, and
 *    without it the next version is a guess.
 */
enum CreativeState: string
{
    /** Being written. Not yet anybody else's problem. */
    case Draft = 'draft';

    /** Briefed and waiting for somebody to start. */
    case Briefed = 'briefed';

    case InProduction = 'in_production';

    /** A version exists and somebody is looking at it. */
    case InReview = 'in_review';

    /** Looked at, and sent back with a reason. */
    case ChangesRequested = 'changes_requested';

    /** A specific version was approved. See the note above about what that does and does not mean. */
    case Approved = 'approved';

    case Cancelled = 'cancelled';

    /**
     * The states this one may move to.
     *
     * Declared as data so the board, the API and the tests all read the same rules. A move not listed
     * here is refused with the list, which is more useful than "invalid state".
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Briefed, self::Cancelled],
            self::Briefed => [self::InProduction, self::Draft, self::Cancelled],
            self::InProduction => [self::InReview, self::Cancelled],
            self::InReview => [self::Approved, self::ChangesRequested, self::Cancelled],
            // Back to production, because that is what "changes requested" means.
            self::ChangesRequested => [self::InProduction, self::Cancelled],
            /*
             * An approved request can go back into production: a campaign gets extended, a size is
             * added, a client changes their mind after signing off. What it cannot do is stay approved
             * while a new version appears — see `requiresReReview()`.
             */
            self::Approved => [self::InProduction, self::Cancelled],
            self::Cancelled => [self::Draft],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @throws InvalidCreativeTransition */
    public function assertCanMoveTo(self $next): void
    {
        if (! $this->canMoveTo($next)) {
            throw InvalidCreativeTransition::between($this, $next);
        }
    }

    /**
     * Whether a new version landing in this state sends the work back for review.
     *
     * True for everything past production, and the rule that keeps an approval honest: a client
     * approved a file, not a request, so a later file is unapproved until somebody says otherwise.
     */
    public function requiresReReview(): bool
    {
        return $this === self::Approved || $this === self::ChangesRequested || $this === self::InReview;
    }

    /** Still somebody's work. Drives the board's "what is open" count. */
    public function isOpen(): bool
    {
        return $this !== self::Approved && $this !== self::Cancelled;
    }

    /** Whether work sits with the reviewer rather than with the producer. */
    public function isWaitingOnReview(): bool
    {
        return $this === self::InReview;
    }

    /** The order the board shows, left to right in the reading direction. */
    public function boardPosition(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Briefed => 1,
            self::InProduction => 2,
            self::InReview => 3,
            self::ChangesRequested => 4,
            self::Approved => 5,
            self::Cancelled => 6,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Briefed => 'Briefed',
            self::InProduction => 'In production',
            self::InReview => 'In review',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The states a board shows as columns, in order.
     *
     * @return list<self>
     */
    public static function board(): array
    {
        $states = self::cases();

        usort($states, static fn (self $a, self $b): int => $a->boardPosition() <=> $b->boardPosition());

        return $states;
    }
}
