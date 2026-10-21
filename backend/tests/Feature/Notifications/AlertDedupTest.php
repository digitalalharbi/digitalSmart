<?php

declare(strict_types=1);

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
| No spam
|--------------------------------------------------------------------------
|
| "ROAS low" is said once per cooldown, again when it got worse, and again once the cooldown has passed. The
| key is the alert's own — kind and campaign — so two different campaigns dropping are two notifications and one
| campaign dropping twice in a day is one.
|
*/

/** @return array{w: SyncedWorkspace, campaign: string} */
function alertWorld(): array
{
    $w = SyncedWorkspace::default();
    /* The buyer is on no project and is the one announcing, so the owner — who sees everything — is the recipient. */
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);
    app(ActorContextBinder::class)->bind(new UserId((string) $buyer->getKey()), new WorkspaceId($w->workspaceId()));

    return ['w' => $w, 'campaign' => (string) DB::table('campaigns')->where('provider', 'meta')->orderBy('id')->value('id')];
}

function roasDrop(string $campaign, NotificationSeverity $severity): NotificationDraft
{
    return new NotificationDraft(
        kind: NotificationKind::RoasDrop,
        title: 'ROAS dropped on Eid film',
        subjectId: $campaign,
        severity: $severity,
        payload: ['before' => 3.8, 'after' => 2.9, 'change_ratio' => -0.24],
        dedupeKey: 'roas_drop:'.$campaign,
    );
}

it('says a repeat inside the cooldown once, and again only when it got worse', function (): void {
    ['w' => $w, 'campaign' => $campaign] = alertWorld();
    $announce = app(Announce::class);

    expect($announce(roasDrop($campaign, NotificationSeverity::Attention)))->toHaveCount(1)
        ->and($announce(roasDrop($campaign, NotificationSeverity::Attention)))->toBe([])
        ->and($announce(roasDrop($campaign, NotificationSeverity::Info)))->toBe([])
        ->and($announce(roasDrop($campaign, NotificationSeverity::Critical)))->toHaveCount(1)
        ->and(DB::table('notifications')->where('kind', 'roas_drop')->count())->toBe(2);

    $feed = test()->actingAs($w->scenario->owner)->getJson('/api/notifications')->assertOk()->json('data.notifications');
    $alerts = array_values(array_filter($feed, static fn (array $n): bool => $n['kind'] === 'roas_drop'));

    $critical = array_values(array_filter($alerts, static fn (array $n): bool => $n['severity'] === 'critical'))[0] ?? null;

    expect(array_column($alerts, 'severity'))->toEqualCanonicalizing(['attention', 'critical'])
        ->and($critical)->not->toBeNull()
        ->and($critical['category'])->toBe('performance')
        ->and($critical['payload']['after'])->toBe(2.9)
        ->and($critical['subject']['type'])->toBe('campaign')
        ->and($critical['subject']['path'])->toBe('/campaigns')
        ->and($critical['is_waiting'])->toBeTrue();
});

it('says it again once the cooldown has passed', function (): void {
    ['campaign' => $campaign] = alertWorld();
    $announce = app(Announce::class);

    expect($announce(roasDrop($campaign, NotificationSeverity::Attention)))->toHaveCount(1);
    DB::table('notifications')->where('kind', 'roas_drop')->update(['created_at' => now()->subHours(25)]);

    expect($announce(roasDrop($campaign, NotificationSeverity::Attention)))->toHaveCount(1)
        ->and(DB::table('notifications')->where('kind', 'roas_drop')->count())->toBe(2);
});

it('keeps two campaigns apart: a different key is a different thing to say', function (): void {
    ['campaign' => $campaign] = alertWorld();
    $other = (string) DB::table('campaigns')->where('provider', 'google_ads')->orderBy('id')->value('id');
    $announce = app(Announce::class);

    expect($announce(roasDrop($campaign, NotificationSeverity::Attention)))->toHaveCount(1)
        ->and($announce(roasDrop($other, NotificationSeverity::Attention)))->toHaveCount(1);
});

it('tells nobody who could not have read the campaign', function (): void {
    ['w' => $w, 'campaign' => $campaign] = alertWorld();
    $creative = $w->scenario->addMember(SystemRole::Creative);

    app(Announce::class)(roasDrop($campaign, NotificationSeverity::Critical));

    expect(DB::table('notifications')->where('kind', 'roas_drop')->where('user_id', $creative->getKey())->count())->toBe(0);
});
