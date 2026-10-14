<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

use App\Application\Advertising\DTO\CampaignOwnershipView;
use App\Domain\Advertising\CampaignOwnership;

/**
 * Reading and changing which project a campaign belongs to.
 *
 * Workspace-scoped by contract, and narrowed further by the project ids the actor may see — a campaign on
 * an account bound only to projects somebody cannot read is not theirs to assign.
 */
interface CampaignAssignmentRepository
{
    /**
     * Campaigns in a workspace, newest sync first, with their ownership resolved.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @param  list<CampaignOwnership>  $only  Restrict to these ownership states; empty means all.
     * @return list<CampaignOwnershipView>
     */
    public function page(
        string $workspaceId,
        ?array $visibleProjectIds,
        array $only,
        int $limit,
        int $offset,
    ): array;

    /**
     * How many campaigns are in each ownership state.
     *
     * Counted in SQL over the whole population rather than by measuring the page, because the number an
     * operator acts on is "how many need assigning", not "how many need assigning among the fifty I can
     * currently see".
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, int>
     */
    public function countByOwnership(string $workspaceId, ?array $visibleProjectIds): array;

    /** @param list<string>|null $visibleProjectIds */
    public function find(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $campaignId,
    ): ?CampaignOwnershipView;

    /** `null` clears the assignment and returns the campaign to its account's bindings. */
    public function assign(string $workspaceId, string $campaignId, ?string $projectId): void;
}
