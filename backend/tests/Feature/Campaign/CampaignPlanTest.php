<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CampaignPlanScene;

/*
|--------------------------------------------------------------------------
| Campaign plans
|--------------------------------------------------------------------------
|
| A campaign described once and kept, before any platform hears of it. It is made of things that already exist — the ad
| accounts bound to its project, a tracking link, a conversion, Creative Hub requests — and refuses anything else. Editing a plan
| under review sends it back to draft with a new version. Nothing here creates anything on a platform.
|
*/

it('keeps a plan made of what already exists, as a draft, and calls no platform', function (): void {
    $s = CampaignPlanScene::create();
    $campaigns = DB::table('campaigns')->count();

    $plan = $this->actingAs($s['w']->scenario->owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s))->assertCreated()->json('data');

    expect($plan)->toMatchArray(['status' => 'draft', 'version' => 1, 'objective' => 'leads', 'destination' => 'https://example.test/ramadan', 'editable' => true])
        ->and(array_column($plan['targets'], 'provider'))->toEqualCanonicalizing(['meta', 'google_ads'])
        ->and($plan['targeting']['countries'])->toBe(['SA', 'AE'])
        ->and($plan['creatives'][0]['title'])->toBe('Ramadan hero video')
        ->and($plan['conversion']['name'])->toBe('Lead form sent')
        ->and(DB::table('campaigns')->count())->toBe($campaigns);
    expect(DB::table('audit_logs')->where('action', 'campaign_blueprint.created')->where('auditable_id', $plan['id'])->exists())->toBeTrue();
});

it('refuses an ad account the project is not bound to, and references from another project or client', function (): void {
    $s = CampaignPlanScene::create();
    $owner = $s['w']->scenario->owner;
    $otherProject = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $s['w']->workspaceId()]))->create();
    $foreignLink = (string) Str::uuid7();
    DB::table('tracking_links')->insert(['id' => $foreignLink, 'workspace_id' => $s['w']->workspaceId(), 'client_id' => $otherProject->getAttribute('client_id'),
        'project_id' => $otherProject->getKey(), 'public_token' => Str::random(20), 'name' => 'Other', 'destination_url' => 'https://example.test/other', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    // The Google account is bound to the Meta project only in planScene; the other project has nothing bound.
    $this->actingAs($owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s, ['project_id' => $otherProject->getKey(), 'tracking_link_id' => null, 'conversion_event_id' => null, 'creative_request_ids' => []]))
        ->assertUnprocessable()->assertJsonPath('code', 'account_not_bound');
    $this->actingAs($owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s, ['tracking_link_id' => $foreignLink]))
        ->assertUnprocessable()->assertJsonPath('code', 'reference_outside_project');
    $this->actingAs($owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s, ['creative_request_ids' => [(string) Str::uuid7()]]))
        ->assertUnprocessable()->assertJsonPath('code', 'reference_outside_project');
    $this->actingAs($owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s, ['budget_type' => 'lifetime', 'ends_at' => null]))->assertUnprocessable();
    expect(DB::table('campaign_blueprints')->count())->toBe(0);
});

it('submits a complete plan, refuses an incomplete one with what is missing, and sends an edited plan back to draft', function (): void {
    $s = CampaignPlanScene::create();
    $owner = $s['w']->scenario->owner;
    $id = $this->actingAs($owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s, ['external_account_ids' => [], 'tracking_link_id' => null]))->json('data.id');

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/submit")
        ->assertUnprocessable()->assertJsonPath('code', 'blueprint_incomplete');
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.missing', ['at least one ad account', 'a tracking link or a landing page']);

    $this->actingAs($owner)->putJson("/api/campaign-plans/{$id}", CampaignPlanScene::payload($s))->assertOk()->assertJsonPath('data.version', 2);
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'ready_for_review');

    // Editing what is under review returns it to draft, with a new version, so no approval can name an unread plan.
    $this->actingAs($owner)->putJson("/api/campaign-plans/{$id}", CampaignPlanScene::payload($s, ['budget_minor' => 60_000]))
        ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 3)->assertJsonPath('data.submitted_by', null);

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/cancel", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/cancel", ['reason' => 'Client postponed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->actingAs($owner)->putJson("/api/campaign-plans/{$id}", CampaignPlanScene::payload($s))->assertUnprocessable()->assertJsonPath('code', 'not_editable');
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/submit")->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
});

it('shows a plan only to people who can see its project, and lets a creative read but not write', function (): void {
    $s = CampaignPlanScene::create();
    $id = $this->actingAs($s['w']->scenario->owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s))->json('data.id');

    Auth::forgetGuards();
    $buyer = $s['w']->scenario->addMember(SystemRole::MediaBuyer);
    $this->actingAs($buyer)->getJson("/api/campaign-plans/{$id}")->assertNotFound();
    $this->actingAs($buyer)->getJson('/api/campaign-plans')->assertOk()->assertJsonCount(0, 'data');

    Auth::forgetGuards();
    $creative = $s['w']->scenario->addMember(SystemRole::Creative);
    DB::table('project_members')->insert(['id' => (string) Str::uuid7(), 'project_id' => $s['project']->getKey(), 'user_id' => $creative->getKey(),
        'role_id' => $s['w']->scenario->role(SystemRole::Creative)->getKey(), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($creative)->getJson("/api/campaign-plans/{$id}")->assertOk();
    $this->actingAs($creative)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s))->assertForbidden();

    Auth::forgetGuards();
    $viewer = $s['w']->scenario->addMember(SystemRole::ClientViewer);
    $this->actingAs($viewer)->getJson('/api/campaign-plans')->assertForbidden();
});

it('offers only what this project can use, and is part of the campaign launch feature', function (): void {
    $s = CampaignPlanScene::create();
    $owner = $s['w']->scenario->owner;

    $options = $this->actingAs($owner)->getJson('/api/campaign-plans/options?project_id='.$s['project']->getKey())->assertOk()->json('data');
    expect(array_column($options['accounts'], 'id'))->toEqualCanonicalizing([$s['meta'], $s['google']])
        ->and(array_column($options['tracking_links'], 'id'))->toBe([$s['link']])
        ->and(array_column($options['conversions'], 'id'))->toBe([$s['conversion']])
        ->and(array_column($options['creatives'], 'id'))->toBe([$s['creative']]);

    $this->artisan('saas:plan', ['workspace' => $s['w']->workspaceId(), 'plan' => 'growth', '--operator' => 'ops', '--reason' => 'test'])->assertSuccessful();
    $this->actingAs($owner)->getJson('/api/campaign-plans')->assertForbidden()->assertJsonPath('feature', 'campaign_launch');
});
