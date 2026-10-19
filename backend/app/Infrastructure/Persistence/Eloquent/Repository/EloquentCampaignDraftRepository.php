<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Advertising\Contract\CampaignDraftRepository;
use App\Application\Shared\Contract\IdentifierGenerator;
use Illuminate\Support\Facades\DB;

final readonly class EloquentCampaignDraftRepository implements CampaignDraftRepository
{
    public function __construct(private IdentifierGenerator $ids) {}

    public function create(string $workspaceId, string $batchId, string $projectId, ?string $clientId, string $provider, string $name, string $goal, ?string $objective, int $dailyBudgetMinor, string $currency, array $translation, string $userId): string
    {
        $id = $this->ids->generate();

        DB::table('campaign_drafts')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'batch_id' => $batchId,
            'project_id' => $projectId,
            'client_id' => $clientId,
            'provider' => $provider,
            'name' => $name,
            'goal' => $goal,
            'provider_objective' => $objective,
            'daily_budget_minor' => $dailyBudgetMinor,
            'currency' => $currency,
            'status' => 'paused',
            'translation' => json_encode($translation, JSON_THROW_ON_ERROR),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function attachApproval(string $draftId, string $approvalId): void
    {
        DB::table('campaign_drafts')->where('id', $draftId)->update(['approval_id' => $approvalId, 'updated_at' => now()]);
    }

    public function project(string $workspaceId, string $projectId): ?array
    {
        $row = DB::table('projects')->where(['workspace_id' => $workspaceId, 'id' => $projectId])->first(['client_id', 'name']);

        return $row === null ? null : ['client_id' => $row->client_id === null ? null : (string) $row->client_id, 'name' => (string) $row->name];
    }
}
