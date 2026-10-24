<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

use App\Application\Campaign\DTO\ExecutionRecord;
use App\Domain\Advertising\Launch\ExecutionStatus;

interface LaunchRepository
{
    /** @param array<string, mixed> $snapshot */
    public function createLaunch(string $id, string $workspaceId, string $clientId, string $projectId, string $blueprintId, int $version, string $fingerprint, array $snapshot, ?string $requestedBy, ?string $approvedBy, string $executedBy): void;

    public function createExecution(string $id, string $workspaceId, string $launchId, string $provider, string $externalAccountId, string $idempotencyReference, ExecutionStatus $status, ?string $message, ?string $apiReference): void;

    public function execution(string $workspaceId, string $id): ?ExecutionRecord;

    /** @return list<ExecutionRecord> newest launch first */
    public function executionsForBlueprint(string $workspaceId, string $blueprintId): array;

    /** @return array<string, mixed> the launch plan as approved */
    public function snapshot(string $workspaceId, string $launchId): array;

    /** @param array<string, mixed> $fields */
    public function updateExecution(string $workspaceId, string $id, array $fields): void;
}
