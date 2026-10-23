<?php

declare(strict_types=1);

namespace App\Application\Leads\Automation;

use DateTimeImmutable;

interface LeadFollowUpReadModel
{
    /**
     * Open leads of one project that someone still has to deal with, newest first.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<FollowUpRow>
     */
    public function openLeads(string $workspaceId, ?array $visibleProjectIds, string $projectId, int $limit): array;

    /**
     * Leads in projects that promise a response time and have not been attempted, across every workspace. For the
     * scheduled alert, which runs as nobody.
     *
     * @return list<FollowUpRow>
     */
    public function awaitingFirstAttempt(DateTimeImmutable $since): array;
}
