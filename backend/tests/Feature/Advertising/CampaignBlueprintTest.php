<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/**
 * One campaign described once, translated into each platform's terms with what each cannot take, and turned into
 * paused drafts with launch requests — never a campaign on a platform.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function blueprint(array $overrides = []): array
{
    return [
        'name' => 'Ramadan Sale',
        'goal' => 'leads',
        'daily_budget_minor' => 100_001,
        'currency' => 'SAR',
        'starts_on' => '2026-11-01',
        'countries' => ['SA', 'AE'],
        'age_min' => 25,
        'age_max' => 44,
        'gender' => 'all',
        'languages' => ['ar', 'en'],
        'interests' => 'Home décor, interior design',
        'headline' => 'Up to 40% off this Ramadan',
        'primary_text' => 'Shop the collection before Eid.',
        'call_to_action' => 'Shop now',
        'landing_url' => 'https://example.test/ramadan',
        'platforms' => ['meta', 'google_ads', 'linkedin', 'openai'],
        ...$overrides,
    ];
}

it('says, per platform and per field, what it can and cannot take — before anything is made', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $preview = test()->actingAs($w->scenario->owner)->postJson('/api/campaign-blueprints/preview', blueprint())->assertOk()->json('data');
    $by = array_column($preview, null, 'provider');
    $field = static fn (string $p, string $f): array => array_column($by[$p]['fields'], null, 'field')[$f];

    expect($by['meta']['objective'])->toBe('OUTCOME_LEADS')
        ->and($field('meta', 'goal')['support'])->toBe('supported')
        ->and($field('google_ads', 'goal')['support'])->toBe('partial')
        ->and($field('linkedin', 'age')['support'])->toBe('partial')
        ->and($field('meta', 'interests')['support'])->toBe('partial')
        ->and($by['openai']['creates'])->toBe('not_supported')
        // The budget is split in minor units with nothing lost.
        ->and(array_sum(array_column($preview, 'daily_budget_minor')))->toBe(100_001)
        // And no platform claims it can be created from here.
        ->and(array_unique(array_column(array_filter($preview, static fn (array $t): bool => $t['provider'] !== 'openai'), 'creates')))->toBe(['unverified'])
        ->and(DB::table('campaign_drafts')->count())->toBe(0);
});

it('makes paused drafts with a launch request each, skips a platform that cannot take the goal, and calls no platform', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $project = $w->project(Provider::Meta);
    $campaigns = DB::table('campaigns')->count();

    $made = test()->actingAs($w->scenario->owner)
        ->postJson('/api/campaign-blueprints/drafts', [...blueprint(['goal' => 'app_promotion', 'platforms' => ['meta', 'linkedin']]), 'project_id' => $project->getKey()])
        ->assertCreated()->json('data');

    expect(array_column($made['drafts'], 'provider'))->toBe(['meta'])
        ->and($made['skipped'])->toBe(['linkedin'])
        ->and(DB::table('campaign_drafts')->pluck('status')->all())->toBe(['paused'])
        ->and(DB::table('campaigns')->count())->toBe($campaigns);

    $approval = DB::table('approval_requests')->where('id', $made['drafts'][0]['approval_id'])->sole();
    expect($approval->kind)->toBe('campaign_launch')
        ->and($approval->state)->toBe('pending')
        ->and(json_decode((string) $approval->proposal, true)['status_on_creation'])->toBe('PAUSED');
});

it('refuses an impossible blueprint, and drafts for someone who cannot ask for approval', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    test()->actingAs($w->scenario->owner)->postJson('/api/campaign-blueprints/preview', blueprint(['age_min' => 50, 'age_max' => 20]))->assertStatus(422);
    test()->actingAs($w->scenario->owner)->postJson('/api/campaign-blueprints/preview', blueprint(['goal' => 'store_traffic']))->assertStatus(422);
    test()->actingAs($w->scenario->owner)->postJson('/api/campaign-blueprints/preview', blueprint(['landing_url' => 'http://insecure.test']))->assertStatus(422);

    $viewer = $w->scenario->addMember(SystemRole::ClientViewer);
    test()->actingAs($viewer)->postJson('/api/campaign-blueprints/drafts', [...blueprint(), 'project_id' => $w->project(Provider::Meta)->getKey()])->assertForbidden();
    expect(DB::table('campaign_drafts')->count())->toBe(0);
});
