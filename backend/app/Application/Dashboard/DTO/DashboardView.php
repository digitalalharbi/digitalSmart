<?php

declare(strict_types=1);

namespace App\Application\Dashboard\DTO;

use App\Domain\Dashboard\Visibility;

final readonly class DashboardView
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<WidgetView>  $widgets
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public Visibility $visibility,
        public array $filters,
        public bool $isDefault,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $authorName,
        public bool $isOwnedByActor,
        public array $widgets,
        public string $updatedAt,
    ) {}
}
