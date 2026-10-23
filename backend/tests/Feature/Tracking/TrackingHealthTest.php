<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Tracking health, the test console and the data layer
|--------------------------------------------------------------------------
|
| What is asserted: health follows the traffic that really arrived, no pixel and no provider is ever reported as working
| without evidence, a test visit is watched in the console and counts nowhere, and a data layer name can neither collide with
| Tag Manager's own nor reach a page it should not.
|
*/

const HEALTH_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{scenario: WorkspaceScenario, project: Project, link: array<string, mixed>, form: string} */
function healthWorld(object $test, ?WorkspaceScenario $scenario = null): array
{
    $scenario ??= WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();
    $link = $test->actingAs($scenario->owner)->postJson('/api/tracking/links', [
        'project_id' => $project->getKey(), 'name' => 'Snap', 'destination_url' => 'https://shop.example.com/offer', 'platform' => 'snapchat', 'campaign' => 'Spring',
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

/**
 * @param  array<string, mixed>  $query
 * @return array<string, array<string, mixed>>
 */
function healthChecks(object $test, WorkspaceScenario $s, Project $p, array $query = []): array
{
    $data = $test->actingAs($s->owner)->getJson('/api/tracking/health?'.http_build_query(['project_id' => $p->getKey(), ...$query]))->assertOk()->json('data');
    $out = [];

    foreach ($data['checks'] as $c) {
        $out[$c['key']] = $c;
    }

    return $out;
}

it('reports a project that nobody has followed a link in as not configured, then partial, then live as evidence arrives', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link] = healthWorld($this);
    $checks = healthChecks($this, $s, $p);

    expect($checks['first_party']['state'])->toBe('NOT_CONFIGURED');

    $location = (string) $this->withHeaders(['User-Agent' => HEALTH_BROWSER])->get('/api/r/'.basename((string) $link['short_url']).'?gclid=Cj0KCQ')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    $partial = healthChecks($this, $s, $p);
    expect($partial['first_party']['state'])->toBe('PARTIAL')
        ->and($partial['click_ids']['state'])->toBe('LIVE');

    $this->call('POST', '/api/public/track', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['touchpoint' => $q['ds_tp'], 'event' => 'landing_view', 'event_id' => 'land-health-001'], JSON_THROW_ON_ERROR))->assertAccepted();

    expect(healthChecks($this, $s, $p)['first_party']['state'])->toBe('LIVE');
});

it('never shows a provider pixel or an unconnected provider as working', function (): void {
    ['scenario' => $s, 'project' => $p] = healthWorld($this);
    $c = healthChecks($this, $s, $p);

    foreach (['meta', 'tiktok', 'snapchat', 'linkedin'] as $provider) {
        expect($c[$provider.'_browser']['state'])->toBe('NOT_CONFIGURED')->and($c[$provider.'_server']['state'])->toBe('AWAITING_CREDENTIALS');
    }

    expect($c['x_server']['state'])->toBe('UNSUPPORTED')
        ->and($c['deduplication']['state'])->toBe('NOT_CONFIGURED')
        ->and($c['ga4']['state'])->toBe('NOT_CONFIGURED')
        ->and($c['gtm']['state'])->toBe('NOT_CONFIGURED');
});

it('narrows health to one link and refuses a link from another project or workspace', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link] = healthWorld($this);
    $foreign = healthWorld($this);
    $other = WorkspaceScenario::create('Another agency');

    expect(healthChecks($this, $s, $p, ['link_id' => $link['id']]))->toHaveKey('first_party');
    $this->actingAs($s->owner)->getJson('/api/tracking/health?project_id='.$p->getKey().'&link_id='.$foreign['link']['id'])->assertNotFound();
    $this->actingAs($other->owner)->getJson('/api/tracking/health?project_id='.$p->getKey())->assertNotFound();
});

it('lets only people who manage tracking use the test console', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link] = healthWorld($this);
    $viewer = $s->addMember(SystemRole::ClientViewer);

    $this->actingAs($viewer)->postJson('/api/tracking/links/'.$link['id'].'/test')->assertForbidden();
    $this->actingAs($viewer)->getJson('/api/tracking/debug/events?project_id='.$p->getKey())->assertForbidden();
});

