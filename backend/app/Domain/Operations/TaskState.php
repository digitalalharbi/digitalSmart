<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Operations\Exception\InvalidTaskTransition;

/**
 * Where a piece of operational work has got to.
 *
 * A board somebody works, so the legal moves are declared here rather than left to whoever writes the
 * next endpoint. Three rules are worth stating out loud, because each has an obvious wrong version:
 *
 *  - **Blocked must say what is blocking it.** A column of unexplained blocked cards is a column nobody
 *    can clear, and the reason is the only thing that tells the next person who to ask.
 *  - **Blocked does not lead to done.** The only ways out are back into progress or cancelled. A card
 *    that went from blocked straight to done records a resolution that never happened.
 *  - **Done records when.** The state alone is a claim; the completion time is the evidence, and a
 *    database CHECK refuses one without the other.
 */
enum TaskState: string
{
    case Todo = 'todo';

    case InProgress = 'in_progress';

    /** Waiting on something outside this board. Carries a reason, always. */
    case Blocked = 'blocked';

    /** Finished by whoever did it, and waiting on somebody else to look. */
    case InReview = 'in_review';

    case Done = 'done';

    case Cancelled = 'cancelled';

    /**
     * The states this one may move to.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Todo => [self::InProgress, self::Blocked, self::Cancelled],
            self::InProgress => [self::InReview, self::Blocked, self::Done, self::Cancelled],
            /*
             * Not to done, deliberately. Work that was blocked and is now finished passed back through
             * somebody doing it, and a board that allowed the shortcut would lose that fact — along with
             * any way to measure how long the block cost.
             */
            self::Blocked => [self::InProgress, self::Cancelled],
            self::InReview => [self::Done, self::InProgress, self::Cancelled],
            // Reopened rather than edited: finishing something twice is a real event on a real board.
            self::Done => [self::InProgress],
            self::Cancelled => [self::Todo],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @throws InvalidTaskTransition */
    public function assertCanMoveTo(self $next): void
    {
        if (! $this->canMoveTo($next)) {
            throw InvalidTaskTransition::between($this, $next);
        }
    }

    /**
     * Whether moving into this state requires a reason.
     *
     * Blocked and cancelled both do, and both are refused three times over — here, in the use case and
     * by a CHECK constraint. Three layers for one rule, because the reason is the whole content of the
     * state: "blocked" without it is an unactionable card, and "cancelled" without it is a decision
     * somebody will be asked to defend with nothing to hand.
     */
    public function needsReason(): bool
    {
        return $this === self::Blocked || $this === self::Cancelled;
    }

    /** Still somebody's work. Drives every open count, and what "overdue" is allowed to mean. */
    public function isOpen(): bool
    {
        return $this !== self::Done && $this !== self::Cancelled;
    }

    /** Whether the work sits with a reviewer rather than with the person doing it. */
    public function isWaitingOnSomebodyElse(): bool
    {
        return $this === self::InReview || $this === self::Blocked;
    }

    /** True only for done. Cancelled work was not completed, and counting it as such flatters a report. */
    public function isCompleted(): bool
    {
        return $this === self::Done;
    }

    /** The order the board shows, left to right in the reading direction. */
    public function boardPosition(): int
    {
        return match ($this) {
            self::Todo => 0,
            self::InProgress => 1,
            self::Blocked => 2,
            self::InReview => 3,
            self::Done => 4,
            self::Cancelled => 5,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::Blocked => 'Blocked',
            self::InReview => 'In review',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The states the board shows as columns, in order.
     *
     * @return list<self>
     */
    public static function board(): array
    {
        $states = self::cases();

        usort($states, static fn (self $a, self $b): int => $a->boardPosition() <=> $b->boardPosition());

        return $states;
    }

    /**
     * The states that are still work, in board order.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::board(), static fn (self $state): bool => $state->isOpen()));
    }
}
