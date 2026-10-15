<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use App\Interface\Queue\SyncBoundAccountJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\SyncedWorkspace;

/** @return array{0: SyncedWorkspace, 1: string} */
function backfillWorld(): array
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $account = (string) DB::table('project_integration_bindings')->value('external_account_id');
    RateLimiter::clear('backfill:'.$account);

    return [$w, $account];
}

it('queues a chosen window on the backfill queue, the lowest priority there is', function (): void {
    Queue::fake();
    [$w, $account] = backfillWorld();

    $data = test()->actingAs($w->scenario->owner)
        ->postJson("/api/integrations/accounts/{$account}/backfill", ['days' => 90])
        ->assertStatus(202)->json('data');

    expect($data['days'])->toBe(90)->and($data['queue'])->toBe('sync-backfill');
    Queue::assertPushedOn('sync-backfill', SyncBoundAccountJob::class);
});

it('offers a short list of windows, not a free field', function (): void {
    Queue::fake();
    [$w, $account] = backfillWorld();

    test()->actingAs($w->scenario->owner)->postJson("/api/integrations/accounts/{$account}/backfill", ['days' => 400])->assertStatus(422);
    test()->actingAs($w->scenario->owner)->postJson("/api/integrations/accounts/{$account}/backfill", ['days' => 7])->assertStatus(422);
    Queue::assertNothingPushed();
});

it('asks for the same account at most once in six hours', function (): void {
    Queue::fake();
    [$w, $account] = backfillWorld();

    test()->actingAs($w->scenario->owner)->postJson("/api/integrations/accounts/{$account}/backfill", ['days' => 30])->assertStatus(202);
    test()->actingAs($w->scenario->owner)->postJson("/api/integrations/accounts/{$account}/backfill", ['days' => 30])
        ->assertStatus(429)->assertJsonPath('code', 'backfill_recently_requested');
});

it('will not read the history of an account no project has bound', function (): void {
    Queue::fake();
    [$w] = backfillWorld();

    test()->actingAs($w->scenario->owner)
        ->postJson('/api/integrations/accounts/'.Illuminate\Support\Str::uuid().'/backfill', ['days' => 30])
        ->assertNotFound();
    Queue::assertNothingPushed();
});

it('is an administrator’s decision, not a media buyer’s', function (): void {
    Queue::fake();
    [$w, $account] = backfillWorld();
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);

    test()->actingAs($buyer)->postJson("/api/integrations/accounts/{$account}/backfill", ['days' => 30])->assertForbidden();
    Queue::assertNothingPushed();
});
