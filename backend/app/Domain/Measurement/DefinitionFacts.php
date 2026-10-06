<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

/** The facts about one conversion definition that a diagnosis needs. */
final readonly class DefinitionFacts
{
    /** @param list<string> $rawEventNames every raw name mapped to this definition */
    public function __construct(
        public string $id,
        public string $name,
        public NormalizedEvent $normalizedEvent,
        public bool $isConversion,
        public bool $isPrimary,
        public bool $expectsValue,
        /** How many times any mapped name was seen in the window. Zero means never. */
        public int $observedCount = 0,
        /** Whether any of those observations carried a value. */
        public bool $hasValue = false,
        public array $rawEventNames = [],
    ) {}
}
