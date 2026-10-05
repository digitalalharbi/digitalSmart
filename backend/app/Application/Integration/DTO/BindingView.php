<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

final readonly class BindingView
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $projectName,
        public string $externalAccountId,
        public string $externalAccountName,
        public string $provider,
        public string $providerLabel,
        public bool $syncEnabled,
        public ?string $boundAt,
        public ?string $lastSyncedAt,
    ) {}
}
