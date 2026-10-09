<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Integration\Provider;

/**
 * One store a connection can read, and whether this workspace already reads it.
 *
 * The registered ones are **shown rather than filtered out**, with the client they are registered to. A merchant
 * missing from the list reads as "the connection cannot see it", and somebody then goes looking at the provider
 * for a problem that does not exist. "Already connected to Alhambra Retail" answers the question instead.
 */
final readonly class ConnectableStore
{
    public function __construct(
        public Provider $provider,
        public string $externalId,
        public string $name,
        public string $currency,
        public ?string $domain,
        public ?string $timezone,
        public bool $isRegistered,
        /** Which client it is already registered to, where it is. Named, because that is the useful part. */
        public ?string $registeredToClientName,
    ) {}
}
