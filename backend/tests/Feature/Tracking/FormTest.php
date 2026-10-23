<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Forms, public submission, and the lead a submission makes
|--------------------------------------------------------------------------
|
| What is asserted: a form belongs to a project and is invisible outside it; the public sees a published form and nothing
| about the tenant; the server judges every answer; the submission is the system of record and is kept once; a lead is
| made through the one lead system, carrying the campaign the visit came from; and no answer ever reaches the event store.
|
*/

const FORM_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{0: WorkspaceScenario, 1: Project} */
function formProject(?WorkspaceScenario $scenario = null): array
{
    $scenario ??= WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    return [$scenario, Project::factory()->forClient($client)->create()];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function formBody(Project $project, array $overrides = []): array
{
    return [
        'project_id' => $project->getKey(),
        'name' => 'Villa enquiry',
        'default_locale' => 'ar',
        'creates_lead' => true,
        'thank_you_ar' => 'شكراً لك',
        'thank_you_en' => 'Thank you',
        'fields' => [
            ['key' => 'name', 'type' => 'text', 'label_ar' => 'الاسم', 'label_en' => 'Name', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'phone', 'type' => 'phone', 'label_ar' => 'الجوال', 'label_en' => 'Mobile', 'required' => true, 'maps_to' => 'phone'],
            ['key' => 'city', 'type' => 'select', 'label_ar' => 'المدينة', 'label_en' => 'City', 'options' => [['value' => 'riyadh', 'label_ar' => 'الرياض'], ['value' => 'jeddah', 'label_ar' => 'جدة']], 'maps_to' => 'custom.city'],
        ],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: string, token: string}
 */
function publishedForm(object $test, WorkspaceScenario $scenario, Project $project, array $overrides = []): array
{
    $form = $test->actingAs($scenario->owner)->postJson('/api/tracking/forms', formBody($project, $overrides))->assertCreated()->json('data');
    $test->actingAs($scenario->owner)->postJson('/api/tracking/forms/'.$form['id'].'/status', ['status' => 'published'])->assertOk();

    return ['id' => $form['id'], 'token' => $form['public_url_token']];
}

it('builds a form in a project and keeps it from anybody outside it', function (): void {
    [$agency, $project] = formProject();
    $other = WorkspaceScenario::create('Another agency');

    $this->actingAs($other->owner)->postJson('/api/tracking/forms', formBody($project))->assertNotFound();
    $form = $this->actingAs($agency->owner)->postJson('/api/tracking/forms', formBody($project))->assertCreated()->json('data');

    expect($form['status'])->toBe('draft')->and($form['fields'])->toHaveCount(3);
    $this->actingAs($other->owner)->getJson('/api/tracking/forms/'.$form['id'])->assertNotFound();
    expect($this->actingAs($other->owner)->getJson('/api/tracking/forms')->json('data'))->toBe([]);
    $this->actingAs($agency->addMember(SystemRole::ClientViewer))->postJson('/api/tracking/forms', formBody($project))->assertForbidden();
});

it('refuses to publish a form with nothing in it, or a lead form that cannot reach anybody', function (): void {
    [$scenario, $project] = formProject();

    $empty = $this->actingAs($scenario->owner)->postJson('/api/tracking/forms', formBody($project, ['creates_lead' => false, 'fields' => []]))->assertCreated()->json('data');
    $this->actingAs($scenario->owner)->postJson('/api/tracking/forms/'.$empty['id'].'/status', ['status' => 'published'])->assertUnprocessable();

    $this->actingAs($scenario->owner)->postJson('/api/tracking/forms', formBody($project, ['fields' => [['key' => 'city', 'type' => 'text', 'label_en' => 'City']]]))
        ->assertUnprocessable();
});

it('refuses an unsafe redirect after submit', function (): void {
    [$scenario, $project] = formProject();

    $this->actingAs($scenario->owner)->postJson('/api/tracking/forms', formBody($project, ['redirect_url' => 'http://127.0.0.1/']))->assertUnprocessable();
    $this->actingAs($scenario->owner)->postJson('/api/tracking/forms', formBody($project, ['redirect_url' => 'https://shop.example.com/thanks']))->assertCreated();
});

it('shows the public a published form and nothing about the tenant behind it', function (): void {
    [$scenario, $project] = formProject();
    $form = publishedForm($this, $scenario, $project);
    $draft = $this->actingAs($scenario->owner)->postJson('/api/tracking/forms', formBody($project, ['name' => 'Draft']))->json('data.public_url_token');

    $json = $this->getJson('/api/public/forms/'.$form['token'])->assertOk()->json('data');
    $raw = json_encode($json, JSON_THROW_ON_ERROR);

    expect($json['fields'])->toHaveCount(3)
        ->and($raw)->not->toContain((string) $project->getKey())
        ->and($raw)->not->toContain((string) $project->client_id)
        ->and($raw)->not->toContain($scenario->workspace->getKey())
        ->and($raw)->not->toContain('maps_to')
        ->and($raw)->not->toContain('custom.city');

    $this->getJson('/api/public/forms/'.$draft)->assertNotFound();
    $this->getJson('/api/public/forms/Zzzzzzzzzzzz')->assertNotFound();
});

it('keeps a submission once and makes a lead from it, carrying the campaign the visit came from', function (): void {
    [$scenario, $project] = formProject();
    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', [
        'project_id' => $project->getKey(), 'name' => 'Snap', 'destination_url' => 'https://shop.example.com/offer', 'platform' => 'snapchat',
        'campaign' => 'Spring', 'template_patterns' => ['utm_source' => '{{platform}}', 'utm_medium' => 'paid', 'utm_campaign' => '{{campaign}}'],
    ])->assertCreated()->json('data');
    $location = (string) $this->withHeaders(['User-Agent' => FORM_BROWSER])->get('/api/r/'.basename((string) $link['short_url']).'?gclid=Cj0KCQ')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
    $form = publishedForm($this, $scenario, $project);

    $body = ['event_id' => 'sub-0001-aaaa', 'touchpoint' => $q['ds_tp'], 'locale' => 'ar', 'consent' => 'granted',
        'values' => ['name' => 'Noura', 'phone' => '0555001122', 'city' => 'riyadh', 'unknown_field' => 'x']];

    $first = $this->postJson('/api/public/forms/'.$form['token'].'/submit', $body)->assertCreated()->json('data');
    $repeat = $this->postJson('/api/public/forms/'.$form['token'].'/submit', $body)->assertCreated()->json('data');

    expect($first['thank_you']['ar'])->toBe('شكراً لك')->and($repeat)->toBe($first)
        ->and(DB::table('form_submissions')->count())->toBe(1)
        ->and(DB::table('leads')->count())->toBe(1);

    $submission = DB::table('form_submissions')->firstOrFail();
    $lead = DB::table('leads')->firstOrFail();
    expect(json_decode((string) $submission->payload, true))->toEqualCanonicalizing(['name' => 'Noura', 'phone' => '0555001122', 'city' => 'riyadh'])
        ->and($submission->tracking_link_id)->toBe($link['id'])
        ->and($submission->lead_id)->toBe($lead->id)
        ->and($submission->consent_state)->toBe('granted')
        ->and($lead->source_kind)->toBe('form_submission')
        ->and($lead->utm_campaign)->toBe('spring')
        ->and($lead->click_identifier)->toBe('Cj0KCQ')
        ->and($lead->attribution_evidence)->toBe('click_identifier')
        ->and(json_decode((string) $lead->custom, true))->toBe(['city' => 'riyadh'])
        ->and($lead->project_id)->toBe($project->getKey());

    expect(DB::table('tracking_events')->whereIn('name', ['form_submit', 'lead_created'])->pluck('name')->sort()->values()->all())->toBe(['form_submit', 'lead_created']);
});

