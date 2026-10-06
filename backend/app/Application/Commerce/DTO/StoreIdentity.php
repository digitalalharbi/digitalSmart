<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/** Just enough about a store to route a webhook to it. */
final readonly class StoreIdentity
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $clientId,
        public string $externalId,
        public string $currency,
    ) {}
}
