<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Funnels, breakdowns, follow-up and a lead's tracked path
|--------------------------------------------------------------------------
|
| Built from real traffic: a link is followed, a page reports a view, a form is submitted, a lead is worked. What is
| asserted is that each count is the one the traffic produced, that automated visits are left out and counted apart, that a
| step nobody reports is unknown and not zero, that nothing crosses a project or a workspace, and that a lead shows the
| path that was recorded without claiming it was the whole path.
|
*/

const FUNNEL_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{scenario: WorkspaceScenario, project: Project, link: array<string, mixed>, form: string} */
function funnelWorld(object $test, ?WorkspaceScenario $scenario = null): array
{
    $scenario ??= WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();
    $link = $test->actingAs($scenario->owner)->postJson('/api/tracking/links', [
        'project_id' => $project->getKey(), 'name' => 'Snap', 'destination_url' => 'https://shop.example.com/offer', 'platform' => 'snapchat',
        'campaign' => 'Spring', 'creative' => 'Video 1', 'template_patterns' => ['utm_source' => '{{platform}}', 'utm_campaign' => '{{campaign}}'],
    ])->assertCreated()->json('data');
    $form = $test->actingAs($scenario->owner)->postJson('/api/tracking/forms', [
        'project_id' => $project->getKey(), 'name' => 'Enquiry', 'creates_lead' => true, 'fields' => [
            ['key' => 'name', 'type' => 'text', 'label_en' => 'Name', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'phone', 'type' => 'phone', 'label_en' => 'Phone', 'required' => true, 'maps_to' => 'phone'],
        ],
    ])->assertCreated()->json('data');
    $test->actingAs($scenario->owner)->postJson('/api/tracking/forms/'.$form['id'].'/status', ['status' => 'published'])->assertOk();

    return ['scenario' => $scenario, 'project' => $project, 'link' => $link, 'form' => $form['public_url_token']];
}

/** @param array<string, mixed> $link */
function clickVia(object $test, array $link, string $agent = FUNNEL_BROWSER): string
{
    $location = (string) $test->withHeaders(['User-Agent' => $agent])->get('/api/r/'.basename((string) $link['short_url']))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    return is_string($q['ds_tp'] ?? null) ? $q['ds_tp'] : '';
}

function pageReports(object $test, string $touchpoint, string $event, string $id): void
{
    $test->call('POST', '/api/public/track', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['touchpoint' => $touchpoint, 'event' => $event, 'event_id' => $id], JSON_THROW_ON_ERROR))->assertAccepted();
}

function submitAs(object $test, string $form, string $touchpoint, string $phone, string $id): void
{
    $test->postJson('/api/public/forms/'.$form.'/submit', ['event_id' => $id, 'touchpoint' => $touchpoint, 'values' => ['name' => 'Noura '.$phone, 'phone' => $phone]])->assertCreated();
}

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function funnelOf(object $test, WorkspaceScenario $scenario, Project $project, array $query = []): array
{
    return $test->actingAs($scenario->owner)->getJson('/api/tracking/analytics/funnel?'.http_build_query(['project_id' => $project->getKey(), ...$query]))->assertOk()->json('data');
}

/**
 * @param  array<string, mixed>  $funnel
 * @return array<string, array<string, mixed>>
 */
function stagesBySource(array $funnel): array
{
    $out = [];

    foreach ($funnel['stages'] as $s) {
        $out[$s['source']] = $s;
    }

    return $out;
}

