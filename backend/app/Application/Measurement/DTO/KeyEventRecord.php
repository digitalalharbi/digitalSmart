<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

/**
 * An event the property's own owner marked as a key event.
 *
 * This is **a suggestion, never a conversion**. GA4 marking an event key is GA4's opinion
 * — often a default, sometimes left over from whoever set the property up years ago — and this
 * product's conversion registry records what *this agency* counts for *this client*. Importing
 * these automatically would mean the product decided what success means, which is the one
 * decision it must never make on somebody's behalf.
 *
 * So these are offered as candidates a person confirms, and the confirmation is what creates
 * an event definition with an author and an audit entry.
 */
final readonly class KeyEventRecord
{
    public function __construct(
        /** The raw event name, e.g. `purchase` or `generate_lead`. */
        public string $eventName,
        /** `ONCE_PER_EVENT` or `ONCE_PER_SESSION`. */
        public ?string $countingMethod = null,
        /** False for events GA4 marks key by default, such as `purchase`. */
        public bool $isCustom = false,
        /** A value GA4 applies when the event arrives without one, in the major unit. */
        public ?string $defaultValue = null,
        public ?string $defaultCurrency = null,
    ) {}

    /**
     * Whether GA4 counts this once per session rather than once per event.
     *
     * It changes what the number means: counted per session, two purchases in one visit are
     * one key event. Surfaced so nobody reconciles it against an order count and concludes
     * the tracking is broken.
     */
    public function isCountedOncePerSession(): bool
    {
        return $this->countingMethod === 'ONCE_PER_SESSION';
    }
}
