<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * What one person's notification centre is carrying.
 *
 * The figure worth leading with is `waiting`, not `unread`. "Twelve unread" says nothing a person can act on —
 * it is a measure of how long they have been away. "Two waiting on you" is a to-do list, and it is the number
 * that belongs on a bell.
 *
 * Every count comes from SQL. An unread badge computed from whatever happens to be in a browser's cache is a
 * number that disagrees with the list below it, and once a person has seen that disagreement they stop
 * believing the badge.
 */
final readonly class NotificationDigest
{
    /** @param array<string, int> $unreadByKind counts keyed by `NotificationKind` value */
    public function __construct(
        public int $total,
        public int $unread,
        /** Unread, and of a kind the recipient is expected to do something about. */
        public int $waiting,
        public array $unreadByKind,
    ) {}

    public function hasAnything(): bool
    {
        return $this->total > 0;
    }
}
