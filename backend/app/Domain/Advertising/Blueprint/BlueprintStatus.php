<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Blueprint;

/**
 * Where a campaign blueprint stands, from a draft to a live campaign.
 *
 * **`approved` means a person approved the plan, never that anything exists on a platform.** The states after it — queued,
 * creating, created paused, verified, activation pending, active — are each written only by the step that observed them, and a
 * campaign is `verified` only after it was read back from the platform.
 */
enum BlueprintStatus: string
{
    case Draft = 'draft';
    case ReadyForReview = 'ready_for_review';
    case Approved = 'approved';
    case Queued = 'queued';
    case Creating = 'creating';
    /** Some platforms created, some not; each platform's own record says which. */
    case Partial = 'partial';
    case CreatedPaused = 'created_paused';
    case Verified = 'verified';
    case ActivationPending = 'activation_pending';
    case Active = 'active';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::ReadyForReview, self::Cancelled],
            self::ReadyForReview => [self::Draft, self::Approved, self::Cancelled],
            self::Approved => [self::Draft, self::Queued, self::Cancelled],
            self::Queued => [self::Creating, self::Failed],
            self::Creating => [self::CreatedPaused, self::Partial, self::Failed],
            self::Partial => [self::Queued, self::Failed, self::Cancelled],
            self::CreatedPaused => [self::Verified, self::Failed],
            self::Verified => [self::ActivationPending, self::Cancelled],
            self::ActivationPending => [self::Active, self::Verified],
            self::Active, self::Failed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** The plan can be edited; editing a plan under review sends it back to draft. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::ReadyForReview], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
