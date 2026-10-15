<?php

declare(strict_types=1);

namespace App\Application\SurfaceView\DTO;

use App\Domain\Dashboard\Visibility;
use App\Domain\SurfaceView\SurfaceKind;

final readonly class SurfaceViewView
{
    /** @param array<string, string|list<string>> $filters */
    public function __construct(
        public string $id,
        public SurfaceKind $surface,
        public string $name,
        public Visibility $visibility,
        public ?string $projectId,
        public ?string $projectName,
        public array $filters,
        public ?string $authorId,
        public ?string $authorName,
        public bool $isMine,
    ) {}
}
