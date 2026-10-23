<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use App\Infrastructure\Integration\Adapter\AbstractProviderAdapter;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Conversion mappings, dispatch and retention
|--------------------------------------------------------------------------
|
| What is asserted: a mapping exists only where the provider's documentation allows one; a conversion that must not leave
| (a test, a bot, no consent, no identifier, no connection) is recorded as skipped with its reason and never sent; a
| sandbox connection simulates and says so; a repeat is one dispatch; retry keeps the event id; and retention rolls raw
| events into counts and strips identifiers.
|
*/

const DISPATCH_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{scenario: WorkspaceScenario, project: Project, link: array<string, mixed>, form: string} */
function dispatchWorld(object $test): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();
    $link = $test->actingAs($scenario->owner)->postJson('/api/tracking/links', [
        'project_id' => $project->getKey(), 'name' => 'Snap', 'destination_url' => 'https://shop.example.com/offer', 'platform' => 'snapchat',
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

function connectSandbox(object $test, WorkspaceScenario $s, Provider $provider, bool $sandbox = true): void
{
    $credentials = [];

    foreach ($provider->requiredCredentialKeys() as $key) {
        $credentials[$key] = $sandbox ? AbstractProviderAdapter::SANDBOX_TOKEN : 'a-real-looking-'.$key;
    }

    $test->actingAs($s->owner)->putJson('/api/integrations/providers/'.$provider->value, ['credentials' => $credentials])->assertSuccessful();

    if ($sandbox) {
        return;
    }

    /* A live connection cannot be verified here (that is the blocked part); the status is set directly to reach the sender's gate. */
    DB::table('provider_connections')->where('provider', $provider->value)->update(['status' => 'connected']);
}

function mapEvent(object $test, WorkspaceScenario $s, Project $p, string $event, string $provider, string $name): void
{
    $test->actingAs($s->owner)->postJson('/api/tracking/conversions/mappings', [
        'project_id' => $p->getKey(), 'event' => $event, 'provider' => $provider, 'provider_event' => $name,
    ])->assertCreated();
}

/**
 * @param  array<string, mixed>  $link
 * @return string touchpoint
 */
function dispatchVisit(object $test, array $link, string $query = '', string $agent = DISPATCH_BROWSER): string
{
    $location = (string) $test->withHeaders(['User-Agent' => $agent])->get('/api/r/'.basename((string) $link['short_url']).$query)->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    return is_string($q['ds_tp'] ?? null) ? $q['ds_tp'] : '';
}

function submitWith(object $test, string $form, string $touchpoint, string $phone, string $id, string $consent = 'granted'): void
{
    $test->postJson('/api/public/forms/'.$form.'/submit', [
        'event_id' => $id, 'touchpoint' => $touchpoint, 'consent' => $consent, 'values' => ['name' => 'Noura', 'phone' => $phone],
    ])->assertCreated();
}

it('offers each provider as its own documentation allows and nothing more', function (): void {
    ['scenario' => $s, 'project' => $p] = dispatchWorld($this);

    $data = $this->actingAs($s->owner)->getJson('/api/tracking/conversions?project_id='.$p->getKey())->assertOk()->json('data');
    $by = array_column($data['providers'], null, 'provider');

    expect($data['events'])->toContain('form_submit', 'sale_completed')
        ->and($by['snapchat']['support'])->toBe('DOCUMENTED')->and($by['snapchat']['events'])->toContain('SIGN_UP')
        ->and($by['snapchat']['events'])->not->toContain('LEAD')
        ->and($by['meta']['support'])->toBe('DOCUMENTED')->and($by['meta']['source'])->toStartWith('https://developers.facebook.com')
        ->and($by['google_ads']['caveat'])->toContain('Data Manager API')
        ->and($by['tiktok']['support'])->toBe('UNVERIFIED')->and($by['tiktok']['source'])->toBeNull()
        ->and($by['x']['support'])->toBe('UNSUPPORTED')
        ->and($data['policy'])->toBe(['consent_policy' => 'required', 'retention_days' => 400]);
});

it('refuses a mapping the provider’s documentation does not allow', function (): void {
    ['scenario' => $s, 'project' => $p] = dispatchWorld($this);
    $post = fn (array $over) => $this->actingAs($s->owner)->postJson('/api/tracking/conversions/mappings', ['project_id' => $p->getKey(), 'event' => 'lead_created', 'provider' => 'snapchat', 'provider_event' => 'SIGN_UP', ...$over]);

    $post(['provider' => 'tiktok', 'provider_event' => 'SubmitForm'])->assertUnprocessable();
    $post(['provider' => 'x', 'provider_event' => 'Lead'])->assertUnprocessable();
    $post(['provider_event' => 'LEAD'])->assertUnprocessable();
    $post(['provider' => 'google_ads', 'provider_event' => 'Lead form'])->assertUnprocessable();
    $post(['event' => 'link_click'])->assertUnprocessable();
    $post([])->assertCreated();

    expect(DB::table('conversion_mappings')->count())->toBe(1);
});

it('keeps mappings and the dispatch log inside the project and the workspace', function (): void {
    ['scenario' => $s, 'project' => $p] = dispatchWorld($this);
    $other = WorkspaceScenario::create('Another agency');

    $this->actingAs($other->owner)->getJson('/api/tracking/conversions?project_id='.$p->getKey())->assertNotFound();
    $this->actingAs($other->owner)->postJson('/api/tracking/conversions/mappings', ['project_id' => $p->getKey(), 'event' => 'lead_created', 'provider' => 'snapchat', 'provider_event' => 'SIGN_UP'])->assertNotFound();
    $this->actingAs($s->addMember(SystemRole::ClientViewer))->postJson('/api/tracking/conversions/mappings', ['project_id' => $p->getKey(), 'event' => 'lead_created', 'provider' => 'snapchat', 'provider_event' => 'SIGN_UP'])->assertForbidden();
});

it('simulates a conversion for a sandbox connection, says so, and sends a repeat once', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = dispatchWorld($this);
    connectSandbox($this, $s, Provider::Snapchat);
    mapEvent($this, $s, $p, 'form_submit', 'snapchat', 'SIGN_UP');

    $tp = dispatchVisit($this, $link);
    submitWith($this, $form, $tp, '0555002001', 'sub-disp-0001');
    submitWith($this, $form, $tp, '0555002001', 'sub-disp-0001');

    $rows = $this->actingAs($s->owner)->getJson('/api/tracking/conversions?project_id='.$p->getKey())->json('data.dispatches');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['event' => 'form_submit', 'provider' => 'snapchat', 'provider_event' => 'SIGN_UP', 'event_id' => 'sub-disp-0001', 'status' => 'sent', 'source_state' => 'SANDBOX', 'channel' => 'server'])
        ->and($rows[0]['provider_received'])->toBeNull()
        ->and($rows[0]['deduplicated'])->toBeNull();
});