it('watches a test visit in the console and counts it nowhere', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = healthWorld($this);

    $test = $this->actingAs($s->owner)->postJson('/api/tracking/links/'.$link['id'].'/test')->assertCreated()->json('data');
    parse_str((string) parse_url((string) $test['url'], PHP_URL_QUERY), $q);
    expect($q['ds_tp'])->toBe($test['touchpoint'])->and($test['url'])->toStartWith('https://shop.example.com/offer?');

    $this->call('POST', '/api/public/track', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['touchpoint' => $test['touchpoint'], 'event' => 'landing_view', 'event_id' => 'land-test-0001'], JSON_THROW_ON_ERROR))->assertAccepted();
    $this->postJson('/api/public/forms/'.$form.'/submit', ['event_id' => 'sub-test-0001', 'touchpoint' => $test['touchpoint'], 'values' => ['name' => 'Tester', 'phone' => '0555009001']])->assertCreated();

    $events = $this->actingAs($s->owner)->getJson('/api/tracking/debug/events?project_id='.$p->getKey().'&touchpoint='.$test['touchpoint'])->assertOk()->json('data');
    $names = array_column($events, 'event');

    expect($names)->toContain('link_click', 'session_start', 'landing_view', 'form_submit')
        ->and(array_unique(array_column($events, 'status')))->toBe(['test'])
        ->and($events[0])->toHaveKeys(['at', 'event', 'source', 'status', 'event_id', 'destinations'])
        ->and($events[0]['destinations']['state'])->toBe('NOT_CONFIGURED')
        ->and(DB::table('leads')->count())->toBe(0)
        ->and(DB::table('form_submissions')->where('traffic_class', 'test')->count())->toBe(1);

    $counts = $this->actingAs($s->owner)->getJson('/api/tracking/links/'.$link['id'])->json('data.counts');
    expect($counts['clicks']['value'])->toBe(0)->and($counts['excluded'])->toBeGreaterThan(0);

    $funnel = $this->actingAs($s->owner)->getJson('/api/tracking/analytics/funnel?project_id='.$p->getKey())->json('data');
    expect($funnel['stages'][0]['count'])->toBe(0);
});

it('saves data layer names per project, gives the public form the mapping and refuses a colliding name', function (): void {
    ['scenario' => $s, 'project' => $p, 'form' => $form] = healthWorld($this);

    $this->actingAs($s->owner)->putJson('/api/tracking/settings/data-layer', ['project_id' => $p->getKey(), 'names' => ['form_submit' => 'client_lead']])
        ->assertOk()->assertJsonPath('data.data_layer.form_submit', 'client_lead')->assertJsonPath('data.data_layer.landing_view', 'ds_landing_view');
    $this->actingAs($s->owner)->putJson('/api/tracking/settings/data-layer', ['project_id' => $p->getKey(), 'names' => ['form_submit' => 'gtm.click']])->assertUnprocessable();
    $this->actingAs($s->owner)->putJson('/api/tracking/settings/data-layer', ['project_id' => $p->getKey(), 'names' => ['lead_created' => 'x_lead']])->assertUnprocessable();

    $public = $this->getJson('/api/public/forms/'.$form)->assertOk()->json('data');
    expect($public['data_layer']['form_submit'])->toBe('client_lead');

    $snippet = $this->actingAs($s->owner)->getJson('/api/tracking/snippet?project_id='.$p->getKey())->assertOk()->json('data');
    expect($snippet['snippet'])->toContain('client_lead')->and($snippet['snippet'])->toContain('/public/track')->and($snippet['defaults']['form_submit'])->toBe('ds_form_submit');

    $other = WorkspaceScenario::create('Another agency');
    $this->actingAs($other->owner)->putJson('/api/tracking/settings/data-layer', ['project_id' => $p->getKey(), 'names' => ['form_submit' => 'x_y']])->assertNotFound();
    $this->actingAs($other->owner)->getJson('/api/tracking/snippet?project_id='.$p->getKey())->assertNotFound();
});
