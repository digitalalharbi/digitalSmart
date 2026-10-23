<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/** @return array{s: WorkspaceScenario, project: Project, form: string, buyer: User, manager: User, outsider: User, suspended: User, client: User} */
function automationWorld(): array
{
    $s = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $s->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();
    $join = static function (User $user, SystemRole $role) use ($s, $project): User {
        ProjectMember::query()->create(['project_id' => $project->getKey(), 'user_id' => $user->getKey(), 'role_id' => $s->role($role)->getKey()]);

        return $user;
    };

    $form = test()->actingAs($s->owner)->postJson('/api/tracking/forms', [
        'project_id' => (string) $project->getKey(), 'name' => 'Enquiry', 'creates_lead' => true, 'fields' => [
            ['key' => 'name', 'type' => 'text', 'label_en' => 'Name', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'phone', 'type' => 'phone', 'label_en' => 'Phone', 'required' => true, 'maps_to' => 'phone'],
        ],
    ])->assertCreated()->json('data');
    test()->actingAs($s->owner)->postJson('/api/tracking/forms/'.$form['id'].'/status', ['status' => 'published'])->assertOk();

    return [
        's' => $s, 'project' => $project, 'form' => (string) $form['public_url_token'],
        'buyer' => $join($s->addMember(SystemRole::MediaBuyer), SystemRole::MediaBuyer),
        'manager' => $join($s->addMember(SystemRole::ProjectManager), SystemRole::ProjectManager),
        'outsider' => $s->addMember(SystemRole::MediaBuyer),
        'suspended' => $join($s->addMember(SystemRole::MediaBuyer, MembershipStatus::Suspended), SystemRole::MediaBuyer),
        'client' => $join($s->addMember(SystemRole::ClientViewer), SystemRole::ClientViewer),
    ];
}

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function automationSettings(string $project, array $over = []): array
{
    return [
        'project_id' => $project, 'enabled' => true, 'assignment_mode' => 'none', 'create_task' => false, 'notify_owner' => true,
        'sla_minutes' => 30, 'task_priority' => 'high', ...$over,
    ];
}

function automationSubmit(string $form, int $n): void
{
    test()->postJson('/api/public/forms/'.$form.'/submit', [
        'event_id' => 'auto-submit-event-'.$n, 'consent' => 'granted', 'values' => ['name' => 'Prospect '.$n, 'phone' => '05550040'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)],
    ])->assertCreated();
}

/**
 * @param  array{s: WorkspaceScenario, project: Project, form: string, buyer: User, manager: User, outsider: User, suspended: User, client: User}  $w
 * @return list<string>
 */
function automationPool(array $w): array
{
    return array_column(test()->actingAs($w['s']->owner)->getJson('/api/tracking/follow-up?project_id='.$w['project']->getKey())->assertOk()->json('data.owners'), 'id');
}

it('offers as owners only people who may open this project’s leads', function (): void {
    $w = automationWorld();
    $pool = automationPool($w);

    expect($pool)->toContain((string) $w['buyer']->getKey())
        ->and($pool)->not->toContain((string) $w['suspended']->getKey())
        ->and($pool)->not->toContain((string) $w['client']->getKey())
        ->and($pool)->not->toContain((string) $w['outsider']->getKey());

    $sorted = $pool;
    sort($sorted);
    expect($pool)->toBe($sorted);
});

it('refuses an owner who could not open the leads they were given', function (): void {
    $w = automationWorld();

    foreach (['outsider', 'suspended', 'client'] as $who) {
        test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings((string) $w['project']->getKey(), [
            'assignment_mode' => 'specific', 'owner_id' => (string) $w[$who]->getKey(),
        ]))->assertUnprocessable();
    }

    test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings((string) $w['project']->getKey(), ['assignment_mode' => 'specific']))->assertUnprocessable();
});

it('does nothing to a new lead until the project switches automation on', function (): void {
    $w = automationWorld();
    test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings((string) $w['project']->getKey(), [
        'enabled' => false, 'assignment_mode' => 'specific', 'owner_id' => (string) $w['buyer']->getKey(), 'create_task' => true,
    ]))->assertOk();
    automationSubmit($w['form'], 1);

    expect(DB::table('leads')->whereNotNull('owner_id')->count())->toBe(0)->and(DB::table('tasks')->count())->toBe(0);
});

