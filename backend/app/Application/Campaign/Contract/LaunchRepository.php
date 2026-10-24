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

    /**
     * What in the launch path is waiting on somebody: plans awaiting approval or activation, and accounts whose creation is unknown,
     * failed, partial, mismatched, or whose activation failed.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<array{kind: string, blueprint_id: string, name: string, client_id: string, client_name: string, project_id: string, project_name: string, provider: string|null, since: string}>
     */
    public function issues(string $workspaceId, ?array $visibleProjectIds, int $limit): array;
}