it('records why a conversion was not sent, and never sends it', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = dispatchWorld($this);
    connectSandbox($this, $s, Provider::Snapchat);
    mapEvent($this, $s, $p, 'form_submit', 'snapchat', 'SIGN_UP');

    submitWith($this, $form, dispatchVisit($this, $link), '0555002002', 'sub-disp-0002', 'unknown');
    submitWith($this, $form, dispatchVisit($this, $link), '0555002003', 'sub-disp-0003', 'denied');
    submitWith($this, $form, dispatchVisit($this, $link, '', 'facebookexternalhit/1.1'), '0555002004', 'sub-disp-0004', 'granted');
    $test = $this->actingAs($s->owner)->postJson('/api/tracking/links/'.$link['id'].'/test')->json('data');
    submitWith($this, $form, $test['touchpoint'], '0555002005', 'sub-disp-0005', 'granted');

    $rows = array_column($this->actingAs($s->owner)->getJson('/api/tracking/conversions?project_id='.$p->getKey())->json('data.dispatches'), null, 'event_id');

    expect($rows['sub-disp-0002']['skip_reason'])->toBe('consent')
        ->and($rows['sub-disp-0003']['skip_reason'])->toBe('consent')
        ->and($rows['sub-disp-0004']['skip_reason'])->toBe('suspected_bot')
        ->and($rows['sub-disp-0005']['skip_reason'])->toBe('test_traffic')
        ->and(array_values(array_unique(array_column($rows, 'status'))))->toBe(['skipped'])
        ->and(DB::table('provider_event_dispatches')->where('status', 'sent')->count())->toBe(0);
});

