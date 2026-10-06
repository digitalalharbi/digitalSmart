<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Integration\Provider;
use App\Domain\Measurement\MeasurementFreshness;
use App\Domain\Measurement\MeasurementSourceKind;
use DateTimeImmutable;

/** One thing that measures a client's work, and how current it is. */
final readonly class MeasurementSourceView
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public ?string $projectId,
        public ?string $projectName,
        public ?Provider $provider,
        public MeasurementSourceKind $kind,
        public string $externalId,
        public string $name,
        public MeasurementFreshness $freshness,
        public bool $isActive,
        public ?DateTimeImmutable $lastSyncedAt = null,
        public ?DateTimeImmutable $lastEventAt = null,
        public array $metadata = [],
    ) {}
}