it('counts what the traffic produced, stage by stage, and leaves automated visits out', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = funnelWorld($this);

    $tps = [clickVia($this, $link), clickVia($this, $link), clickVia($this, $link), clickVia($this, $link)];
    clickVia($this, $link, 'facebookexternalhit/1.1');
    pageReports($this, $tps[0], 'landing_view', 'land-0000-0001');
    pageReports($this, $tps[1], 'landing_view', 'land-0000-0002');
    pageReports($this, $tps[2], 'landing_view', 'land-0000-0003');
    pageReports($this, $tps[0], 'form_start', 'start-000-0001');
    pageReports($this, $tps[1], 'form_start', 'start-000-0002');
    submitAs($this, $form, $tps[0], '0555001101', 'sub-0000-0001');
    submitAs($this, $form, $tps[1], '0555001102', 'sub-0000-0002');

    $leadId = (string) DB::table('leads')->where('phone', '0555001101')->value('id');
    $this->actingAs($s->owner)->postJson('/api/leads/'.$leadId.'/stage', ['stage' => 'contacted'])->assertOk();
    $this->actingAs($s->owner)->postJson('/api/leads/'.$leadId.'/stage', ['stage' => 'qualified'])->assertOk();
    $this->actingAs($s->owner)->postJson('/api/leads/'.$leadId.'/milestones', ['type' => 'appointment_booked'])->assertCreated();

    $f = funnelOf($this, $s, $p);
    $st = stagesBySource($f);

    expect($st['link_click']['count'])->toBe(4)
        ->and($st['landing_view']['count'])->toBe(3)
        ->and($st['form_start']['count'])->toBe(2)
        ->and($st['form_submit']['count'])->toBe(2)
        ->and($st['lead_created']['count'])->toBe(2)
        ->and($st['lead_contacted']['count'])->toBe(1)
        ->and($st['lead_qualified']['count'])->toBe(1)
        ->and($st['appointment_booked']['count'])->toBe(1)
        ->and($st['sale_completed']['count'])->toBe(0)
        ->and($st['landing_view']['conversion_from_previous'])->toBe(0.75)
        ->and($st['sale_completed']['conversion_from_previous'])->toEqual(0.0)
        ->and($f['overall_conversion'])->toEqual(0.0)
        ->and($f['excluded_sessions'])->toBe(1)
        ->and($f['is_standard'])->toBeTrue();
});

it('shows a step the page never reported as unknown, not zero', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link] = funnelWorld($this);
    clickVia($this, $link);

    $st = stagesBySource(funnelOf($this, $s, $p));

    expect($st['landing_view']['count'])->toBeNull()->and($st['landing_view']['state'])->toBe('partial')->and($st['landing_view']['reason'])->not->toBeEmpty();
});

it('narrows a funnel to one link and keeps projects and workspaces apart', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $a, 'form' => $form] = funnelWorld($this);
    $other = $this->actingAs($s->owner)->postJson('/api/tracking/links', [
        'project_id' => $p->getKey(), 'name' => 'TikTok', 'destination_url' => 'https://shop.example.com/b', 'platform' => 'tiktok',
    ])->assertCreated()->json('data');
    clickVia($this, $a);
    clickVia($this, $other);
    clickVia($this, $other);
    $foreign = funnelWorld($this);
    clickVia($this, $foreign['link']);

    expect(stagesBySource(funnelOf($this, $s, $p))['link_click']['count'])->toBe(3)
        ->and(stagesBySource(funnelOf($this, $s, $p, ['link_id' => $other['id']]))['link_click']['count'])->toBe(2);

    $this->actingAs($foreign['scenario']->owner)->getJson('/api/tracking/analytics/funnel?project_id='.$p->getKey())->assertNotFound();
    $this->actingAs($foreign['scenario']->owner)->getJson('/api/tracking/analytics/funnel?project_id='.$foreign['project']->getKey().'&link_id='.$a['id'])->assertNotFound();
    expect($form)->not->toBeEmpty();
});

it('stores a project’s own funnel in journey order and refuses a misleading one', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link] = funnelWorld($this);
    clickVia($this, $link);

    $saved = $this->actingAs($s->owner)->postJson('/api/tracking/funnels', [
        'project_id' => $p->getKey(), 'name' => 'Short', 'stages' => [['source' => 'link_click', 'label_ar' => 'نقرة'], ['source' => 'lead_created', 'required' => false]],
    ])->assertCreated()->json('data');

    $this->actingAs($s->owner)->postJson('/api/tracking/funnels', ['project_id' => $p->getKey(), 'name' => 'Backwards', 'stages' => [['source' => 'lead_created'], ['source' => 'link_click']]])->assertUnprocessable();
    $this->actingAs($s->owner)->postJson('/api/tracking/funnels', ['project_id' => $p->getKey(), 'name' => 'Unknown', 'stages' => [['source' => 'link_click'], ['source' => 'scroll']]])->assertUnprocessable();

    $f = funnelOf($this, $s, $p, ['funnel_id' => $saved['id']]);
    expect($f['name'])->toBe('Short')->and($f['stages'])->toHaveCount(2)->and($f['is_standard'])->toBeFalse();
    expect($this->actingAs($s->owner)->getJson('/api/tracking/funnels')->json('data'))->toHaveCount(1);
    $this->actingAs($s->owner)->deleteJson('/api/tracking/funnels/'.$saved['id'])->assertOk();
    expect($this->actingAs($s->owner)->getJson('/api/tracking/funnels')->json('data'))->toBe([]);
    $this->actingAs($s->addMember(SystemRole::ClientViewer))->postJson('/api/tracking/funnels', ['project_id' => $p->getKey(), 'name' => 'x1', 'stages' => [['source' => 'link_click'], ['source' => 'lead_created']]])->assertForbidden();
});

