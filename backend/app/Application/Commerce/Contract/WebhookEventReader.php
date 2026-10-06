<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Application\Commerce\DTO\RecordedWebhookEvent;

/**
 * Reads back a webhook that was already recorded.
 *
 * Separate from the journal that writes them, because the queued worker needs to *read* the payload and
 * has no business being able to record a new arrival. The split also makes the security property
 * explicit: the only payload the worker can act on is one that has already been through the signature
 * check and written down.
 */
interface WebhookEventReader
{
    public function find(string $eventId): ?RecordedWebhookEvent;
}
