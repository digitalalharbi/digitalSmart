<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Commerce\Contract\WebhookEventReader;
use App\Application\Commerce\DTO\RecordedWebhookEvent;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\StoreWebhookEvent;

final readonly class EloquentWebhookEventReader implements WebhookEventReader
{
    public function find(string $eventId): ?RecordedWebhookEvent
    {
        $event = StoreWebhookEvent::query()->whereKey($eventId)->first();

        if (! $event instanceof StoreWebhookEvent) {
            return null;
        }

        /** @var Provider $provider */
        $provider = $event->getAttribute('provider');
        /** @var array<string, mixed> $payload */
        $payload = $event->getAttribute('payload') ?? [];

        return new RecordedWebhookEvent(
            id: (string) $event->getKey(),
            workspaceId: (string) $event->getAttribute('workspace_id'),
            storeId: $event->getAttribute('store_id') === null ? null : (string) $event->getAttribute('store_id'),
            provider: $provider,
            topic: (string) $event->getAttribute('topic'),
            signatureVerified: (bool) $event->getAttribute('signature_verified'),
            payload: $payload,
        );
    }
}
