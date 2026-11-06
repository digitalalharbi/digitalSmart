<?php

declare(strict_types=1);

use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\Contract\NotificationBroadcaster;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Notifications\DTO\NotificationView;
use App\Domain\Access\SystemRole;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Quiet hours and snooze
|--------------------------------------------------------------------------
|
| Quiet is about interruption, never about being told. Inside somebody's quiet hours, or while they have snoozed, the
| notification is still written to their bell; what is withheld is the realtime push and the copy over another channel,
| and that copy is a `held` row rather than a silence. A critical notification interrupts anyway. Nothing held is sent
| later, and nobody can set any of this for somebody else.
|
*/

/** A broadcaster that remembers who it was asked to push to. */
final class QuietRecordingBroadcaster implements NotificationBroadcaster
{
    /** @var list<string> */
    public array $pushedTo = [];

    public function push(string $workspaceId, array $notifications): void
    {
        foreach ($notifications as $notification) {
            $this->pushedTo[] = $notification->recipientId;
        }
    }

    public function isConfigured(): bool
    {
        return true;
    }
}

/** @return array{from: int, until: int} a window, in UTC hours, that contains this instant */
function quietWindowNow(): array
{
    $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('G');

    return ['from' => $hour, 'until' => ($hour + 2) % 24];
}

/** @return array{from: int, until: int} a window, in UTC hours, that does not contain this instant or the next hour */
function quietWindowLater(): array
{
    $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('G');

    return ['from' => ($hour + 3) % 24, 'until' => ($hour + 5) % 24];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\JsonResponse>
 */
function saveQuietPreferences(User $user, array $overrides = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->putJson('/api/notifications/preferences', [
        'daily' => true, 'weekly' => true, 'monthly' => true, 'hour' => 9, 'timezone' => 'UTC',
        'channels' => ['in_app'], 'muted_categories' => [],
        ...$overrides,
    ]);
}

/** @return array{scenario: WorkspaceScenario, project: Project, client: Client, creative: User, broadcaster: QuietRecordingBroadcaster} */
function quietScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();
    $creative = $scenario->addMember(SystemRole::Creative);

    ProjectMember::query()->create([
        'project_id' => $project->getKey(),
        'user_id' => $creative->getKey(),
        'role_id' => $scenario->role(SystemRole::Creative)->getKey(),
    ]);

    $broadcaster = new QuietRecordingBroadcaster;
    app()->instance(NotificationBroadcaster::class, $broadcaster);

    return ['scenario' => $scenario, 'project' => $project, 'client' => $client, 'creative' => $creative, 'broadcaster' => $broadcaster];
}

/** @param array{scenario: WorkspaceScenario, project: Project, client: Client, creative: User, broadcaster: QuietRecordingBroadcaster} $setup */
function assignQuietTask(array $setup): string
{
    $owner = $setup['scenario']->owner;

    $taskId = (string) test()->actingAs($owner)->postJson('/api/tasks', [
        'title' => 'Rebuild the audience lists',
        'client_id' => (string) $setup['client']->getKey(),
        'project_id' => (string) $setup['project']->getKey(),
    ])->assertCreated()->json('data.id');

    test()->actingAs($owner)
        ->postJson("/api/tasks/{$taskId}/assignee", ['assignee_id' => (string) $setup['creative']->getKey()])
        ->assertOk();

    return $taskId;
}

