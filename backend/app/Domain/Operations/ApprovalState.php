<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Operations\Exception\InvalidApprovalTransition;

/**
 * Where an approval request has got to.
 *
 * **Every outcome is terminal.** An approved request cannot be edited and a declined one cannot be re-decided:
 * amending a decided request produces a record claiming somebody approved something they never saw, which is
 * the one thing an approval trail exists to make impossible. A changed proposal is a **new request**, which
 * may name the one it replaces.
 *
 * It is the same rule an approved budget follows — the figure a client agreed cannot be quietly revised — and
 * the same rule a creative approval follows, where sign-off attaches to a version rather than to a request.
 */
enum ApprovalState: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Declined = 'declined';

    /** The requester withdrew it. Carries its own reason: changing your mind is still a decision. */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for a decision',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Declined;
    }

    /** Whether a reason is required to reach this state. */
    public function needsReason(): bool
    {
        /*
         * Declining and withdrawing both do. Approving does not: "yes" needs no explanation, and demanding
         * one would get "ok" typed into the box, which is worse than an empty field because it looks like a
         * record. A decline is the one somebody will be asked to defend.
         */
        return $this === self::Declined || $this === self::Withdrawn;
    }

    /**
     * Whether the proposal may still be changed.
     *
     * Only while pending. See the note at the top of this enum.
     */
    public function isAmendable(): bool
    {
        return $this === self::Pending;
    }

    /** @throws InvalidApprovalTransition */
    public function assertCanMoveTo(self $next): void
    {
        if (! $this->isPending()) {
            throw InvalidApprovalTransition::alreadySettled($this, $next);
        }

        if ($next === self::Pending) {
            throw InvalidApprovalTransition::alreadySettled($this, $next);
        }
    }

    public function position(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Approved => 1,
            self::Declined => 2,
            self::Withdrawn => 3,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
