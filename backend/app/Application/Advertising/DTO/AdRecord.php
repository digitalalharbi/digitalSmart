<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\EntityStatus;
use DateTimeImmutable;

final readonly class AdRecord
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $externalId,
        public string $adGroupExternalId,
        public string $campaignExternalId,
        public string $name,
        public EntityStatus $status,
        public ?string $rawStatus = null,
        public ?string $rawEffectiveStatus = null,
        public ?string $creativeExternalId = null,
        /** Why the platform rejected it, when it did. */
        public ?string $reviewFeedback = null,
        public ?DateTimeImmutable $providerCreatedAt = null,
        public ?DateTimeImmutable $providerUpdatedAt = null,
        public array $metadata = [],
    ) {}
}
