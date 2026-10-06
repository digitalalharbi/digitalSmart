<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Measurement\NormalizedEvent;

final readonly class EventDefinitionInput
{
    /** @param list<array{raw_event_name: string, measurement_source_id: ?string}> $mappings */
    public function __construct(
        public string $clientId,
        public string $name,
        public NormalizedEvent $normalizedEvent,
        public ?string $projectId = null,
        public ?string $description = null,
        public bool $isConversion = true,
        public bool $isPrimary = false,
        public bool $expectsValue = false,
        public array $mappings = [],
    ) {}
}
