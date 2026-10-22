<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

use App\Application\Notifications\DTO\DeliveryOutcome;
use App\Application\Notifications\DTO\NotificationView;
use App\Domain\Notifications\DigestChannel;

/**
 * Carry a notification somewhere other than the bell.
 *
 * An adapter answers honestly: `sent` only with a provider's acknowledgement, `awaiting_credentials` while there is
 * no provider to ask. The caller records the answer; it never infers one.
 */
interface NotificationChannelDelivery
{
    public function deliver(DigestChannel $channel, string $workspaceId, NotificationView $notification): DeliveryOutcome;
}
