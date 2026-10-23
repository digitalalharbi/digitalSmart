<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Tracking in a report
|--------------------------------------------------------------------------
|
| What is asserted: each view reads the traffic that really arrived, a step no page reported is "not reported" and never a
| zero, nothing that identifies a person, a link address, a token or an internal id reaches a client-safe report or its
| share link, and a section that does not say what it shows is refused.
|
*/

const REPORT_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
const REPORT_VIEWS = ['links', 'forms', 'funnel', 'follow_up', 'health'];

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, link: array<string, mixed>, form: string} */
function trackingReportWorld(object $test): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();
    $link = $test->actingAs($scenario->owner)->postJson('/api/tracking/links', [
        'project_id' => $project->getKey(), 'name' => 'Snap spring', 'destination_url' => 'https://secret-shop.example.com/private-offer', 'platform' => 'snapchat',
    ])->assertCreated()->json('data');
    $form = $test->actingAs($scenario->owner)->postJson('/api/tracking/forms', [
        'project_id' => $project->getKey(), 'name' => 'Enquiry form', 'creates_lead' => true, 'fields' => [
            ['key' => 'name', 'type' => 'text', 'label_en' => 'Name', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'phone', 'type' => 'phone', 'label_en' => 'Phone', 'required' => true, 'maps_to' => 'phone'],
        ],
    ])->assertCreated()->json('data');
    $test->actingAs($scenario->owner)->postJson('/api/tracking/forms/'.$form['id'].'/status', ['status' => 'published'])->assertOk();

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project, 'link' => $link, 'form' => $form['public_url_token']];
}

/** @param array<string, mixed> $link */
function trackingReportVisit(object $test, array $link, ?string $submitTo = null, string $phone = ''): void
{
    $location = (string) $test->withHeaders(['User-Agent' => REPORT_BROWSER])->get('/api/r/'.basename((string) $link['short_url']).'?gclid=Cj0KCQ')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    if ($submitTo !== null) {
        $test->postJson('/api/public/forms/'.$submitTo.'/submit', [
            'event_id' => 'sub-rpt-'.$phone, 'touchpoint' => $q['ds_tp'], 'consent' => 'granted', 'values' => ['name' => 'Secret Person', 'phone' => $phone],
        ])->assertCreated();
    }
}

/** A report window ends yesterday, so traffic recorded now is moved three days back. */
function trackingBackdate(): void
{
    DB::statement("UPDATE tracking_sessions SET started_at = started_at - interval '3 days'");
    DB::statement("UPDATE form_submissions SET submitted_at = submitted_at - interval '3 days'");
    DB::statement("UPDATE leads SET received_at = received_at - interval '3 days'");
}

/** @param array{scenario: WorkspaceScenario, client: Client} $w */
function trackingReport(object $test, array $w, string $audience = 'client_safe'): string
{
    return (string) $test->actingAs($w['scenario']->owner)->postJson('/api/reports', [
        'name' => 'Tracking month', 'client_id' => (string) $w['client']->getKey(), 'window' => 'last_30_days', 'audience' => $audience,
        'sections' => array_map(static fn (string $v): array => ['kind' => 'tracking', 'tracking_view' => $v], REPORT_VIEWS),
    ])->assertCreated()->json('data.id');
}

it('reads each view of the traffic that really arrived, with a gap shown as a gap', function (): void {
    $w = trackingReportWorld($this);
    trackingReportVisit($this, $w['link'], $w['form'], '0555003001');
    trackingReportVisit($this, $w['link']);

    trackingBackdate();
    $sections = $this->actingAs($w['scenario']->owner)->getJson('/api/reports/'.trackingReport($this, $w))->assertOk()->json('data.sections');
    $by = array_column($sections, null, 'title');

    $links = $by['Link performance']['tracking']['rows'][0];
    expect($links)->toMatchArray(['link' => 'Snap spring', 'platform' => 'snapchat', 'clicks' => 2, 'submissions' => 1, 'leads' => 1])
        ->and($links['landing_views'])->toBeNull();

    expect($by['Form performance']['tracking']['rows'][0])->toMatchArray(['form' => 'Enquiry form', 'submissions' => 1, 'leads' => 1]);

    $funnel = array_column($by['Funnel']['tracking']['rows'], null, 'step');
    expect($funnel['Link click']['count'])->toBe(2)
        ->and($funnel['Landing view']['count'])->toBeNull()
        ->and($funnel['Form submitted']['count'])->toBe(1)
        ->and($funnel['Form submitted']['rate'])->toBeNull();

    $follow = array_column($by['Lead follow-up']['tracking']['rows'], 'value', 'figure');
    expect($follow['Leads'])->toBe(1)->and($follow['Not yet answered'])->toBe(1);

    $health = array_column($by['Tracking health']['tracking']['rows'], 'state', 'check');
    expect($health['First-party events'])->toBe('PARTIAL')->and($health)->not->toHaveKey('Meta — browser')->and($health)->not->toHaveKey('Deduplication');
});

