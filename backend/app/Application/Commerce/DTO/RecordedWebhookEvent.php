<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Integration\Provider;

/** A webhook as it was written down, with the verdict on its signature. */
final readonly class RecordedWebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $storeId,
        public Provider $provider,
        public string $topic,
        public bool $signatureVerified,
        public array $payload,
    ) {}
}
