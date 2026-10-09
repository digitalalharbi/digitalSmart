<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Integration\Provider;

/**
 * One store this workspace reads, as the product knows it.
 *
 * `lastSyncedAt` and `ordersSyncedThrough` are both published and they are different facts. The first says when
 * somebody last asked the platform; the second says how far through its orders the answer actually got, and it
 * moves only when orders landed. A store that is failing has the first and not the second, which is precisely
 * the state a single "last updated" column hides.
 */
final readonly class StoreView
{
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public Provider $provider,
        public string $externalId,
        public string $name,
        public ?string $domain,
        public string $currency,
        public ?string $timezone,
        public bool $isActive,
        public ?string $lastSyncedAt,
        public ?string $ordersSyncedThrough,
        /** Whether anything can read it at all: a provider with no sync adapter cannot be read. */
        public bool $isReadable,
    ) {}
}
