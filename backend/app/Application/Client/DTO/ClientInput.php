<?php

declare(strict_types=1);

namespace App\Application\Client\DTO;

/**
 * Validated input for creating or updating a client.
 *
 * Built by the Interface layer from a Form Request, so the use case never sees
 * a raw HTTP payload.
 */
final readonly class ClientInput
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $legalName = null,
        public ?string $industry = null,
        public ?string $website = null,
        public ?string $primaryContactName = null,
        public ?string $primaryContactEmail = null,
        public ?string $primaryContactPhone = null,
        public ?string $defaultCurrency = null,
        public ?string $notes = null,
        public bool $isDemo = false,
    ) {}
}
