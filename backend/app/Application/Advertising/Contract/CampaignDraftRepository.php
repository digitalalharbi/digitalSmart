<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

interface CampaignDraftRepository
{
    /** @param array<string, mixed> $translation */
    public function create(string $workspaceId, string $batchId, string $projectId, ?string $clientId, string $provider, string $name, string $goal, ?string $objective, int $dailyBudgetMinor, string $currency, array $translation, string $userId): string;

    public function attachApproval(string $draftId, string $approvalId): void;

    /** @return array{client_id: string|null, name: string}|null */
    public function project(string $workspaceId, string $projectId): ?array;
}
