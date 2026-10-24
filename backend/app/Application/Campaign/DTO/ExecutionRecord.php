<?php

declare(strict_types=1);

namespace App\Application\Campaign\DTO;

use App\Domain\Advertising\Launch\ExecutionStatus;
use DateTimeImmutable;

final readonly class ExecutionRecord
{
    /**
     * @param  list<string>  $providerAdIds
     * @param  array<string, mixed>|null  $readback
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $launchId,
        public string $blueprintId,
        public string $projectId,
        public string $provider,
        public string $externalAccountId,
        public int $attempt,
        public string $idempotencyReference,
        public ExecutionStatus $status,
        public ?string $source,
        public ?string $providerCampaignId,
        public ?string $providerAdGroupId,
        public array $providerAdIds,
        public ?array $readback,
        public ?string $failureClassification,
        public ?string $message,
        public ?DateTimeImmutable $requestStartedAt,
        public ?DateTimeImmutable $requestFinishedAt,
    ) {}
}
