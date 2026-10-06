<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

/** A raw provider event name, and which source it arrives from. */
final readonly class EventMappingView
{
    public function __construct(
        public string $id,
        public string $rawEventName,
        /** Null means "from any source": a name fired identically everywhere. */
        public ?string $measurementSourceId = null,
        public ?string $measurementSourceName = null,
    ) {}
}
