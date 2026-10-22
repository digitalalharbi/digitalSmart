<?php

declare(strict_types=1);

use App\Application\Digest\Command\BuildPerformanceDigests;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\UserId;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Workspace\WorkspaceId;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| The notification centre
|--------------------------------------------------------------------------
|
| Tabs are a stored column, counts agree with the list beneath them, every history state is the owner's own, and
| a preference can switch things off but never widen what somebody is shown.
|
*/

/** @return array{w: SyncedWorkspace, campaign: string, budget: ?string} */
function centreWorld(): array
{
    $w = SyncedWorkspace::default();
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);
    app(ActorContextBinder::class)->bind(new UserId((string) $buyer->getKey()), new WorkspaceId($w->workspaceId()));

    return [
        'w' => $w,
        'campaign' => (string) DB::table('campaigns')->where('provider', 'meta')->orderBy('id')->value('id'),
        'budget' => null,
    ];
}

function centreAlert(NotificationKind $kind, string $subject, NotificationSeverity $severity, string $key): void
{
    app(Announce::class)(new NotificationDraft(
        kind: $kind,
        title: $kind->label(),
        subjectId: $subject,
        severity: $severity,
        payload: ['before' => 3.8, 'after' => 2.9],
        dedupeKey: $key,
    ));
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function centreFind(array $rows, string $key, string $value): array
{
    foreach ($rows as $row) {
        if (($row[$key] ?? null) === $value) {
            return $row;
        }
    }

    throw new LogicException("No row with {$key} = {$value}.");
}

/** The first zone in which the local hour is at or past nine right now, so the clock itself is the test. */
function centreZoneWhere(bool $before): string
{
    foreach (['Pacific/Honolulu', 'America/Los_Angeles', 'UTC', 'Asia/Riyadh', 'Asia/Tokyo', 'Pacific/Auckland'] as $zone) {
        $hour = (int) (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('G');

        if (($hour < BuildPerformanceDigests::LOCAL_HOUR) === $before) {
            return $zone;
        }
    }

    throw new LogicException('No zone fits at this hour.');
}

/** @return array<string, mixed> */
function centreLatestDigest(SyncedWorkspace $w): array
{
    /** @var array<string, mixed> $row */
    $row = (array) DB::table('digests')->where(['workspace_id' => $w->workspaceId(), 'user_id' => $w->scenario->owner->getKey(), 'kind' => 'daily_performance'])->first();

    /** @var array<string, mixed> $summary */
    $summary = json_decode((string) ($row['summary'] ?? '{}'), true);

    return $summary;
}

/** @return array<string, mixed> */
function centreFeed(SyncedWorkspace $w, string $query = ''): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($w->scenario->owner)->getJson('/api/notifications'.($query === '' ? '' : "?{$query}"))->assertOk()->json('data');
}

describe('tabs and counts', function (): void {
    it('filters by category, and the counts above the list agree with it', function (): void {
        ['w' => $w, 'campaign' => $campaign] = centreWorld();
        centreAlert(NotificationKind::RoasDrop, $campaign, NotificationSeverity::Critical, 'roas:'.$campaign);
        centreAlert(NotificationKind::SpendSpike, $campaign, NotificationSeverity::Attention, 'spend:'.$campaign);
        centreAlert(NotificationKind::CampaignEnded, $campaign, NotificationSeverity::Info, 'ended:'.$campaign);

        $all = centreFeed($w);
        $performance = centreFeed($w, 'category=performance');
        $budget = centreFeed($w, 'category=budget');

        expect($performance['notifications'])->toHaveCount(3)
            ->and($budget['notifications'])->toHaveCount(0)
            ->and($all['digest']['unread_by_category']['performance'])->toBe(3)
            ->and($all['digest']['unread_by_severity'])->toMatchArray(['critical' => 1, 'attention' => 1, 'info' => 1])
            /* The one that merely ended asks nothing; the other two wait. */
            ->and($all['digest']['waiting'])->toBe(2);
    });

    it('publishes the tabs and the severities with the vocabulary', function (): void {
        $w = SyncedWorkspace::default();
        $vocabulary = test()->actingAs($w->scenario->owner)->getJson('/api/notifications/vocabulary')->assertOk()->json('data');

        expect(array_column($vocabulary['categories'], 'category'))->toContain('performance', 'budget', 'creative', 'tracking', 'approvals', 'reports')
            ->and(array_column($vocabulary['severities'], 'severity'))->toBe(['info', 'success', 'attention', 'critical'])
            ->and(centreFind($vocabulary['kinds'], 'kind', 'budget_near_limit')['category'])->toBe('budget');
    });
});

describe('history', function (): void {
    it('records a click as read too, and a dismissal takes the row out of the list and the counts but not the record', function (): void {
        ['w' => $w, 'campaign' => $campaign] = centreWorld();
        centreAlert(NotificationKind::RoasDrop, $campaign, NotificationSeverity::Critical, 'roas:'.$campaign);
        centreAlert(NotificationKind::SpendSpike, $campaign, NotificationSeverity::Attention, 'spend:'.$campaign);
        [$first, $second] = array_column(centreFeed($w)['notifications'], 'id');

        $afterClick = test()->actingAs($w->scenario->owner)->postJson("/api/notifications/{$first}/clicked")->assertOk()->json('data');
        $clicked = centreFind($afterClick['notifications'], 'id', $first);

        expect($clicked['clicked_at'])->not->toBeNull()
            ->and($clicked['read_at'])->not->toBeNull()
            ->and($clicked['is_unread'])->toBeFalse();

        $afterDismiss = test()->actingAs($w->scenario->owner)->postJson("/api/notifications/{$second}/dismiss")->assertOk()->json('data');

        expect(array_column($afterDismiss['notifications'], 'id'))->not->toContain($second)
            ->and($afterDismiss['digest']['total'])->toBe(1)
            ->and(DB::table('notifications')->where('id', $second)->value('dismissed_at'))->not->toBeNull();
    });

    it('answers not found for somebody else\'s notification, so a guess confirms nothing', function (): void {
        ['w' => $w, 'campaign' => $campaign] = centreWorld();
        centreAlert(NotificationKind::RoasDrop, $campaign, NotificationSeverity::Critical, 'roas:'.$campaign);
        $id = centreFeed($w)['notifications'][0]['id'];
        $other = $w->scenario->addMember(SystemRole::ProjectManager);

        test()->actingAs($other)->postJson("/api/notifications/{$id}/clicked")->assertNotFound();
        test()->actingAs($other)->postJson("/api/notifications/{$id}/dismiss")->assertNotFound();

        expect(DB::table('notifications')->where('id', $id)->value('dismissed_at'))->toBeNull();
    });
});

describe('preferences', function (): void {
    it('starts at the defaults, saves what a person asks for, and says which channels can deliver today', function (): void {
        $w = SyncedWorkspace::default();
        $owner = $w->scenario->owner;

        $defaults = test()->actingAs($owner)->getJson('/api/notifications/preferences')->assertOk()->json('data');

        expect($defaults)->toMatchArray(['daily' => true, 'weekly' => true, 'monthly' => true, 'hour' => 9, 'timezone' => null, 'channels' => ['in_app']])
            ->and(centreFind($defaults['channel_availability'], 'channel', 'whatsapp')['availability'])->toBe('awaiting_credentials')
            ->and(centreFind($defaults['channel_availability'], 'channel', 'in_app')['availability'])->toBe('available');

        $client = (string) $w->project(App\Domain\Integration\Provider::Meta)->client_id;
        $saved = test()->actingAs($owner)->putJson('/api/notifications/preferences', [
            'daily' => true, 'weekly' => false, 'monthly' => true,
            'channels' => ['in_app', 'email'],
            'timezone' => 'Asia/Riyadh', 'hour' => 7,
            'client_id' => $client, 'project_ids' => null,
            'muted_categories' => ['tracking'],
        ])->assertOk()->json('data');

        expect($saved['weekly'])->toBeFalse()
            ->and($saved['hour'])->toBe(7)
            ->and($saved['timezone'])->toBe('Asia/Riyadh')
            ->and($saved['client_id'])->toBe($client)
            ->and($saved['muted_categories'])->toBe(['tracking']);

        expect(test()->actingAs($owner)->getJson('/api/notifications/preferences')->json('data.hour'))->toBe(7);
    });

    it('refuses a zone PHP does not know, a client outside the workspace, and an hour off the clock', function (): void {
        $w = SyncedWorkspace::default();
        $base = ['daily' => true, 'weekly' => true, 'monthly' => true, 'channels' => ['in_app'], 'hour' => 9, 'muted_categories' => []];

        test()->actingAs($w->scenario->owner)->putJson('/api/notifications/preferences', ['hour' => 24] + $base)->assertUnprocessable();
        test()->actingAs($w->scenario->owner)->putJson('/api/notifications/preferences', ['timezone' => 'Mars/Olympus'] + $base)->assertUnprocessable();
        test()->actingAs($w->scenario->owner)->putJson('/api/notifications/preferences', ['client_id' => (string) Illuminate\Support\Str::uuid7()] + $base)->assertNotFound();
    });

    it('a muted tab means not being told, whatever the permission would have allowed', function (): void {
        ['w' => $w, 'campaign' => $campaign] = centreWorld();
        test()->actingAs($w->scenario->owner)->putJson('/api/notifications/preferences', [
            'daily' => true, 'weekly' => true, 'monthly' => true, 'channels' => ['in_app'], 'hour' => 9,
            'muted_categories' => ['performance'],
        ])->assertOk();

        centreAlert(NotificationKind::RoasDrop, $campaign, NotificationSeverity::Critical, 'roas:'.$campaign);

        expect(DB::table('notifications')->where('kind', 'roas_drop')->where('user_id', $w->scenario->owner->getKey())->count())->toBe(0);
    });

    it('a switched-off cadence, an unreached hour, or a muted performance tab means no digest for that person', function (): void {
        $w = SyncedWorkspace::default();
        $owner = $w->scenario->owner;
        $zone = centreZoneWhere(before: false);
        $localHour = (int) (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('G');

        $prefs = static fn (array $over): array => $over + ['daily' => true, 'weekly' => true, 'monthly' => true, 'channels' => ['in_app'], 'hour' => 9, 'timezone' => $zone, 'muted_categories' => []];

        test()->actingAs($owner)->putJson('/api/notifications/preferences', $prefs(['daily' => false]))->assertOk();
        $this->artisan('digests:performance')->assertSuccessful();
        expect(DB::table('digests')->where('kind', 'daily_performance')->count())->toBe(0);

        test()->actingAs($owner)->putJson('/api/notifications/preferences', $prefs(['daily' => true, 'hour' => min(23, $localHour + 1)]))->assertOk();
        $this->artisan('digests:performance')->assertSuccessful();
        expect(DB::table('digests')->where('kind', 'daily_performance')->count())->toBe($localHour === 23 ? 1 : 0);

        test()->actingAs($owner)->putJson('/api/notifications/preferences', $prefs(['hour' => $localHour, 'muted_categories' => ['performance']]))->assertOk();
        $this->artisan('digests:performance')->assertSuccessful();
        expect(DB::table('digests')->where('kind', 'daily_performance')->count())->toBe($localHour === 23 ? 1 : 0);

        test()->actingAs($owner)->putJson('/api/notifications/preferences', $prefs(['hour' => $localHour]))->assertOk();
        $this->artisan('digests:performance')->assertSuccessful();
        expect(DB::table('digests')->where('kind', 'daily_performance')->where('user_id', $owner->getKey())->count())->toBe(1);
    });

    it('a client scope narrows the digest to that client\'s platforms and never widens it', function (): void {
        $w = SyncedWorkspace::default();
        $owner = $w->scenario->owner;
        $client = (string) $w->project(App\Domain\Integration\Provider::GoogleAds)->client_id;

        test()->actingAs($owner)->putJson('/api/notifications/preferences', [
            'daily' => true, 'weekly' => true, 'monthly' => true, 'channels' => ['in_app'], 'hour' => 9, 'muted_categories' => [],
            'client_id' => $client,
        ])->assertOk();
        $this->artisan('digests:performance daily_performance')->assertSuccessful();

        $digest = centreLatestDigest($w);

        expect(array_column($digest['platforms'], 'key'))->toBe(['google_ads']);
    });
});
