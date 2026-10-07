<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

use App\Application\Notifications\DTO\NotificationView;
use App\Domain\Notifications\NotificationDigest;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;

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
    public function announce(
        string $workspaceId,
        array $userIds,
        NotificationKind $kind,
        string $title,
        ?string $body,
        NotificationSubject $subject,
        string $subjectId,
        ?string $actorId,
    ): array;

    /**
     * One person's notifications, newest first.
     *
     * @return list<NotificationView>
     */
    public function forUser(string $workspaceId, string $userId, bool $unreadOnly, int $limit): array;

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
}
