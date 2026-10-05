<?php

declare(strict_types=1);

namespace App\Application\Dashboard\DTO;

use App\Domain\Dashboard\Visibility;

/** A dashboard and its whole widget set, saved together. */
final readonly class DashboardInput
{
    /**
     * @param  list<WidgetInput>|null  $widgets  null leaves the existing set alone
     * @param  array<string, mixed>  $filters  inherited by every widget that does not override them
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public Visibility $visibility = Visibility::Private,
        public ?string $projectId = null,
        public array $filters = [],
        public bool $isDefault = false,
        public ?array $widgets = null,
    ) {}
}
