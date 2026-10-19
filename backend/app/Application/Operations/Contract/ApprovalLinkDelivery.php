<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

/**
 * Puts a one-time approval link in front of one person, on one channel.
 *
 * Returns what honestly happened: `sent`, `logged` (development only: written to the application log), or
 * `awaiting_credentials` when the channel has no provider account configured. A link that was not sent is still
 * recorded, so the trail shows the attempt — but it is never shown to anyone else instead.
 */
interface ApprovalLinkDelivery
{
    /** @param 'whatsapp'|'email' $channel */
    public function deliver(string $channel, string $userId, string $url, string $title, string $expiresAt): string;
}