it('sends a client nothing that identifies a person, an address, a token or a record', function (): void {
    $w = trackingReportWorld($this);
    trackingReportVisit($this, $w['link'], $w['form'], '0555003002');
    trackingBackdate();
    $report = trackingReport($this, $w);

    $internal = (string) $this->actingAs($w['scenario']->owner)->getJson('/api/reports/'.$report)->assertOk()->getContent();
    $token = (string) $this->actingAs($w['scenario']->owner)->postJson('/api/reports/'.$report.'/shares', ['days' => 7])->assertCreated()->json('meta.token');
    $shared = (string) $this->getJson('/api/shared-reports/'.$token)->assertOk()->getContent();

    foreach ([$internal, $shared] as $body) {
        expect($body)->not->toContain('Secret Person')
            ->and($body)->not->toContain('0555003002')
            ->and($body)->not->toContain('secret-shop.example.com')
            ->and($body)->not->toContain(basename((string) $w['link']['short_url']))
            ->and($body)->not->toContain('Cj0KCQ')
            ->and($body)->not->toContain($w['form'])
            ->and($body)->not->toContain((string) $w['project']->getKey());
    }

    expect($shared)->not->toContain((string) $w['link']['id']);
});

it('says an empty client has nothing recorded rather than showing zeroes', function (): void {
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $w = ['scenario' => $scenario, 'client' => $client];

    $sections = $this->actingAs($scenario->owner)->getJson('/api/reports/'.trackingReport($this, $w))->assertOk()->json('data.sections');

    $by = array_column($sections, 'is_empty', 'title');
    expect($by['Link performance'])->toBeTrue()->and($by['Form performance'])->toBeTrue();
});

it('refuses a tracking section with no view, or an unknown one, and keeps a client’s report to that client', function (): void {
    $w = trackingReportWorld($this);
    $body = ['name' => 'x1', 'client_id' => (string) $w['client']->getKey(), 'window' => 'last_30_days', 'audience' => 'client_safe'];

    $this->actingAs($w['scenario']->owner)->postJson('/api/reports', [...$body, 'sections' => [['kind' => 'tracking']]])->assertUnprocessable();
    $this->actingAs($w['scenario']->owner)->postJson('/api/reports', [...$body, 'sections' => [['kind' => 'tracking', 'tracking_view' => 'sessions']]])->assertUnprocessable();

    trackingReportVisit($this, $w['link'], $w['form'], '0555003003');
    $other = trackingReportWorld($this);
    trackingBackdate();
    $report = trackingReport($this, $other);
    $links = $this->actingAs($other['scenario']->owner)->getJson('/api/reports/'.$report)->json('data.sections.0.tracking.rows');

    expect($links[0]['clicks'])->toBe(0)->and(DB::table('form_submissions')->count())->toBe(1);
});

it('writes the tracking tables to a spreadsheet and a csv with gaps said aloud', function (): void {
    $w = trackingReportWorld($this);
    trackingReportVisit($this, $w['link'], $w['form'], '0555003004');
    trackingBackdate();
    $report = trackingReport($this, $w);

    $csv = (string) $this->actingAs($w['scenario']->owner)->get('/api/reports/'.$report.'/csv')->assertOk()->getContent();
    expect($csv)->toContain('"Link","Platform","Clicks"')->and($csv)->toContain('Not reported')->and($csv)->not->toContain('0555003004');

    $this->actingAs($w['scenario']->owner)->get('/api/reports/'.$report.'/xlsx')->assertOk();
});