it('breaks traffic down by a dimension and keeps unreported steps null', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = funnelWorld($this);
    $tp = clickVia($this, $link);
    clickVia($this, $link);
    pageReports($this, $tp, 'landing_view', 'land-0000-0009');
    submitAs($this, $form, $tp, '0555001103', 'sub-0000-0009');

    $rows = $this->actingAs($s->owner)->getJson('/api/tracking/analytics/breakdown?project_id='.$p->getKey().'&dimension=platform')->assertOk()->json('data.rows');
    $campaign = $this->actingAs($s->owner)->getJson('/api/tracking/analytics/breakdown?project_id='.$p->getKey().'&dimension=device')->json('data.rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['key' => 'snapchat', 'sessions' => 2, 'landing_views' => 1, 'form_starts' => null, 'submissions' => 1, 'leads' => 1])
        ->and($campaign[0]['key'])->toBe('mobile');

    $this->actingAs($s->owner)->getJson('/api/tracking/analytics/breakdown?project_id='.$p->getKey().'&dimension=password')->assertUnprocessable();
});

it('shows a lead the path that was recorded and says what it could not know', function (): void {
    ['scenario' => $s, 'link' => $link, 'form' => $form] = funnelWorld($this);
    $tp = clickVia($this, $link);
    pageReports($this, $tp, 'landing_view', 'land-0000-0010');
    submitAs($this, $form, $tp, '0555001104', 'sub-0000-0010');
    $lead = (string) DB::table('leads')->where('phone', '0555001104')->value('id');
    $this->actingAs($s->owner)->postJson('/api/leads/'.$lead.'/stage', ['stage' => 'contacted'])->assertOk();

    $timeline = $this->actingAs($s->owner)->getJson('/api/leads/'.$lead.'/timeline')->assertOk()->json('data');
    $names = array_column($timeline['events'], 'name');

    expect($timeline['tracked'])->toBeTrue()
        ->and($names)->toContain('link_click', 'landing_view', 'form_submit', 'lead_created', 'lead_contact_attempt')
        ->and(array_search('link_click', $names, true))->toBeLessThan((int) array_search('lead_created', $names, true));

    $manual = $this->actingAs($s->owner)->postJson('/api/leads', [
        'client_id' => DB::table('leads')->where('id', $lead)->value('client_id'), 'source_kind' => 'manual', 'name' => 'Walk-in', 'phone' => '0555009999',
    ]);
    if ($manual->status() === 201) {
        $none = $this->actingAs($s->owner)->getJson('/api/leads/'.$manual->json('data.id').'/timeline')->json('data');
        expect($none['tracked'])->toBeFalse()->and($none['note'])->toContain('not recorded');
    }
});

it('keeps a lead’s path from anybody who cannot see the lead', function (): void {
    ['scenario' => $s, 'link' => $link, 'form' => $form] = funnelWorld($this);
    submitAs($this, $form, clickVia($this, $link), '0555001105', 'sub-0000-0011');
    $lead = (string) DB::table('leads')->where('phone', '0555001105')->value('id');
    $other = WorkspaceScenario::create('Another agency');

    $this->actingAs($other->owner)->getJson('/api/leads/'.$lead.'/timeline')->assertNotFound();
    $this->actingAs($s->owner)->getJson('/api/leads/'.$lead.'/timeline')->assertOk();
});

it('reports how the team followed up, from the first attempt to the first real contact', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = funnelWorld($this);
    submitAs($this, $form, clickVia($this, $link), '0555001106', 'sub-0000-0012');
    submitAs($this, $form, clickVia($this, $link), '0555001107', 'sub-0000-0013');
    $lead = (string) DB::table('leads')->where('phone', '0555001106')->value('id');
    $this->actingAs($s->owner)->postJson('/api/leads/'.$lead.'/stage', ['stage' => 'contacted'])->assertOk();
    $this->actingAs($s->owner)->postJson('/api/leads/'.$lead.'/stage', ['stage' => 'qualified'])->assertOk();

    $f = $this->actingAs($s->owner)->getJson('/api/tracking/analytics/follow-up?project_id='.$p->getKey().'&sla_minutes=60')->assertOk()->json('data');

    expect($f['leads'])->toBe(2)->and($f['contacted'])->toBe(1)->and($f['uncontacted'])->toBe(1)->and($f['contact_rate'])->toBe(0.5)
        ->and($f['sla_compliance'])->toBe(0.5)
        ->and($f['attempted'])->toBe(1)->and($f['median_seconds_to_first_attempt'])->toBeInt()->and($f['qualified'])->toBe(1);
});