it('adds nothing new for the same person submitting again, and says the lead was not created twice', function (): void {
    [$scenario, $project] = formProject();
    $form = publishedForm($this, $scenario, $project);

    foreach (['sub-0000-0001', 'sub-0000-0002'] as $id) {
        $this->postJson('/api/public/forms/'.$form['token'].'/submit', ['event_id' => $id, 'values' => ['name' => 'Noura', 'phone' => '+966555001122']])->assertCreated();
    }

    expect(DB::table('form_submissions')->count())->toBe(2)
        ->and(DB::table('leads')->count())->toBe(1)
        ->and(DB::table('tracking_events')->where('name', 'lead_created')->count())->toBe(1);
});

it('tells the visitor which answers to fix and keeps nothing', function (): void {
    [$scenario, $project] = formProject();
    $form = publishedForm($this, $scenario, $project);

    $this->postJson('/api/public/forms/'.$form['token'].'/submit', ['event_id' => 'sub-0000-0003', 'values' => ['name' => '', 'phone' => '12', 'city' => 'mars']])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name', 'required')->assertJsonPath('errors.phone', 'invalid')->assertJsonPath('errors.city', 'invalid');

    expect(DB::table('form_submissions')->count())->toBe(0)->and(DB::table('leads')->count())->toBe(0);
});

