<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Measurement\NormalizedEvent;

/** One thing a project counts, and the raw names that mean it. */
final readonly class EventDefinitionView
{
    /** @param list<EventMappingView> $mappings */
    public function __construct(
        public string $id,
        public string $clientId,
        public ?string $projectId,
        public string $name,
        public NormalizedEvent $normalizedEvent,
        public bool $isConversion,
        public bool $isPrimary,
        public bool $expectsValue,
        public ?string $description = null,
        public array $mappings = [],
        /** How many times a mapped name has been seen, across the window asked for. */
        public ?int $observedCount = null,
        public ?string $lastSeenDate = null,
    ) {}
}
