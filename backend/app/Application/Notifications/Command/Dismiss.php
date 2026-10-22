<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Exception\ResourceNotFound;

/**
 * Put one of your own notifications away. It leaves the list and the counts and keeps its row: a dismissed
 * alert is still the record of having been told, and the history a person can be shown later.
 */
final readonly class Dismiss
{
    public function __construct(private NotificationStore $store, private ActorContext $actor) {}

    public function __invoke(string $notificationId): void
    {
        if (! $this->store->dismiss($this->actor->workspaceId()->value, $this->actor->userId()->value, $notificationId)) {
            throw ResourceNotFound::of('Notification');
        }
    }
}
