<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign;

use App\Application\Campaign\Contract\CampaignReconciler;
use App\Application\Campaign\DTO\ExecutionRecord;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Advertising\Launch\ProviderCreatePlan;
use App\Domain\Advertising\MetricSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A verified creation, written into the same campaign, ad group and ad tables a sync writes — keyed on the platform's own ids, so
 * the next sync updates these rows instead of adding a second copy. Assigned to the plan's project, paused, and labelled with its
 * source and the execution that made it.
 */
final readonly class EloquentCampaignReconciler implements CampaignReconciler
{
    public function reconcile(ExecutionRecord $execution, ProviderCreatePlan $plan, string $name, MetricSource $source): void
    {
        if ($execution->providerCampaignId === null || $execution->providerAdGroupId === null) {
            return;
        }

        $metadata = json_encode(['source' => $source->value, 'launch_execution_id' => $execution->id, 'initial_state' => $plan->initialState], JSON_THROW_ON_ERROR);
        $now = now();

        DB::transaction(function () use ($execution, $plan, $name, $metadata, $now): void {
            $campaignId = $this->upsert('campaigns', $execution, $execution->providerCampaignId, [
                'name' => $name,
                'raw_objective' => $plan->objective,
                'status' => EntityStatus::Paused->value,
                'raw_status' => mb_substr($plan->initialState, 0, 190),
                'budget_mode' => $plan->budgetType,
                'budget_minor' => $plan->budgetMinor,
                'currency' => $plan->accountCurrency ?? $plan->currency,
                'starts_at' => $plan->startsAt,
                'ends_at' => $plan->endsAt,
                'project_id' => $execution->projectId,
                'metadata' => $metadata,
                'provider_created_at' => $now,
            ]);

            $adGroupId = $this->upsert('ad_groups', $execution, $execution->providerAdGroupId, [
                'campaign_id' => $campaignId,
                'name' => $name,
                'status' => EntityStatus::Paused->value,
                'currency' => $plan->accountCurrency ?? $plan->currency,
                'metadata' => $metadata,
                'provider_created_at' => $now,
            ]);

            foreach ($execution->providerAdIds as $i => $adId) {
                $this->upsert('ads', $execution, $adId, [
                    'campaign_id' => $campaignId,
                    'ad_group_id' => $adGroupId,
                    'name' => sprintf('%s — %s', $name, $plan->creatives[$i]['title'] ?? sprintf('ad %d', $i + 1)),
                    'status' => EntityStatus::Paused->value,
                    'metadata' => $metadata,
                    'provider_created_at' => $now,
                ]);
            }
        });
    }

    /** @param array<string, mixed> $values */
    private function upsert(string $table, ExecutionRecord $execution, string $externalId, array $values): string
    {
        $existing = DB::table($table)->where('external_account_id', $execution->externalAccountId)->where('external_id', $externalId)->value('id');

        if (is_string($existing)) {
            DB::table($table)->where('id', $existing)->update([...$values, 'updated_at' => now()]);

            return $existing;
        }

        $id = (string) Str::uuid7();
        DB::table($table)->insert([
            ...$values,
            'id' => $id,
            'workspace_id' => $execution->workspaceId,
            'external_account_id' => $execution->externalAccountId,
            'provider' => $execution->provider,
            'external_id' => $externalId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
