<?php

declare(strict_types=1);

namespace App\Application\Project\DTO;

/** Read model for project listings and detail. */
final readonly class ProjectView
{
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public string $name,
        public string $slug,
        public string $status,
        public string $currency,
        public ?string $description,
        public ?string $startsOn,
        public ?string $endsOn,
        public bool $isDemo,
        public int $memberCount,
        /** Whether the actor is explicitly assigned to this project. */
        public bool $isAssigned,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}
}
