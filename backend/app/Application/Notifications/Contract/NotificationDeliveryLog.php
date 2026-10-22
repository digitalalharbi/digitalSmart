<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

use App\Application\Notifications\DTO\DeliveryOutcome;
use App\Domain\Notifications\DigestChannel;

interface NotificationDeliveryLog
{
    /** One row per notification per channel; a second attempt overwrites the first's status and time. */
    public function record(string $workspaceId, string $notificationId, string $userId, DigestChannel $channel, DeliveryOutcome $outcome): void;

    /**
     * The deliveries behind these notifications, for the history a reader is shown.
     *
     * @param  list<string>  $notificationIds
     * @return array<string, list<array{channel: string, status: string, detail: ?string, attempted_at: string}>>
     */
    public function forNotifications(string $workspaceId, string $userId, array $notificationIds): array;
}
