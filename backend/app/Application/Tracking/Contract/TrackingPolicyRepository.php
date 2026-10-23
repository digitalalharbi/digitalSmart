<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Domain\Tracking\Dispatch\ConsentPolicy;

interface TrackingPolicyRepository
{
    /** @return array{consent_policy: ConsentPolicy, retention_days: int} defaults when the project has saved none */
    public function for(string $projectId): array;

    public function save(string $workspaceId, string $projectId, ConsentPolicy $consent, int $retentionDays, string $actorId): void;
}
