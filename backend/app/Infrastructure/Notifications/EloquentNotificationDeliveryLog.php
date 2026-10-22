<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\NotificationDeliveryLog;
use App\Application\Notifications\DTO\DeliveryOutcome;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Notifications\DigestChannel;
use Illuminate\Support\Facades\DB;

final readonly class EloquentNotificationDeliveryLog implements NotificationDeliveryLog
{
    public function __construct(private IdentifierGenerator $identifiers) {}

    public function record(string $workspaceId, string $notificationId, string $userId, DigestChannel $channel, DeliveryOutcome $outcome): void
    {
        $now = now();

        DB::table('notification_deliveries')->upsert(
            [[
                'id' => $this->identifiers->generate(),
                'workspace_id' => $workspaceId,
                'notification_id' => $notificationId,
                'user_id' => $userId,
                'channel' => $channel->value,
                'status' => $outcome->status->value,
                'detail' => $outcome->detail,
                'attempted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['notification_id', 'channel'],
            ['status', 'detail', 'attempted_at', 'updated_at'],
        );
    }

    public function forNotifications(string $workspaceId, string $userId, array $notificationIds): array
    {
        if ($notificationIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('notification_deliveries')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->whereIn('notification_id', $notificationIds)
            ->orderBy('channel')
            ->get(['notification_id', 'channel', 'status', 'detail', 'attempted_at']) as $row) {
            $out[(string) $row->notification_id][] = [
                'channel' => (string) $row->channel,
                'status' => (string) $row->status,
                'detail' => is_string($row->detail) ? $row->detail : null,
                'attempted_at' => (string) $row->attempted_at,
            ];
        }

        return $out;
    }
}