it('assigns a new lead, opens its task and tells the owner without naming the prospect', function (): void {
    $w = automationWorld();
    test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings((string) $w['project']->getKey(), [
        'assignment_mode' => 'specific', 'owner_id' => (string) $w['buyer']->getKey(), 'create_task' => true,
    ]))->assertOk();
    automationSubmit($w['form'], 7);

    $lead = DB::table('leads')->first();
    $task = DB::table('tasks')->first();
    assert($lead instanceof stdClass && $task instanceof stdClass);
    $note = DB::table('notifications')->where('user_id', $w['buyer']->getKey())->where('kind', 'lead_assigned')->first();

    expect($lead->owner_id)->toBe((string) $w['buyer']->getKey())
        ->and($lead->assignment_reason)->toBe('automation')
        ->and($task->subject_type)->toBe('lead')->and($task->subject_id)->toBe($lead->id)
        ->and($task->project_id)->toBe((string) $w['project']->getKey())
        ->and($task->priority)->toBe('high')
        ->and($task->due_on)->not->toBeNull()
        ->and($note)->not->toBeNull();

    $text = json_encode([$task, $note], JSON_THROW_ON_ERROR);
    expect($text)->not->toContain('Prospect 7')->and($text)->not->toContain('0555004007');
});

it('hands leads round the people who may have them, in a fixed order, skipping nobody who is eligible and nobody who is not', function (): void {
    $w = automationWorld();
    test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings((string) $w['project']->getKey(), ['assignment_mode' => 'round_robin']))->assertOk();
    $pool = automationPool($w);

    foreach (range(1, count($pool) * 2 + 1) as $n) {
        automationSubmit($w['form'], $n);
    }

    $owners = DB::table('leads')->orderBy('received_at')->orderBy('id')->pluck('owner_id')->all();
    $expected = [];

    foreach (range(0, count($pool) * 2) as $i) {
        $expected[] = $pool[$i % count($pool)];
    }

    expect($owners)->toBe($expected)
        ->and(array_unique($owners))->not->toContain((string) $w['suspended']->getKey())
        ->and(array_unique($owners))->not->toContain((string) $w['outsider']->getKey())
        ->and(DB::table('leads')->pluck('assignment_reason')->unique()->all())->toBe(['round_robin']);
});

it('moves on past a person who stopped being eligible between two leads', function (): void {
    $w = automationWorld();
    test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings((string) $w['project']->getKey(), ['assignment_mode' => 'round_robin']))->assertOk();
    $pool = automationPool($w);
    automationSubmit($w['form'], 1);

    DB::table('workspace_members')->where('user_id', $pool[1])->where('workspace_id', $w['s']->workspace->getKey())->update(['status' => MembershipStatus::Suspended->value]);
    automationSubmit($w['form'], 2);
    automationSubmit($w['form'], 3);

    $owners = DB::table('leads')->orderBy('received_at')->orderBy('id')->pluck('owner_id')->all();

    expect($owners)->not->toContain($pool[1] ?? '')
        ->and(count($owners))->toBe(3);
});

it('groups what the team still has to do, and states each lead against the promised response time', function (): void {
    $w = automationWorld();
    $project = (string) $w['project']->getKey();
    test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings($project, ['sla_minutes' => 30]))->assertOk();

    foreach (range(1, 4) as $n) {
        automationSubmit($w['form'], $n);
    }

    $ids = DB::table('leads')->orderBy('id')->pluck('id')->all();
    DB::table('leads')->where('id', $ids[0])->update(['received_at' => now()->subMinutes(10)]);
    DB::table('leads')->where('id', $ids[1])->update(['received_at' => now()->subMinutes(25)]);
    DB::table('leads')->where('id', $ids[2])->update(['received_at' => now()->subMinutes(90)]);
    DB::table('leads')->where('id', $ids[3])->update(['received_at' => now()->subHours(40)]);
    DB::table('lead_events')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'workspace_id' => $w['s']->workspace->getKey(), 'lead_id' => $ids[3], 'type' => 'stage_changed', 'from_stage' => 'new', 'to_stage' => 'contacted', 'occurred_at' => now()->subHours(39), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('leads')->where('id', $ids[3])->update(['stage' => 'contacted']);

    $groups = test()->actingAs($w['s']->owner)->getJson('/api/tracking/follow-up?project_id='.$project)->assertOk()->json('data.groups');

    expect($groups['new']['count'])->toBe(1)->and($groups['approaching']['count'])->toBe(1)
        ->and($groups['overdue']['count'])->toBe(1)->and($groups['no_response']['count'])->toBe(1)
        ->and($groups['overdue']['items'][0]['sla_state'])->toBe('overdue')
        ->and($groups['overdue']['items'][0]['due_at'])->not->toBeNull();
});