it('follows the project’s consent policy, but never sends a visitor who said no', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = dispatchWorld($this);
    connectSandbox($this, $s, Provider::Snapchat);
    mapEvent($this, $s, $p, 'form_submit', 'snapchat', 'SIGN_UP');
    $this->actingAs($s->owner)->putJson('/api/tracking/settings/policy', ['project_id' => $p->getKey(), 'consent_policy' => 'not_required', 'retention_days' => 400])->assertOk();

    submitWith($this, $form, dispatchVisit($this, $link), '0555002006', 'sub-disp-0006', 'unknown');
    submitWith($this, $form, dispatchVisit($this, $link), '0555002007', 'sub-disp-0007', 'denied');

    $status = DB::table('provider_event_dispatches')->pluck('status', 'event_id')->all();
    expect($status['sub-disp-0006'])->toBe('sent')->and($status['sub-disp-0007'])->toBe('skipped');
    $this->actingAs($s->owner)->putJson('/api/tracking/settings/policy', ['project_id' => $p->getKey(), 'consent_policy' => 'required', 'retention_days' => 10])->assertUnprocessable();
    $this->actingAs($s->owner)->putJson('/api/tracking/settings/policy', ['project_id' => $p->getKey(), 'consent_policy' => 'maybe', 'retention_days' => 400])->assertUnprocessable();
});

it('does not send through a provider that is not connected or holds live credentials', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = dispatchWorld($this);
    mapEvent($this, $s, $p, 'form_submit', 'snapchat', 'SIGN_UP');
    submitWith($this, $form, dispatchVisit($this, $link), '0555002008', 'sub-disp-0008');
    expect(DB::table('provider_event_dispatches')->value('skip_reason'))->toBe('not_connected');

    connectSandbox($this, $s, Provider::Snapchat, sandbox: false);
    submitWith($this, $form, dispatchVisit($this, $link), '0555002009', 'sub-disp-0009');
    expect(DB::table('provider_event_dispatches')->where('event_id', 'sub-disp-0009')->value('skip_reason'))->toBe('live_transport_blocked');
});

it('sends the later stages of a lead as offline conversions once each, with the visit’s identifiers', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = dispatchWorld($this);
    connectSandbox($this, $s, Provider::GoogleAds);
    mapEvent($this, $s, $p, 'lead_qualified', 'google_ads', 'customers/123/conversionActions/456');
    mapEvent($this, $s, $p, 'sale_completed', 'google_ads', 'customers/123/conversionActions/789');

    submitWith($this, $form, dispatchVisit($this, $link, '?gclid=Cj0KCQ'), '0555002010', 'sub-disp-0010');
    submitWith($this, $form, dispatchVisit($this, $link), '0555002011', 'sub-disp-0011');
    $withId = (string) DB::table('leads')->where('phone', '0555002010')->value('id');
    $without = (string) DB::table('leads')->where('phone', '0555002011')->value('id');

    foreach ([$withId, $without] as $lead) {
        $this->actingAs($s->owner)->postJson('/api/leads/'.$lead.'/stage', ['stage' => 'qualified'])->assertOk();
    }

    $this->actingAs($s->owner)->postJson('/api/leads/'.$withId.'/stage', ['stage' => 'won'])->assertOk();
    $this->actingAs($s->owner)->postJson('/api/leads/'.$withId.'/stage', ['stage' => 'qualified'])->assertOk();

    $d = [];

    foreach (DB::table('provider_event_dispatches')->get() as $row) {
        $d[(string) $row->event_id][] = $row;
    }

    $one = static fn (string $key): stdClass => $d[$key][0] ?? throw new RuntimeException('No dispatch for '.$key);

    expect($d['lead-'.$withId.'-lead_qualified'])->toHaveCount(1)
        ->and($one('lead-'.$withId.'-lead_qualified')->status)->toBe('sent')
        ->and($one('lead-'.$withId.'-lead_qualified')->channel)->toBe('offline')
        ->and($one('lead-'.$withId.'-sale_completed')->status)->toBe('sent')
        ->and($one('lead-'.$without.'-lead_qualified')->skip_reason)->toBe('no_click_id');
});

