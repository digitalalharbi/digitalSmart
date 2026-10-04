<?php

declare(strict_types=1);

namespace App\Domain\Client;

use App\Domain\Identity\Email;

/** Descriptive client attributes, grouped so the entity is not a bag of setters. */
final readonly class ClientProfile
{
    public function __construct(
        public ?string $legalName = null,
        public ?string $industry = null,
        public ?string $website = null,
        public ?string $primaryContactName = null,
        public ?Email $primaryContactEmail = null,
        public ?string $primaryContactPhone = null,
        public ?string $defaultCurrency = null,
        public ?string $notes = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }
}
