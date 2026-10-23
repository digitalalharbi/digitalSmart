<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SyncedWorkspace;

const OPS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{w: SyncedWorkspace, project: string, campaign: string, form: string, client: string} */
function opsWorld(): array
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $project = (string) $w->project(Provider::Meta)->getKey();
    $ad = DB::table('ads')->where('workspace_id', $w->workspaceId())->first();
    assert($ad instanceof stdClass);

    $form = test()->actingAs($w->scenario->owner)->postJson('/api/tracking/forms', [
        'project_id' => $project, 'name' => 'Ops form', 'creates_lead' => true, 'fields' => [
            ['key' => 'name', 'type' => 'text', 'label_en' => 'Name', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'phone', 'type' => 'phone', 'label_en' => 'Phone', 'required' => true, 'maps_to' => 'phone'],
        ],
    ])->assertCreated()->json('data');
    test()->actingAs($w->scenario->owner)->postJson('/api/tracking/forms/'.$form['id'].'/status', ['status' => 'published'])->assertOk();

    return ['w' => $w, 'project' => $project, 'campaign' => (string) $ad->campaign_id, 'form' => (string) $form['public_url_token'], 'client' => (string) $w->project(Provider::Meta)->getAttribute('client_id')];
}

/**
 * @param  array{w: SyncedWorkspace, project: string, campaign: string, form: string, client: string}  $o
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function opsLinkFor(array $o, string $name, array $extra = []): array
{
    return test()->actingAs($o['w']->scenario->owner)->postJson('/api/tracking/links', [
        'project_id' => $o['project'], 'name' => $name, 'destination_url' => 'https://shop.example.com/ops', ...$extra,
    ])->assertCreated()->json('data');
}

/**
 * @param  array{w: SyncedWorkspace, project: string, campaign: string, form: string, client: string}  $o
 * @param  array<string, mixed>  $link
 */
function opsLead(array $o, array $link, int $n): void
{
    $location = (string) test()->withHeaders(['User-Agent' => OPS_UA])->get('/api/r/'.basename((string) $link['short_url']))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
    test()->postJson('/api/public/forms/'.$o['form'].'/submit', [
        'event_id' => 'ops-submit-event-'.$n, 'touchpoint' => $q['ds_tp'], 'consent' => 'granted', 'values' => ['name' => 'Ops Person '.$n, 'phone' => '05550050'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)],
    ])->assertCreated();
}

/** @param  array<string, mixed>  $link */
function opsClicks(array $link, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->withHeaders(['User-Agent' => OPS_UA])->get('/api/r/'.basename((string) $link['short_url']));
    }
}

/**
 * @param  array{w: SyncedWorkspace, project: string, campaign: string, form: string, client: string}  $o
 * @return array<string, mixed>
 */
function opsRead(array $o, string $query = ''): array
{
    return test()->actingAs($o['w']->scenario->owner)->getJson('/api/tracking/analytics/operations?project_id='.$o['project'].$query)->assertOk()->json('data');
}

it('prices leads only over the links that have spend, and counts every lead on its own', function (): void {
    $o = opsWorld();
    $bound = opsLinkFor($o, 'Bound', ['campaign_id' => $o['campaign']]);
    $loose = opsLinkFor($o, 'Loose');
    opsLead($o, $bound, 1);
    opsLead($o, $bound, 2);
    foreach ([3, 4, 5] as $n) {
        opsLead($o, $loose, $n);
    }

    $r = opsRead($o);
    $spend = (int) DB::table('daily_metrics')->where('entity_type', 'campaign')->where('entity_id', $o['campaign'])->sum('spend_minor');
    $money = $r['kpis']['by_currency'][0];

    expect($r['kpis']['tracked']['leads'])->toBe(5)
        ->and($money['leads'])->toBe(2)->and($money['priced_links'])->toBe(1)
        ->and($money['spend_minor'])->toBe($spend)
        ->and($money['cpl_minor'])->toBe(intdiv($spend + 1, 2))
        ->and($r['kpis']['unpriced_links'])->toBe(1);

    $rows = array_column($r['links'], null, 'clicks');
    $unbound = array_values(array_filter($r['links'], static fn (array $l): bool => $l['link']['name'] === 'Loose'))[0];
    expect($unbound['spend']['basis'])->toBe('UNAVAILABLE')->and($unbound['cpl']['minor'])->toBeNull()->and($unbound['cpl']['reason'])->not->toBeNull();
});

