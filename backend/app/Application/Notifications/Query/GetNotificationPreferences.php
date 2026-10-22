<?php

declare(strict_types=1);

namespace App\Application\Notifications\Query;

use App\Application\Notifications\Contract\NotificationPreferenceStore;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Shared\Contract\ActorContext;

/** Your own preferences. Keyed by the authenticated user; no parameter could name somebody else's. */
final readonly class GetNotificationPreferences
{
    public function __construct(private NotificationPreferenceStore $store, private ActorContext $actor) {}

    public function __invoke(): NotificationPreferences
    {
        return $this->store->get($this->actor->workspaceId()->value, $this->actor->userId()->value);
    }
}
