<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

/**
 * One analytics property the connected user can read, as the provider describes it.
 *
 * Discovery is deliberately separate from registration. The product shows what the account can
 * see and a person chooses which property measures which client — because the mapping between
 * a GA4 property and an agency's client is a commercial fact, not a technical one, and an
 * agency's account routinely holds properties for clients it no longer works with.
 */
final readonly class DiscoveredProperty
{
    /** @param list<DataStreamRecord> $streams */
    public function __construct(
        /** The provider's own resource name, e.g. `properties/123456`. Stored verbatim. */
        public string $propertyId,
        public string $displayName,
        public string $accountName,
        public string $accountDisplayName,
        /** `PROPERTY_TYPE_ORDINARY`, `PROPERTY_TYPE_SUBPROPERTY`, `PROPERTY_TYPE_ROLLUP`, … */
        public ?string $propertyType = null,
        public array $streams = [],
    ) {}

    /**
     * Whether this property aggregates others.
     *
     * Worth knowing before anybody reports from it: a roll-up property counts the same event
     * as its source properties do, so measuring a client through both double-counts.
     */
    public function isAggregate(): bool
    {
        return $this->propertyType === 'PROPERTY_TYPE_ROLLUP';
    }
}
