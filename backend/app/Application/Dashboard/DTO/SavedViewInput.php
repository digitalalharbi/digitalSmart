<?php

declare(strict_types=1);

namespace App\Application\Dashboard\DTO;

use App\Application\Analytics\DTO\AnalyticsRequest;
use App\Domain\Dashboard\Visibility;

/** What a caller asked to save. */
final readonly class SavedViewInput
{
    public function __construct(
        public string $name,
        public AnalyticsRequest $query,
        public Visibility $visibility = Visibility::Private,
        public ?string $projectId = null,
        public ?string $description = null,
        public bool $isDefault = false,
    ) {}
}
