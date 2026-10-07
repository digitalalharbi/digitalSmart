<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Exception\ResourceNotFound;

/**
 * Mark one of your own notifications read.
 *
 * Scoped to the actor **in SQL**, not checked after reading. There is no path here where a mistaken or guessed
 * id marks somebody else's notification read — which would be a small harm and an unanswerable question: the
 * person would simply never see the thing they were told.
 */
final readonly class MarkRead
{
    public function __construct(
        private NotificationStore $store,
        private ActorContext $actor,
    ) {}

    public function __invoke(string $notificationId): void
    {
        $marked = $this->store->markRead(
            $this->actor->workspaceId()->value,
            $this->actor->userId()->value,
            $notificationId,
        );

        if (! $marked) {
            /*
             * Not found rather than forbidden. Saying "forbidden" would confirm that the notification exists
             * and belongs to somebody else, which is a fact the asker has no business learning.
             */
            throw ResourceNotFound::of('Notification');
        }
    }
}
