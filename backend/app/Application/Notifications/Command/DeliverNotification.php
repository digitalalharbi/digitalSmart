<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Notifications\Contract\NotificationChannelDelivery;
use App\Application\Notifications\Contract\NotificationDeliveryLog;
use App\Application\Notifications\Contract\NotificationPreferenceStore;
use App\Application\Notifications\DTO\DeliveryOutcome;
use App\Application\Notifications\DTO\NotificationView;
use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\DigestChannel;
use Throwable;

/**
 * Carry a notification over every channel its recipient asked for, and write down what happened.
 *
 * In-app is the bell itself, already written; it is never a delivery row. Each other channel gets one attempt and
 * one row — `awaiting_credentials` today, since no provider is connected — so the history can say "sent" or
 * "failed" the day one is, and says neither before. A delivery that throws is a failed row, never a lost one.
 */
final readonly class DeliverNotification
{
    public function __construct(
        private NotificationPreferenceStore $preferences,
        private NotificationChannelDelivery $delivery,
        private NotificationDeliveryLog $log,
    ) {}

    /** @return list<DigestChannel> the channels attempted */
    public function __invoke(string $workspaceId, NotificationView $notification): array
    {
        $attempted = [];

        foreach ($this->preferences->get($workspaceId, $notification->recipientId)->channels as $channel) {
            if ($channel === DigestChannel::InApp) {
                continue;
            }

            try {
                $outcome = $this->delivery->deliver($channel, $workspaceId, $notification);
            } catch (Throwable $failure) {
                report($failure);
                $outcome = new DeliveryOutcome(
                    DeliveryStatus::Failed,
                    'The delivery threw before an answer came back.',
                );
            }

            $this->log->record($workspaceId, $notification->id, $notification->recipientId, $channel, $outcome);
            $attempted[] = $channel;
        }

        return $attempted;
    }
}
