<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Measurement\NormalizedEvent;

/**
 * A key event the property reports, offered as a candidate conversion.
 *
 * **A suggestion, never a conversion.** GA4 marking an event key is GA4's opinion — often a
 * default, sometimes left by whoever set the property up years ago. This product's registry
 * records what *this agency* counts for *this client*, so a person confirms each one and that
 * confirmation is what creates a definition with an author and an audit entry.
 *
 * `normalizedEvent` is null for every name this product cannot place from the provider's own
 * published vocabulary. Null means "ask", not "unknown and probably custom" — the whole point
 * of the measurement layer is that nothing infers meaning from a name.
 */
final readonly class ConversionSuggestion
{
    public function __construct(
        public string $rawEventName,
        /** Null when the name is not one Google documents. The person chooses. */
        public ?NormalizedEvent $normalizedEvent,
        public bool $alreadyMapped,
        /** False for events GA4 marks key by default, such as `purchase`. */
        public bool $isCustom = false,
        /** Counted once per session rather than per event, which changes what it means. */
        public bool $countedOncePerSession = false,
        public ?string $defaultValue = null,
        public ?string $defaultCurrency = null,
    ) {}
}
