<?php

declare(strict_types=1);

namespace App\Application\Notifications\Query;

use App\Application\Notifications\Contract\NotificationBroadcaster;
use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Notifications\DTO\NotificationFeed;
use App\Application\Shared\Contract\ActorContext;

/**
 * One person's notifications.
 *
 * **No permission check**, deliberately, and this is the one query in the product where that is correct: these
 * rows are scoped to the reader by their own user id, and the permission that decided whether they could be
 * told at all was checked when the notification was written. A second gate here would mean somebody who lost a
 * permission could no longer read a notification they had already been sent — the record of having been told
 * would disappear, which is worse than not having been told.
 */
final readonly class GetNotifications
{
    private const int LIMIT = 50;

    public function __construct(
        private NotificationStore $store,
        private ActorContext $actor,
        private NotificationBroadcaster $broadcaster,
    ) {}

    public function __invoke(bool $unreadOnly = false, ?int $limit = null): NotificationFeed
    {
        $workspaceId = $this->actor->workspaceId()->value;
        $userId = $this->actor->userId()->value;

        return new NotificationFeed(
            notifications: $this->store->forUser(
                $workspaceId,
                $userId,
                $unreadOnly,
                max(1, min($limit ?? self::LIMIT, 200)),
            ),
            digest: $this->store->digest($workspaceId, $userId),
            /*
             * Published so a surface can say whether it is being pushed to or is polling. A page that silently
             * polls while implying it is live is one where somebody waits for an update that never animates.
             */
            isLive: $this->broadcaster->isConfigured(),
        );
    }
}
