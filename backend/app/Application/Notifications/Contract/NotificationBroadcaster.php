<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

use App\Application\Notifications\DTO\NotificationView;

/**
 * Pushing a notification to whoever is looking at the application right now.
 *
 * A port rather than a direct call to the broadcasting facade, for two reasons. The obvious one is that the
 * application layer does not know about Reverb. The one that matters is that **realtime is optional and this
 * product has to work without it**: with no key configured there is no WebSocket server, and the implementation
 * says so and does nothing rather than throwing. The notification is already in the database, so a reader sees
 * it on their next poll — later, but never missing.
 *
 * @see \App\Infrastructure\Notifications\ReverbNotificationBroadcaster
 */
interface NotificationBroadcaster
{
    /** @param list<NotificationView> $notifications */
    public function push(string $workspaceId, array $notifications): void;

    /** Whether a push would actually reach anybody, so a surface can say whether live updates are on. */
    public function isConfigured(): bool;
}
