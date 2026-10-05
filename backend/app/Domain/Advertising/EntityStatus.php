<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * One status vocabulary across every platform.
 *
 * Each provider has its own: Meta uses ACTIVE/PAUSED/DELETED/ARCHIVED plus a
 * separate `effective_status` that folds in parent state and review outcomes;
 * Google uses ENABLED/PAUSED/REMOVED; TikTok splits `operation_status` from
 * `secondary_status`. Normalising lets one table show campaigns from six
 * platforms side by side.
 *
 * The provider's own string is always kept alongside this, because the
 * normalisation loses detail and an operator chasing a delivery problem needs
 * the original word the platform used.
 */
enum EntityStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    /** Live on the platform but not delivering — out of budget, schedule, or review. */
    case NotDelivering = 'not_delivering';
    case InReview = 'in_review';
    case Rejected = 'rejected';
    case Completed = 'completed';
    case Archived = 'archived';
    case Removed = 'removed';
    case Unknown = 'unknown';

    /** Whether this entity can currently spend money. */
    public function isSpending(): bool
    {
        return $this === self::Active;
    }

    /** Whether someone should look at it. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Rejected, self::NotDelivering], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::NotDelivering => 'Not delivering',
            self::InReview => 'In review',
            self::Rejected => 'Rejected',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
            self::Removed => 'Removed',
            self::Unknown => 'Unknown',
        };
    }
}
