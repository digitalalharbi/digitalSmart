<?php

declare(strict_types=1);

namespace App\Application\Automation\Contract;

use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;

interface AutomationRuleRepository
{
    /**
     * The rules a reader may know exist: agency-wide ones, those on a project they can see, and those on a client
     * that has such a project. `$visibleProjectIds` null means every project.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<RuleView>
     */
    public function visibleTo(string $workspaceId, ?array $visibleProjectIds, bool $activeOnly = false): array;

    public function find(string $workspaceId, string $id): ?RuleView;

    public function nameTaken(string $workspaceId, string $name, ?string $exceptId = null): bool;

    public function create(string $workspaceId, string $actorId, RuleInput $input): RuleView;

    public function update(string $workspaceId, string $id, RuleInput $input): RuleView;

    public function delete(string $workspaceId, string $id): void;
}
