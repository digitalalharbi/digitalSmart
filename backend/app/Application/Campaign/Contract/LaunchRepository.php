<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

use App\Application\Campaign\DTO\ExecutionRecord;
use App\Domain\Advertising\Launch\ActivationStatus;
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
     * Move an execution out of `$from` only if it is still there, in one statement. The answer is whether this caller won: of two
     * workers (or two people pressing reconcile) given the same record, exactly one gets `true` and the other must stand down.
     *
     * @param  array<string, mixed>  $fields
     */
    public function transition(string $workspaceId, string $id, ExecutionStatus $from, array $fields): bool;

    /**
     * The same compare-and-set for the activation half of the record: it moves only if `activation_status` is still `$from`.
     *
     * @param  array<string, mixed>  $fields
     */
    public function transitionActivation(string $workspaceId, string $id, ActivationStatus $from, array $fields): bool;

    /**
     * What in the launch path is waiting on somebody: plans awaiting approval or activation, and accounts whose creation is unknown,
     * failed, partial, mismatched, or whose activation failed.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<array{kind: string, blueprint_id: string, name: string, client_id: string, client_name: string, project_id: string, project_name: string, provider: string|null, since: string}>
     */
    public function issues(string $workspaceId, ?array $visibleProjectIds, int $limit): array;
}