it('does not accept a submission for a draft, an archived form or the wrong token', function (): void {
    [$scenario, $project] = formProject();
    $draft = $this->actingAs($scenario->owner)->postJson('/api/tracking/forms', formBody($project))->json('data.public_url_token');
    $body = ['event_id' => 'sub-0000-0004', 'values' => ['name' => 'N', 'phone' => '0555001122']];

    $this->postJson('/api/public/forms/'.$draft.'/submit', $body)->assertNotFound();
    $this->postJson('/api/public/forms/Zzzzzzzzzzzz/submit', $body)->assertNotFound();
    expect(DB::table('form_submissions')->count())->toBe(0);
});

it('drops a honeypot submission quietly and ignores a touchpoint from another project', function (): void {
    [$scenario, $project] = formProject();
    [, $elsewhere] = formProject($scenario);
    $link = $this->actingAs($scenario->owner)->postJson('/api/tracking/links', [
        'project_id' => $elsewhere->getKey(), 'name' => 'Other', 'destination_url' => 'https://shop.example.com/x', 'utm' => ['utm_campaign' => 'other'],
    ])->assertCreated()->json('data');
    $location = (string) $this->withHeaders(['User-Agent' => FORM_BROWSER])->get('/api/r/'.basename((string) $link['short_url']))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
    $form = publishedForm($this, $scenario, $project);
    $values = ['name' => 'Noura', 'phone' => '0555001122'];

    $this->postJson('/api/public/forms/'.$form['token'].'/submit', ['event_id' => 'sub-0000-0005', 'website' => 'http://spam', 'values' => $values])->assertAccepted();
    expect(DB::table('form_submissions')->count())->toBe(0);

    $this->postJson('/api/public/forms/'.$form['token'].'/submit', ['event_id' => 'sub-0000-0006', 'touchpoint' => $q['ds_tp'], 'values' => $values])->assertCreated();
    expect(DB::table('form_submissions')->firstOrFail()->tracking_link_id)->toBeNull()
        ->and(DB::table('leads')->firstOrFail()->utm_campaign)->toBeNull();
});

it('stores no answer in the event store', function (): void {
    [$scenario, $project] = formProject();
    $form = publishedForm($this, $scenario, $project);

    $this->postJson('/api/public/forms/'.$form['token'].'/submit', ['event_id' => 'sub-0000-0007', 'values' => ['name' => 'Noura Secret', 'phone' => '0555001122']])->assertCreated();

    $dump = json_encode(DB::table('tracking_events')->get(), JSON_THROW_ON_ERROR);
    expect($dump)->not->toContain('Noura')->and($dump)->not->toContain('0555001122')->and($dump)->not->toContain('555001122');
});
