<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Tracking links, the redirect and the first-party event store
|--------------------------------------------------------------------------
|
| What is asserted: a link belongs to a project and is invisible outside it; a redirect goes only where its owner said,
| records the click once with the documented click identifiers, and answers a dead link with one bare 404; a page can
| report behaviour but never an outcome; a repeat is one event; automated traffic is marked and left out of counts; and a
| count the product cannot know is null with a reason, not zero.
|
*/

const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{0: WorkspaceScenario, 1: Project} */
function trackedProject(?WorkspaceScenario $scenario = null): array
{
    $scenario ??= WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();

    return [$scenario, $project];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function linkBody(Project $project, array $overrides = []): array
{
    return [
        'project_id' => $project->getKey(),
        'name' => 'Snap spring offer',
        'destination_url' => 'https://shop.example.com/offer',
        'platform' => 'snapchat',
        'campaign' => 'Spring Offer',
        'creative' => 'Video 1',
        'template_patterns' => ['utm_source' => '{{platform}}', 'utm_medium' => 'paid_social', 'utm_campaign' => '{{campaign}}', 'utm_content' => '{{creative}}'],
        ...$overrides,
    ];
}

/** @param array<string, mixed> $link */
function token(array $link): string
{
    return basename((string) $link['short_url']);
}

it('creates a link in a project, fills its parameters from the template and shows an opaque short address', function (): void {
    [$scenario, $project] = trackedProject();

    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', linkBody($project))->assertCreated()->json('data');

    expect($link['utm'])->toMatchArray(['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring_offer', 'utm_content' => 'video_1'])
        ->and($link['short_url'])->toMatch('#/r/[A-Za-z0-9]{10}$#')
        ->and($link['short_url'])->not->toContain((string) $link['id'])
        ->and($link['project']['id'])->toBe($project->getKey())
        ->and($link['counts'])->toBeNull();
});

it('refuses a destination that could make the link a redirect service for somebody else', function (string $url): void {
    [$scenario, $project] = trackedProject();

    $this->actingAs($scenario->owner)->postJson('/api/tracking/links', linkBody($project, ['destination_url' => $url]))
        ->assertUnprocessable();

    expect(DB::table('tracking_links')->count())->toBe(0);
})->with(['javascript:alert(1)', 'http://127.0.0.1/admin', 'https://u:p@example.com/', 'http://intranet/']);

it('keeps links inside their project and workspace', function (): void {
    [$agency, $project] = trackedProject();
    $other = WorkspaceScenario::create('Another agency');
    [, $foreign] = trackedProject($other);

    $this->actingAs($other->owner)->postJson('/api/tracking/links', linkBody($project))->assertNotFound();
    $created = $this->actingAs($agency->owner)->postJson('/api/tracking/links', linkBody($project))->assertCreated()->json('data');

    $this->actingAs($other->owner)->getJson('/api/tracking/links/'.$created['id'])->assertNotFound();
    expect($this->actingAs($other->owner)->getJson('/api/tracking/links')->json('data'))->toBe([]);
    $this->actingAs($other->owner)->postJson('/api/tracking/links/'.$created['id'].'/status', ['status' => 'paused'])->assertNotFound();
    expect($foreign->getKey())->not->toBe($project->getKey());
});

it('lets only people who manage tracking build a link', function (): void {
    [$scenario, $project] = trackedProject();
    $viewer = $scenario->addMember(SystemRole::ClientViewer);

    $this->actingAs($viewer)->postJson('/api/tracking/links', linkBody($project))->assertForbidden();
});

it('records a click, hands the page a touchpoint, and forwards the documented click identifiers', function (): void {
    [$scenario, $project] = trackedProject();
    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', linkBody($project))->assertCreated()->json('data');

    $response = $this->withHeaders(['User-Agent' => BROWSER, 'Referer' => 'https://snapchat.com/ads'])
        ->get('/api/r/'.token($link).'?gclid=Cj0KCQ&madeup=1&utm_source=ignored')
        ->assertRedirect();

    $target = (string) $response->headers->get('Location');
    parse_str((string) parse_url($target, PHP_URL_QUERY), $q);

    expect($target)->toStartWith('https://shop.example.com/offer?')
        ->and($q)->toMatchArray(['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'gclid' => 'Cj0KCQ'])
        ->and($q['ds_tp'])->toMatch('/^[A-Za-z0-9]{20}$/')
        ->and($q)->not->toHaveKey('madeup');

    $events = DB::table('tracking_events')->orderBy('name')->get()->all();
    expect(array_map(static fn (object $e): mixed => $e->name, $events))->toBe(['link_click', 'session_start'])
        ->and(json_decode((string) $events[0]->click_ids, true))->toBe(['gclid' => 'Cj0KCQ'])
        ->and($events[0]->referrer_host)->toBe('snapchat.com')
        ->and($events[0]->traffic_class)->toBe('valid')
        ->and($response->headers->getCookies()[0]->getName())->toBe('ds_v');
});

it('answers a missing, paused or expired link the same way', function (): void {
    [$scenario, $project] = trackedProject();
    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', linkBody($project))->assertCreated()->json('data');

    $this->get('/api/r/Zzzzzzzzzz')->assertNotFound();
    $this->actingAs($scenario->owner)->postJson('/api/tracking/links/'.$link['id'].'/status', ['status' => 'paused'])->assertOk();
    $paused = $this->get('/api/r/'.token($link))->assertNotFound();
    $this->actingAs($scenario->owner)->postJson('/api/tracking/links/'.$link['id'].'/status', ['status' => 'active'])->assertOk();
    DB::table('tracking_links')->update(['expires_at' => now()->subMinute()]);
    $expired = $this->get('/api/r/'.token($link))->assertNotFound();

    expect($paused->getContent())->toBe($expired->getContent())->and(DB::table('tracking_events')->count())->toBe(0);
});

it('never lets the request choose where a link goes', function (): void {
    [$scenario, $project] = trackedProject();
    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', linkBody($project))->assertCreated()->json('data');

    $location = (string) $this->get('/api/r/'.token($link).'?url=https://evil.example&redirect=https://evil.example&next=//evil.example')
        ->headers->get('Location');

    expect(parse_url($location, PHP_URL_HOST))->toBe('shop.example.com')->and($location)->not->toContain('evil');
});

/** @param array<string, mixed> $link */
function followed(object $test, array $link, string $agent = BROWSER): string
{
    $location = (string) $test->withHeaders(['User-Agent' => $agent])->get('/api/r/'.token($link))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    return is_string($q['ds_tp'] ?? null) ? $q['ds_tp'] : '';
}

/** @param array<string, mixed> $body */
function beacon(object $test, array $body): mixed
{
    return $test->call('POST', '/api/public/track', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body, JSON_THROW_ON_ERROR));
}

it('keeps a behaviour reported by the destination page once, and never an outcome', function (): void {
    [$scenario, $project] = trackedProject();
    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', linkBody($project))->assertCreated()->json('data');
    $tp = followed($this, $link);

    beacon($this, ['touchpoint' => $tp, 'event' => 'landing_view', 'event_id' => 'evt-landing-1', 'path' => '/offer?token=secret#x'])->assertAccepted();
    beacon($this, ['touchpoint' => $tp, 'event' => 'landing_view', 'event_id' => 'evt-landing-1'])->assertAccepted();
    beacon($this, ['touchpoint' => $tp, 'event' => 'form_start', 'event_id' => 'evt-start-1', 'meta' => ['field_key' => 'phone', 'value' => '0555001122']])->assertAccepted();
    beacon($this, ['touchpoint' => $tp, 'event' => 'lead_created', 'event_id' => 'evt-lead-1'])->assertAccepted();
    beacon($this, ['touchpoint' => $tp, 'event' => 'sale_completed', 'event_id' => 'evt-sale-01'])->assertAccepted();

    expect(DB::table('tracking_events')->where('source', 'first_party')->pluck('name')->sort()->values()->all())->toBe(['form_start', 'landing_view']);

    $start = DB::table('tracking_events')->where('name', 'form_start')->firstOrFail();
    $view = DB::table('tracking_events')->where('name', 'landing_view')->firstOrFail();
    expect(json_decode((string) $start->metadata, true))->toBe(['field_key' => 'phone'])
        ->and($view->landing_path)->toBe('/offer')
        ->and($view->tracking_link_id)->toBe($link['id']);
});

it('answers a beacon identically whatever it was, so it cannot be used to probe', function (): void {
    $a = beacon($this, ['touchpoint' => 'AAAAAAAAAAAAAAAAAAAA', 'event' => 'landing_view', 'event_id' => 'evt-landing-1'])->assertAccepted()->getContent();
    $b = $this->call('POST', '/api/public/track', [], [], [], [], 'not json')->assertAccepted()->getContent();
    $c = $this->call('POST', '/api/public/track', [], [], [], [], str_repeat('x', 9000))->assertAccepted()->getContent();

    expect($a)->toBe($b)->and($b)->toBe($c)->and(DB::table('tracking_events')->count())->toBe(0);
});

it('marks automated traffic and leaves it out of the counts without deleting it', function (): void {
    [$scenario, $project] = trackedProject();
    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', linkBody($project))->assertCreated()->json('data');

    followed($this, $link);
    followed($this, $link, 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)');

    $counts = $this->actingAs($scenario->owner)->getJson('/api/tracking/links/'.$link['id'])->json('data.counts');

    expect($counts['clicks']['value'])->toBe(1)
        ->and($counts['excluded'])->toBe(1)
        ->and(DB::table('tracking_events')->where('traffic_class', 'suspected_bot')->count())->toBe(2);
});

it('reports a landing view it cannot know as unknown, not as zero', function (): void {
    [$scenario, $project] = trackedProject();
    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', linkBody($project))->assertCreated()->json('data');

    $none = $this->actingAs($scenario->owner)->getJson('/api/tracking/links/'.$link['id'])->json('data.counts');
    expect($none['clicks']['value'])->toBe(0)->and($none['landing_views']['value'])->toBe(0)->and($none['click_id_coverage'])->toBeNull();

    $tp = followed($this, $link);
    $silent = $this->actingAs($scenario->owner)->getJson('/api/tracking/links/'.$link['id'])->json('data.counts');
    expect($silent['clicks']['value'])->toBe(1)
        ->and($silent['landing_views']['value'])->toBeNull()
        ->and($silent['landing_views']['state'])->toBe('partial')
        ->and($silent['landing_views']['reason'])->not->toBeEmpty();

    beacon($this, ['touchpoint' => $tp, 'event' => 'landing_view', 'event_id' => 'evt-landing-9'])->assertAccepted();
    $seen = $this->actingAs($scenario->owner)->getJson('/api/tracking/links/'.$link['id'])->json('data.counts');
    expect($seen['landing_views']['value'])->toBe(1)->and($seen['landing_views']['state'])->toBe('live');
});

it('saves and lists link templates, accepting only utm parameters', function (): void {
    [$scenario] = trackedProject();

    $this->actingAs($scenario->owner)->postJson('/api/tracking/link-templates', ['name' => 'Paid social', 'patterns' => ['utm_source' => '{{platform}}']])->assertCreated();
    $this->actingAs($scenario->owner)->postJson('/api/tracking/link-templates', ['name' => 'Bad', 'patterns' => ['secret' => 'x']])->assertUnprocessable();

    expect($this->actingAs($scenario->owner)->getJson('/api/tracking/link-templates')->json('data.0.name'))->toBe('Paid social');
});

it('refuses to let a browser say that a WhatsApp conversation started, or that a lead was made', function (): void {
    foreach (['whatsapp_conversation_started', 'lead_created', 'sale_completed', 'form_submit'] as $name) {
        $this->call('POST', '/api/public/track', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['event' => $name, 'event_id' => 'x-'.$name, 'session' => 'sss'], JSON_THROW_ON_ERROR))->assertStatus(202);
    }

    expect(DB::table('tracking_events')->whereIn('name', ['whatsapp_conversation_started', 'lead_created', 'sale_completed', 'form_submit'])->count())->toBe(0);
});