it('knows a window that wraps midnight, in the person\'s own zone', function (): void {
    $night = new NotificationPreferences(timezone: 'Asia/Riyadh', quietFrom: 22, quietUntil: 7);

    // 20:30 UTC is 23:30 in Riyadh; 03:59 UTC is 06:59; 04:00 UTC is 07:00, and the end is exclusive.
    expect($night->isQuietAt(new DateTimeImmutable('2026-11-06 20:30:00 UTC'), 'UTC'))->toBeTrue();
    expect($night->isQuietAt(new DateTimeImmutable('2026-11-06 03:59:00 UTC'), 'UTC'))->toBeTrue();
    expect($night->isQuietAt(new DateTimeImmutable('2026-11-06 04:00:00 UTC'), 'UTC'))->toBeFalse();
    expect($night->isQuietAt(new DateTimeImmutable('2026-11-06 12:00:00 UTC'), 'UTC'))->toBeFalse();

    // With no zone of their own, the workspace's decides.
    $followsWorkspace = new NotificationPreferences(quietFrom: 22, quietUntil: 7);
    expect($followsWorkspace->isQuietAt(new DateTimeImmutable('2026-11-06 20:30:00 UTC'), 'Asia/Riyadh'))->toBeTrue();
    expect($followsWorkspace->isQuietAt(new DateTimeImmutable('2026-11-06 20:30:00 UTC'), 'UTC'))->toBeFalse();

    // A snooze is an instant, and it ends by itself.
    $snoozed = new NotificationPreferences(snoozedUntil: new DateTimeImmutable('2026-11-06 13:00:00 UTC'));
    expect($snoozed->isQuietAt(new DateTimeImmutable('2026-11-06 12:59:00 UTC'), 'UTC'))->toBeTrue();
    expect($snoozed->isQuietAt(new DateTimeImmutable('2026-11-06 13:00:00 UTC'), 'UTC'))->toBeFalse();

    expect(NotificationPreferences::defaults()->isQuietAt(new DateTimeImmutable, 'UTC'))->toBeFalse();
});

it('still writes the notification inside quiet hours, and does not push it', function (): void {
    $setup = quietScenario();
    $window = quietWindowNow();

    saveQuietPreferences($setup['creative'], ['quiet_from' => $window['from'], 'quiet_until' => $window['until']])
        ->assertOk()
        ->assertJsonPath('data.quiet_from', $window['from'])
        ->assertJsonPath('data.quiet_until', $window['until']);

    assignQuietTask($setup);

    expect(DB::table('notifications')->where('user_id', $setup['creative']->getKey())->count())->toBe(1);
    expect($setup['broadcaster']->pushedTo)->toBe([]);
});

it('pushes as before outside quiet hours', function (): void {
    $setup = quietScenario();
    $window = quietWindowLater();

    saveQuietPreferences($setup['creative'], ['quiet_from' => $window['from'], 'quiet_until' => $window['until']])->assertOk();

    assignQuietTask($setup);

    expect($setup['broadcaster']->pushedTo)->toBe([(string) $setup['creative']->getKey()]);
});

it('lets a critical notification interrupt quiet hours', function (): void {
    $setup = quietScenario();
    $window = quietWindowNow();

    saveQuietPreferences($setup['creative'], ['quiet_from' => $window['from'], 'quiet_until' => $window['until']])->assertOk();
    $taskId = assignQuietTask($setup);

    expect($setup['broadcaster']->pushedTo)->toBe([]);

    /** @var list<NotificationView> $written */
    $written = app(Announce::class)(new NotificationDraft(
        kind: NotificationKind::TaskAssigned,
        title: 'Spend is running past the approved budget',
        subjectId: $taskId,
        onlyUserId: (string) $setup['creative']->getKey(),
        aboutProjectId: (string) $setup['project']->getKey(),
        aboutClientId: (string) $setup['client']->getKey(),
        severity: NotificationSeverity::Critical,
    ), (string) $setup['scenario']->workspace->getKey());

    expect($written)->toHaveCount(1);
    expect($setup['broadcaster']->pushedTo)->toBe([(string) $setup['creative']->getKey()]);
});

it('snoozes, says until when, and stops snoozing on request', function (): void {
    $setup = quietScenario();
    $creative = $setup['creative'];

    $until = test()->actingAs($creative)->postJson('/api/notifications/snooze', ['minutes' => 60])
        ->assertOk()->json('data.snoozed_until');

    expect($until)->toBeString();
    expect(strtotime((string) $until))->toBeGreaterThan(time() + 3500)->toBeLessThan(time() + 3700);

    assignQuietTask($setup);

    expect(DB::table('notifications')->where('user_id', $creative->getKey())->count())->toBe(1);
    expect($setup['broadcaster']->pushedTo)->toBe([]);

    // Saving preferences neither ends a snooze nor can set one.
    saveQuietPreferences($creative, ['snoozed_until' => '2099-01-01T00:00:00Z'])->assertOk()->assertJsonPath('data.snoozed_until', $until);

    test()->actingAs($creative)->deleteJson('/api/notifications/snooze')->assertOk()->assertJsonPath('data.snoozed_until', null);

    assignQuietTask($setup);

    expect($setup['broadcaster']->pushedTo)->toBe([(string) $creative->getKey()]);
});

