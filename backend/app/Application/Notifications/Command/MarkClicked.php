<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Exception\ResourceNotFound;

/**
 * Record that you followed a notification to the thing it was about. Opening is reading, so it is read too.
 * Scoped to the actor in SQL, like `MarkRead`; somebody else's answers not found.
 */
final readonly class MarkClicked
{
    public function __construct(private NotificationStore $store, private ActorContext $actor) {}

    public function __invoke(string $notificationId): void
    {
        if (! $this->store->markClicked($this->actor->workspaceId()->value, $this->actor->userId()->value, $notificationId)) {
            throw ResourceNotFound::of('Notification');
        }
    }
}
