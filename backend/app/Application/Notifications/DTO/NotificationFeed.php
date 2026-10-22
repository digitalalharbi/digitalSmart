<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTO;

use App\Domain\Notifications\NotificationDigest;

/** One person's notifications, their counts, and whether this session is being pushed to. */
final readonly class NotificationFeed
{
    /** @param list<NotificationView> $notifications */
    public function __construct(
        public array $notifications,
        public NotificationDigest $digest,
        /**
         * Whether realtime is configured at all.
         *
         * Reported rather than assumed. With no Reverb key there is no WebSocket server, the notifications are
         * still written and still arrive on the next poll, and the interface says live updates are off. A page
         * that implied it was live while polling is a page where somebody waits for an update that never comes.
         */
        public bool $isLive,
        /** @var array<string, list<array{channel: string, status: string, detail: ?string, attempted_at: string}>> by notification id */
        public array $deliveries = [],
    ) {}
}
