<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectIntegrationBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A project with two bound ad accounts (Meta and Google Ads), a tracking link, a conversion and a Creative Hub request — the
 * material a campaign plan is made of.
 */
final class CampaignPlanScene
{
    /** @return array{w: SyncedWorkspace, project: Project, meta: string, google: string, link: string, conversion: string, creative: string} */
    public static function create(): array
    {
        $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001', Provider::GoogleAds->value => '1234567890']);
        $project = $w->project(Provider::Meta);
        $clientId = (string) $project->getAttribute('client_id');
        $workspaceId = $w->workspaceId();

        // Bind the Google account to the same project, so one plan can reach both.
        ProjectIntegrationBinding::query()->create([
            'workspace_id' => $workspaceId, 'project_id' => $project->getKey(),
            'external_account_id' => $w->account(Provider::GoogleAds)->getKey(), 'sync_enabled' => true, 'bound_at' => now(),
        ]);

        $link = (string) Str::uuid7();
        DB::table('tracking_links')->insert(['id' => $link, 'workspace_id' => $workspaceId, 'client_id' => $clientId, 'project_id' => $project->getKey(),
            'public_token' => Str::random(20), 'name' => 'Ramadan landing', 'destination_url' => 'https://example.test/ramadan', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conversion = (string) Str::uuid7();
        DB::table('event_definitions')->insert(['id' => $conversion, 'workspace_id' => $workspaceId, 'client_id' => $clientId, 'name' => 'Lead form sent',
            'normalized_event' => 'lead', 'is_conversion' => true, 'created_at' => now(), 'updated_at' => now()]);
        $creative = (string) Str::uuid7();
        DB::table('creative_requests')->insert(['id' => $creative, 'workspace_id' => $workspaceId, 'client_id' => $clientId, 'project_id' => $project->getKey(),
            'title' => 'Ramadan hero video', 'kind' => 'video', 'state' => 'briefed', 'created_at' => now(), 'updated_at' => now()]);

        return ['w' => $w, 'project' => $project, 'meta' => (string) $w->account(Provider::Meta)->getKey(), 'google' => (string) $w->account(Provider::GoogleAds)->getKey(),
            'link' => $link, 'conversion' => $conversion, 'creative' => $creative];
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $scene, array $overrides = []): array
    {
        return [
            'project_id' => $scene['project']->getKey(),
            'name' => 'Ramadan Sale',
            'objective' => 'leads',
            'budget_type' => 'daily',
            'budget_minor' => 50_000,
            'currency' => 'SAR',
            'starts_at' => '2026-12-01T00:00:00+03:00',
            'ends_at' => '2026-12-31T00:00:00+03:00',
            'external_account_ids' => [$scene['meta'], $scene['google']],
            'tracking_link_id' => $scene['link'],
            'landing_url' => null,
            'conversion_event_id' => $scene['conversion'],
            'targeting' => ['countries' => ['sa', 'ae'], 'age_min' => 25, 'age_max' => 44, 'gender' => 'all', 'languages' => ['AR'], 'interests' => 'Home décor'],
            'placement' => ['mode' => 'automatic', 'placements' => []],
            'ad_copy' => ['headline' => 'Up to 40% off this Ramadan', 'primary_text' => 'Shop before Eid.', 'call_to_action' => 'Shop now'],
            'creative_request_ids' => [$scene['creative']],
            ...$overrides,
        ];
    }
}