it('keeps a failed or unknown dispatch final until a person retries it with the same event id', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = dispatchWorld($this);
    connectSandbox($this, $s, Provider::Snapchat);
    mapEvent($this, $s, $p, 'form_submit', 'snapchat', 'SIGN_UP');
    submitWith($this, $form, dispatchVisit($this, $link), '0555002012', 'sub-disp-0012');
    $id = (string) DB::table('provider_event_dispatches')->value('id');

    $this->actingAs($s->owner)->postJson('/api/tracking/conversions/dispatches/'.$id.'/retry')->assertNotFound();

    DB::table('provider_event_dispatches')->where('id', $id)->update(['status' => 'unknown', 'skip_reason' => null, 'server_sent_at' => null]);
    app(App\Application\Tracking\Command\DispatchConversion::class)($id);
    expect(DB::table('provider_event_dispatches')->where('id', $id)->value('status'))->toBe('unknown');

    $other = WorkspaceScenario::create('Another agency');
    $this->actingAs($other->owner)->postJson('/api/tracking/conversions/dispatches/'.$id.'/retry')->assertNotFound();
    $this->actingAs($s->owner)->postJson('/api/tracking/conversions/dispatches/'.$id.'/retry')->assertAccepted();

    $row = DB::table('provider_event_dispatches')->where('id', $id)->first();
    expect($row?->status)->toBe('sent')->and($row?->event_id)->toBe('sub-disp-0012');
});

it('rolls raw events past retention into counts, deletes them and strips identifiers', function (): void {
    ['scenario' => $s, 'project' => $p, 'link' => $link, 'form' => $form] = dispatchWorld($this);
    submitWith($this, $form, dispatchVisit($this, $link, '?gclid=Cj0KCQ'), '0555002013', 'sub-disp-0013');
    $this->actingAs($s->owner)->putJson('/api/tracking/settings/policy', ['project_id' => $p->getKey(), 'consent_policy' => 'required', 'retention_days' => 30])->assertOk();

    $old = now()->subDays(45);
    DB::table('tracking_events')->update(['occurred_at' => $old]);
    DB::table('tracking_sessions')->update(['started_at' => $old]);
    DB::table('form_submissions')->update(['submitted_at' => $old]);
    $events = DB::table('tracking_events')->count();

    $this->artisan('tracking:purge')->assertSuccessful();

    expect(DB::table('tracking_events')->count())->toBe(0)
        ->and(DB::table('tracking_sessions')->count())->toBe(0)
        ->and((int) DB::table('tracking_daily_rollups')->sum('events'))->toBe($events)
        ->and(DB::table('form_submissions')->count())->toBe(1)
        ->and(DB::table('form_submissions')->whereNotNull('visitor_id')->count())->toBe(0)
        ->and(DB::table('leads')->count())->toBe(1);

    $this->artisan('tracking:purge')->assertSuccessful();
    expect((int) DB::table('tracking_daily_rollups')->sum('events'))->toBe($events);
});

it('keeps recent events and other projects untouched by a purge', function (): void {
    ['link' => $link] = dispatchWorld($this);
    dispatchVisit($this, $link);

    $this->artisan('tracking:purge')->assertSuccessful();

    expect(DB::table('tracking_events')->count())->toBeGreaterThan(0)->and(DB::table('tracking_daily_rollups')->count())->toBe(0);
});
