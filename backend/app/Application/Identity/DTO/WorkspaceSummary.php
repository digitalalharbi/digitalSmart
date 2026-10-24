<?php

declare(strict_types=1);

namespace App\Application\Identity\DTO;

final readonly class WorkspaceSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $roleSlug,
        public string $roleName,
        public string $defaultCurrency,
        public string $defaultLocale,
        public string $timezone,
        /** What kind of organisation it is. Changes wording and defaults, never authorization. */
        public string $type = 'agency',
    ) {}
}
