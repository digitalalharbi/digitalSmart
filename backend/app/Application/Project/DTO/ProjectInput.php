<?php

declare(strict_types=1);

namespace App\Application\Project\DTO;

final readonly class ProjectInput
{
    public function __construct(
        public string $clientId,
        public string $name,
        public string $currency,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
        public bool $isDemo = false,
    ) {}
}