it('keeps a response-time summary and a team table to business events, with no one’s detail in them', function (): void {
    $o = opsWorld();
    test()->actingAs($o['w']->scenario->owner)->putJson('/api/tracking/follow-up/settings', [
        'project_id' => $o['project'], 'enabled' => true, 'assignment_mode' => 'specific', 'owner_id' => (string) $o['w']->scenario->owner->getKey(),
        'create_task' => false, 'notify_owner' => false, 'sla_minutes' => 30, 'task_priority' => 'high',
    ])->assertOk();
    $link = opsLinkFor($o, 'Team link');
    opsLead($o, $link, 1);
    opsLead($o, $link, 2);
    $ids = DB::table('leads')->orderBy('id')->pluck('id')->all();
    DB::table('lead_events')->insert(['id' => (string) Str::uuid7(), 'workspace_id' => $o['w']->workspaceId(), 'lead_id' => $ids[0], 'type' => 'stage_changed', 'from_stage' => 'new', 'to_stage' => 'contacted', 'occurred_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('leads')->where('id', $ids[1])->update(['received_at' => now()->subMinutes(120)]);

    $r = opsRead($o);
    $team = $r['team'][0];

    expect($team['assigned'])->toBe(2)->and($team['overdue'])->toBe(1)
        ->and($r['kpis']['response']['sla_compliance'])->toBe(0.5)
        ->and(array_keys($team))->toEqualCanonicalizing(['owner', 'assigned', 'contacted', 'qualified', 'median_attempt_seconds', 'median_contact_seconds', 'sla_compliance', 'overdue'])
        ->and(json_encode($r, JSON_THROW_ON_ERROR))->not->toContain('0555005001')->and(json_encode($r, JSON_THROW_ON_ERROR))->not->toContain('Ops Person');
});

it('narrows every section to the links a filter leaves', function (): void {
    $o = opsWorld();
    $a = opsLinkFor($o, 'Alpha', ['campaign_id' => $o['campaign']]);
    $b = opsLinkFor($o, 'Beta');
    opsClicks($a, 2);
    opsClicks($b, 5);

    $all = opsRead($o);
    $only = opsRead($o, '&link_id='.$b['id']);
    $meta = opsRead($o, '&platform=meta');

    expect(array_sum(array_column($all['links'], 'clicks')))->toBe(7)
        ->and($only['links_considered'])->toBe(1)->and($only['kpis']['tracked']['clicks'])->toBe(5)->and($only['funnel']['scope'])->toBe('chosen_links')
        ->and($all['funnel']['scope'])->toBe('project')
        ->and(array_column($meta['links'], 'link'))->toHaveCount(1);
});

it('raises an observation only when the figures are enough to say it, and attaches the figures', function (): void {
    $o = opsWorld();
    $quiet = opsLinkFor($o, 'Quiet', ['campaign_id' => $o['campaign']]);
    opsClicks($quiet, 5);
    expect(array_column(opsRead($o)['attention'], 'code'))->not->toContain('spend_no_leads');

    opsClicks($quiet, 30);
    $items = opsRead($o)['attention'];
    $item = array_values(array_filter($items, static fn (array $i): bool => $i['code'] === 'spend_no_leads'))[0] ?? null;

    expect($item)->not->toBeNull()->and($item['evidence']['leads'])->toBe(0)->and($item['evidence']['clicks'])->toBeGreaterThanOrEqual(30);
});

it('shows the five readings of one pipeline side by side, with how much of it is covered', function (): void {
    $o = opsWorld();
    $link = opsLinkFor($o, 'Touch', ['campaign_id' => $o['campaign']]);
    opsLead($o, $link, 1);

    $a = opsRead($o)['attribution'];

    expect($a['digitalsmart_attributed']['leads'])->toBe(1)->and($a['last_known_touch']['leads'])->toBe(1)
        ->and((float) $a['coverage'])->toBe(1.0)->and($a['confidence'])->toBe('high')
        ->and(array_keys($a))->toContain('platform_reported', 'first_known_touch', 'outcome', 'missing_evidence');
});

it('keeps another workspace and a member off the project out of the view', function (): void {
    $o = opsWorld();
    $other = opsWorld();

    test()->actingAs($other['w']->scenario->owner)->getJson('/api/tracking/analytics/operations?project_id='.$o['project'])->assertNotFound();
});

it('gives a client-safe report the five tracking views without a name, number, token or id', function (): void {
    $o = opsWorld();
    $link = opsLinkFor($o, 'Public ROI', ['campaign_id' => $o['campaign']]);
    opsLead($o, $link, 1);
    DB::table('leads')->update(['received_at' => now()->subDays(3)]);
    DB::statement("UPDATE tracking_sessions SET started_at = started_at - interval '3 days'");
    DB::statement("UPDATE form_submissions SET submitted_at = submitted_at - interval '3 days'");

    $views = ['roi', 'campaign_funnel', 'lead_quality', 'sales_funnel', 'response'];
    $report = test()->actingAs($o['w']->scenario->owner)->postJson('/api/reports', [
        'name' => 'Tracking ROI report', 'client_id' => $o['client'], 'window' => 'last_30_days', 'audience' => 'client_safe',
        'sections' => array_map(static fn (string $v): array => ['kind' => 'tracking', 'tracking_view' => $v], $views),
    ])->assertCreated()->json('data.id');
    $token = (string) test()->actingAs($o['w']->scenario->owner)->postJson('/api/reports/'.$report.'/shares', ['days' => 7])->assertCreated()->json('meta.token');
    $shared = (string) test()->getJson('/api/shared-reports/'.$token)->assertOk()->getContent();
    $sections = test()->actingAs($o['w']->scenario->owner)->getJson('/api/reports/'.$report)->assertOk()->json('data.sections');
    $by = array_column($sections, null, 'title');

    expect($by['Tracking ROI']['tracking']['rows'][0]['link'])->toBe('Public ROI')
        ->and($by['Tracking ROI']['tracking']['rows'][0]['spend'])->not->toBeNull()
        ->and($by['Lead quality']['tracking']['rows'][0])->toBe(['figure' => 'Leads', 'value' => 1])
        ->and($shared)->not->toContain('Ops Person')->and($shared)->not->toContain('0555005001')
        ->and($shared)->not->toContain(basename((string) $link['short_url']))->and($shared)->not->toContain($o['project'])
        ->and($shared)->not->toContain((string) $link['id']);
});
