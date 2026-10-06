<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Integration\Provider;
use App\Domain\Measurement\MeasurementSourceKind;

final readonly class MeasurementSourceInput
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $clientId,
        public MeasurementSourceKind $kind,
        public string $externalId,
        public string $name,
        public ?Provider $provider = null,
        public ?string $projectId = null,
        public ?string $providerConnectionId = null,
        public bool $isActive = true,
        public array $metadata = [],
    ) {}
}
