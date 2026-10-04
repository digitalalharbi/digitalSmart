<?php

declare(strict_types=1);

namespace App\Application\Client\DTO;

/** Read model for client listings and detail. Shaped for the API, not for the domain. */
final readonly class ClientView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $status,
        public ?string $legalName,
        public ?string $industry,
        public ?string $website,
        public ?string $primaryContactName,
        public ?string $primaryContactEmail,
        public ?string $primaryContactPhone,
        public ?string $defaultCurrency,
        public ?string $notes,
        public bool $isDemo,
        public int $projectCount,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}
}
