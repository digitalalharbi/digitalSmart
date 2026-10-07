<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Shared\Contract\ActorContext;

/** Clear your own unread list, and say how many that was. */
final readonly class MarkAllRead
{
    public function __construct(
        private NotificationStore $store,
        private ActorContext $actor,
    ) {}

    public function __invoke(): int
    {
        return $this->store->markAllRead(
            $this->actor->workspaceId()->value,
            $this->actor->userId()->value,
        );
    }
}