it('treats a snooze that has passed as no snooze', function (): void {
    $setup = quietScenario();
    $creative = $setup['creative'];

    test()->actingAs($creative)->postJson('/api/notifications/snooze', ['minutes' => 60])->assertOk();
    DB::table('notification_preferences')->where('user_id', $creative->getKey())->update(['snoozed_until' => now()->subMinute()]);

    test()->actingAs($creative)->getJson('/api/notifications/preferences')->assertOk()->assertJsonPath('data.snoozed_until', null);

    assignQuietTask($setup);

    expect($setup['broadcaster']->pushedTo)->toBe([(string) $creative->getKey()]);
});

it('refuses half a window, a window of no length, and a snooze outside its bounds', function (): void {
    $setup = quietScenario();
    $creative = $setup['creative'];

    saveQuietPreferences($creative, ['quiet_from' => 22])->assertStatus(422)->assertJsonPath('code', 'invalid_quiet_hours');
    saveQuietPreferences($creative, ['quiet_from' => 8, 'quiet_until' => 8])->assertStatus(422)->assertJsonPath('code', 'invalid_quiet_hours');
    saveQuietPreferences($creative, ['quiet_from' => 24, 'quiet_until' => 7])->assertStatus(422);

    test()->actingAs($creative)->postJson('/api/notifications/snooze', ['minutes' => 5])->assertStatus(422)->assertJsonPath('code', 'invalid_snooze');
    test()->actingAs($creative)->postJson('/api/notifications/snooze', ['minutes' => 20000])->assertStatus(422)->assertJsonPath('code', 'invalid_snooze');

    expect(DB::table('notification_preferences')->where('user_id', $creative->getKey())->whereNotNull('snoozed_until')->count())->toBe(0);
});

it('keeps one person\'s quiet hours and snooze their own', function (): void {
    $setup = quietScenario();
    $owner = $setup['scenario']->owner;
    $window = quietWindowNow();

    // The owner is quiet and snoozed; the creative is neither, and is pushed to as usual.
    saveQuietPreferences($owner, ['quiet_from' => $window['from'], 'quiet_until' => $window['until']])->assertOk();
    test()->actingAs($owner)->postJson('/api/notifications/snooze', ['minutes' => 240])->assertOk();

    test()->actingAs($setup['creative'])->getJson('/api/notifications/preferences')->assertOk()
        ->assertJsonPath('data.quiet_from', null)
        ->assertJsonPath('data.snoozed_until', null);

    assignQuietTask($setup);

    expect($setup['broadcaster']->pushedTo)->toBe([(string) $setup['creative']->getKey()]);
});

it('holds the copy over another channel inside quiet hours, and says so in the delivery history', function (): void {
    $w = SyncedWorkspace::default();
    $owner = $w->scenario->owner;
    $window = quietWindowNow();

    test()->actingAs($owner)->putJson('/api/notifications/preferences', [
        'daily' => true, 'weekly' => true, 'monthly' => true, 'hour' => 0, 'timezone' => 'UTC',
        'channels' => ['in_app', 'email'], 'muted_categories' => [],
        'quiet_from' => $window['from'], 'quiet_until' => $window['until'],
    ])->assertOk();

    $this->artisan('digests:performance daily_performance')->assertSuccessful();

    $notification = (string) DB::table('notifications')->where('kind', 'performance_digest')->where('user_id', $owner->getKey())->value('id');
    $rows = DB::table('notification_deliveries')->where('notification_id', $notification)->get(['channel', 'status', 'detail']);

    expect($notification)->not->toBe('');
    expect($rows->pluck('channel')->all())->toBe(['email']);
    expect($rows->pluck('status')->all())->toBe(['held']);
    expect((string) $rows->pluck('detail')->first())->toContain('quiet hours');
});
