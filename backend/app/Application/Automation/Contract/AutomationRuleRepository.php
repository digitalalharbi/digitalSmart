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

    /**
     * Record that the rule holds today for these campaigns (and no longer for any other), and return how many days
     * in a row it has held for each, and whether its action has already run in this streak.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, array{days: int, acted: bool}>
     */
    public function observe(string $ruleId, array $campaignIds, string $today): array;

    /**
     * Days in a row the rule has held, per campaign, as last observed.
     *
     * @return array<string, int>
     */
    public function streaks(string $ruleId): array;

    public function markActed(string $ruleId, string $campaignId): void;
}