it('raises one response-time alert per state, to the owner, with nothing about the prospect in it', function (): void {
    $w = automationWorld();
    $project = (string) $w['project']->getKey();
    test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings($project, [
        'assignment_mode' => 'specific', 'owner_id' => (string) $w['buyer']->getKey(), 'sla_minutes' => 40,
    ]))->assertOk();
    automationSubmit($w['form'], 9);
    $lead = (string) DB::table('leads')->value('id');

    DB::table('leads')->where('id', $lead)->update(['received_at' => now()->subMinutes(32)]);
    Artisan::call('leads:sla-alerts');
    Artisan::call('leads:sla-alerts');
    expect(DB::table('notifications')->where('kind', 'lead_sla_approaching')->where('user_id', $w['buyer']->getKey())->count())->toBe(1);

    DB::table('leads')->where('id', $lead)->update(['received_at' => now()->subMinutes(55)]);
    Artisan::call('leads:sla-alerts');
    $overdue = DB::table('notifications')->where('kind', 'lead_sla_overdue')->where('user_id', $w['buyer']->getKey())->get();

    expect($overdue)->toHaveCount(1)
        ->and($overdue->first()?->severity)->toBe('critical')
        ->and(json_encode($overdue, JSON_THROW_ON_ERROR))->not->toContain('Prospect 9');

    /* Once somebody tries, there is nothing left to warn about. */
    DB::table('lead_events')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'workspace_id' => $w['s']->workspace->getKey(), 'lead_id' => $lead, 'type' => 'stage_changed', 'from_stage' => 'new', 'to_stage' => 'contacted', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('notifications')->delete();
    Artisan::call('leads:sla-alerts');
    expect(DB::table('notifications')->count())->toBe(0);
});

it('tells the owner when their lead is qualified, booked or sold — and never tells the person who did it', function (): void {
    $w = automationWorld();
    $project = (string) $w['project']->getKey();
    test()->actingAs($w['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings($project, [
        'assignment_mode' => 'specific', 'owner_id' => (string) $w['buyer']->getKey(),
    ]))->assertOk();
    automationSubmit($w['form'], 3);
    $lead = (string) DB::table('leads')->value('id');

    test()->actingAs($w['s']->owner)->postJson('/api/leads/'.$lead.'/stage', ['stage' => 'contacted'])->assertOk();
    test()->actingAs($w['s']->owner)->postJson('/api/leads/'.$lead.'/stage', ['stage' => 'qualified'])->assertOk();
    test()->actingAs($w['s']->owner)->postJson('/api/leads/'.$lead.'/milestones', ['type' => 'appointment_booked'])->assertSuccessful();
    test()->actingAs($w['s']->owner)->postJson('/api/leads/'.$lead.'/stage', ['stage' => 'won'])->assertOk();

    $kinds = DB::table('notifications')->where('user_id', $w['buyer']->getKey())->pluck('kind')->all();
    expect($kinds)->toContain('lead_qualified', 'lead_appointment_booked', 'lead_sale_completed')
        ->and(DB::table('notifications')->where('user_id', $w['s']->owner->getKey())->whereIn('kind', ['lead_qualified', 'lead_sale_completed'])->count())->toBe(0);
});

it('keeps the queue and the settings to people who may read this project', function (): void {
    $w = automationWorld();
    $project = (string) $w['project']->getKey();
    $other = automationWorld();

    test()->actingAs($other['s']->owner)->getJson('/api/tracking/follow-up?project_id='.$project)->assertNotFound();
    test()->actingAs($other['s']->owner)->putJson('/api/tracking/follow-up/settings', automationSettings($project))->assertNotFound();
    test()->actingAs($w['outsider'])->getJson('/api/tracking/follow-up?project_id='.$project)->assertNotFound();
    test()->actingAs($w['client'])->putJson('/api/tracking/follow-up/settings', automationSettings($project))->assertForbidden();
});
