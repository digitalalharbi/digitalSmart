<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Advertising\Launch\ExecutionStatus;

/**
 * The plan's state, read from its accounts' executions. Every account verified: `verified`. Some created and some not: `partial`
 * — and nothing already created is deleted. None created: `failed`. Anything still running: `creating`.
 */
final readonly class LaunchProgress
{
    public function __construct(
        private LaunchRepository $launches,
        private BlueprintRepository $blueprints,
    ) {}

    public function settle(string $workspaceId, string $blueprintId): BlueprintStatus
    {
        $executions = $this->latestLaunch($workspaceId, $blueprintId);
        $statuses = array_map(static fn ($e): ExecutionStatus => $e->status, $executions);

        $status = match (true) {
            $statuses === [] => BlueprintStatus::Failed,
            array_filter($statuses, static fn (ExecutionStatus $s): bool => ! $s->isTerminal()) !== [] => BlueprintStatus::Creating,
            array_filter($statuses, static fn (ExecutionStatus $s): bool => $s !== ExecutionStatus::Verified) === [] => BlueprintStatus::Verified,
            array_filter($statuses, static fn (ExecutionStatus $s): bool => $s->createdSomething()) !== [] => BlueprintStatus::Partial,
            default => BlueprintStatus::Failed,
        };

        $this->blueprints->setStatus($workspaceId, $blueprintId, $status, null, null);

        return $status;
    }

    /** @return list<\App\Application\Campaign\DTO\ExecutionRecord> */
    private function latestLaunch(string $workspaceId, string $blueprintId): array
    {
        $all = $this->launches->executionsForBlueprint($workspaceId, $blueprintId);
        $latest = $all[0]->launchId ?? null;

        return array_values(array_filter($all, static fn ($e): bool => $e->launchId === $latest));
    }
}
