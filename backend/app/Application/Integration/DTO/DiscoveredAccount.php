<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

use App\Domain\Integration\ExternalAccountStatus;

/** One ad account as an adapter reports it, already normalised. */
final readonly class DiscoveredAccount
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $currency = null,
        public ?string $timezone = null,
        public ExternalAccountStatus $status = ExternalAccountStatus::Unknown,
    ) {}
}
