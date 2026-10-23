<?php

declare(strict_types=1);

namespace App\Application\Leads\Automation;

use App\Application\Shared\DTO\OwnerCandidate;

/** Per-project lead automation, and the people it may hand a lead to. */
interface LeadAutomationRepository
{
    public function settings(string $workspaceId, string $projectId): LeadAutomationSettings;

    public function save(string $workspaceId, LeadAutomationSettings $settings, string $updatedBy): void;

    /**
     * Who may be given this project's leads: an active member who can read leads and can reach this project — through
     * the permission that shows every project, or by being on it. Ordered by id so the order is the same every time.
     *
     * @return list<OwnerCandidate>
     */
    public function eligibleOwners(string $workspaceId, string $projectId): array;

    /**
     * The next person in the rota, advancing it. Atomic: two callers at once get two different people, because the choice
     * is made under a lock on the project's pointer.
     */
    public function nextInRotation(string $workspaceId, string $projectId): ?string;

    /** The workspace's time zone, which decides how a deadline is written down for people. */
    public function timezone(string $workspaceId): string;
}
