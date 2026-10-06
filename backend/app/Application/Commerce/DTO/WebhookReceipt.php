<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/**
 * What happened to an arriving webhook.
 *
 * Every outcome answers the provider with a 2xx, including the refusals. A non-2xx makes a provider
 * redeliver, and redelivering a payload whose signature does not verify would achieve nothing except
 * filling the journal — so the response says "received and rejected" rather than "try again".
 */
final readonly class WebhookReceipt
{
    private function __construct(
        public string $outcome,
        public string $topic,
        public ?string $eventId = null,
        public ?string $storeId = null,
        public ?string $workspaceId = null,
    ) {}

    public static function queued(string $topic, string $eventId, string $storeId, string $workspaceId): self
    {
        return new self('queued', $topic, $eventId, $storeId, $workspaceId);
    }

    public static function duplicate(string $topic): self
    {
        return new self('duplicate', $topic);
    }

    public static function ignored(string $topic, string $eventId): self
    {
        return new self('ignored', $topic, $eventId);
    }

    public static function rejected(string $topic, string $eventId): self
    {
        return new self('rejected', $topic, $eventId);
    }

    /** No store registered for the merchant the payload named, so there is nowhere to record it. */
    public static function unknownStore(string $topic): self
    {
        return new self('unknown_store', $topic);
    }

    public function shouldProcess(): bool
    {
        return $this->outcome === 'queued';
    }
}
