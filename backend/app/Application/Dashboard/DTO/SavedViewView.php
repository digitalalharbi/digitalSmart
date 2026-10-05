<?php

declare(strict_types=1);

namespace App\Application\Dashboard\DTO;

use App\Domain\Dashboard\Visibility;

/** A saved view as a reader sees it. */
final readonly class SavedViewView
{
    /** @param array<string, mixed> $query */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public Visibility $visibility,
        public array $query,
        public bool $isDefault,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $authorName,
        /** Whether the current actor may change or delete it. */
        public bool $isOwnedByActor,
        public ?string $lastUsedAt,
        public string $updatedAt,
    ) {}
}
