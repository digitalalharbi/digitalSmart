<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Notifications\Contract\NotificationPreferenceStore;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Notifications\Exception\InvalidNotificationPreference;

/**
 * Do not interrupt me for a while — or stop the snooze early.
 *
 * Your own, keyed by the session like every preference. A snooze withholds the realtime push and the copy over email or
 * WhatsApp; the notification is still written to the bell, a critical one still interrupts, and nothing withheld is sent
 * when the snooze ends. It ends by itself: the instant is stored and compared, nothing has to run to lift it.
 */
final readonly class SnoozeNotifications
{
    public const int MIN_MINUTES = 15;

    public const int MAX_MINUTES = 10080;

    public function __construct(
        private NotificationPreferenceStore $store,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    /** @param int|null $minutes null ends the snooze */
    public function __invoke(?int $minutes): NotificationPreferences
    {
        if ($minutes !== null && ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES)) {
            throw InvalidNotificationPreference::snooze();
        }

        $workspaceId = $this->actor->workspaceId()->value;
        $userId = $this->actor->userId()->value;

        $this->store->snooze(
            $workspaceId,
            $userId,
            $minutes === null ? null : $this->clock->now()->modify(sprintf('+%d minutes', $minutes)),
        );

        return $this->store->get($workspaceId, $userId);
    }
}
