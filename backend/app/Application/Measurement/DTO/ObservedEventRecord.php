<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

/**
 * One raw event name's count on one day, as a provider reported it.
 *
 * Deliberately *not* a conversion. This is the raw observation: the name the site fired and
 * how many times. What it means is decided by the event mapping, which a person wrote. Nothing
 * in this record implies that `purchase_complete` is a purchase.
 */
final readonly class ObservedEventRecord
{
    public function __construct(
        public string $rawEventName,
        /** `Y-m-d`. The provider's own day boundary, in the property's time zone. */
        public string $date,
        public int $count,
        /**
         * Money in minor units, or null.
         *
         * Null means **the provider reported no value**, which is a different fact from zero —
         * a lead has no value and a purchase arriving without one is a tracking fault. Writing
         * zero here would erase that distinction and silence the fault.
         */
        public ?int $valueMinor = null,
        public ?string $currency = null,
    ) {}
}
