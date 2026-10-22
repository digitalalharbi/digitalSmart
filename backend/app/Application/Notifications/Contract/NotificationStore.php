<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Notifications\DTO\NotificationView;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationDigest;
use App\Domain\Notifications\NotificationSeverity;
use DateTimeImmutable;

interface NotificationStore
{
    /**
     * Write one row per recipient, and return the rows written.
     *
     * Returns them because the realtime announcement is made from what was stored rather than from what was
     * intended: a notification pushed to a browser that is not in the database is one a reload makes vanish,
     * which is worse than no realtime at all.
     *
     * @param  list<string>  $userIds
     * @return list<NotificationView>
     */
    public function announce(string $workspaceId, array $userIds, NotificationDraft $draft, ?string $actorId): array;

    /**
     * The worst severity announced under this dedupe key since `$since`, or null when nothing was.
     *
     * The cooldown check. Asked before writing, so a repeat that says nothing new is never stored — a bell that
     * rings for the same ROAS every hour is a bell somebody silences.
     */
    public function lastSeverity(string $workspaceId, string $dedupeKey, DateTimeImmutable $since): ?NotificationSeverity;

    /**
     * One person's notifications, newest first.
     *
     * @return list<NotificationView>
     */
    public function forUser(string $workspaceId, string $userId, bool $unreadOnly, int $limit, ?NotificationCategory $category = null): array;

    public function digest(string $workspaceId, string $userId): NotificationDigest;

    /**
     * Mark one as read, scoped to its owner.
     *
     * Returns false when the row is not that person's. Scoped in SQL rather than checked after reading, so
     * there is no path where a mistaken id marks somebody else's notification read.
     */
    public function markRead(string $workspaceId, string $userId, string $notificationId): bool;

    /** Mark everything unread as read, and return how many were changed. */
    public function markAllRead(string $workspaceId, string $userId): int;

    /** Record a click-through (and a read), scoped to the owner. False when the row is not that person's. */
    public function markClicked(string $workspaceId, string $userId, string $notificationId): bool;

    /** Dismiss, scoped to the owner. The row stays; the list and the counts no longer carry it. */
    public function dismiss(string $workspaceId, string $userId, string $notificationId): bool;
}
