<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use DateTimeImmutable;

final readonly class DispatchRequest
{
    /** @param array<string, string> $clickIds */
    public function __construct(
        public string $workspaceId,
        public string $provider,
        public string $providerEvent,
        public string $eventId,
        public string $channel,
        public array $clickIds,
        public DateTimeImmutable $occurredAt,
        public bool $sandbox,
    ) {}
}
