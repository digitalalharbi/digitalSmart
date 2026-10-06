<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

/** One customer of the client's store, normalised. */
final readonly class CustomerRecord
{
    public function __construct(
        public string $externalId,
        public ?string $name = null,
        /** The client's customer's own contact details. Never reaches a client-portal response. */
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $city = null,
        public ?string $country = null,
    ) {}
}
