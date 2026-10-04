<?php

declare(strict_types=1);

namespace App\Application\Project\DTO;

use App\Domain\Project\ProjectStatus;

final readonly class ProjectFilters
{
    /** @param list<ProjectStatus> $statuses */
    public function __construct(
        public ?string $search = null,
        public array $statuses = [],
        public ?string $clientId = null,
        public ?bool $isDemo = null,
        public string $sort = 'name',
        public string $direction = 'asc',
    ) {}

    /** @return list<string> */
    public function statusValues(): array
    {
        return array_map(static fn (ProjectStatus $status): string => $status->value, $this->statuses);
    }
}
