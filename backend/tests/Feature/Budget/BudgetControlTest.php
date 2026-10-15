<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SyncedWorkspace;

/**
 * The central budget surface, checked against the page it summarises.
 *
 * The control read is a second implementation of "how much has been spent against this budget" — in three
 * statements for the whole agency rather than several per budget. Two implementations of one figure is
 * exactly how a finance screen comes to disagree with the budget page it links to, so the first assertion
 * here is that they never do.
 */
/** @return array{0: SyncedWorkspace, 1: string, 2: App\Infrastructure\Persistence\Eloquent\Model\Project} */
function controlledBudget(): array
{
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $project = $workspace->project(Provider::Meta);

    $id = test()->actingAs($workspace->scenario->owner)->postJson('/api/budgets', [
        'client_id' => (string) $project->getAttribute('client_id'),
        'project_id' => (string) $project->getKey(),
        'name' => 'Synced period',
        'period_start' => now()->subDays(40)->format('Y-m-d'),
        'period_end' => now()->addDays(10)->format('Y-m-d'),
        'amount' => '50000.00',
        'currency' => 'SAR',
    ])->assertCreated()->json('data.id');

    test()->actingAs($workspace->scenario->owner)->postJson("/api/budgets/{$id}/approve")->assertOk();

    return [$workspace, $id, $project];
}

it('agrees with the budget’s own pacing page on spend and on status', function (): void {
    [$workspace, $id] = controlledBudget();
    $owner = $workspace->scenario->owner;

    $page = test()->actingAs($owner)->getJson("/api/budgets/{$id}/pacing")->assertOk()->json('data');
    $row = test()->actingAs($owner)->getJson('/api/budgets/control')->assertOk()->json('data.0');

    expect($row['id'])->toBe($id)
        ->and($row['spent_minor'])->toBe($page['spend']['minor_units'])
        ->and($row['pacing']['status'])->toBe($page['pacing']['status'])
        ->and($row['approved_minor'])->toBe(5_000_000);
});

it('flags money that has been approved and not allocated', function (): void {
    [$workspace] = controlledBudget();

    $row = test()->actingAs($workspace->scenario->owner)->getJson('/api/budgets/control?risk=unallocated')
        ->assertOk()->assertJsonCount(1, 'data')->json('data.0');

    expect($row['unallocated_minor'])->toBe(5_000_000)
        ->and(array_column($row['risks'], 'risk'))->toContain('unallocated');
});

it('totals per currency and never adds two currencies together', function (): void {
    [$workspace] = controlledBudget();

    $meta = test()->actingAs($workspace->scenario->owner)->getJson('/api/budgets/control')->json('meta');

    expect($meta['totals'])->toHaveCount(1)
        ->and($meta['totals'][0]['currency'])->toBe('SAR');
});

it('refuses a risk it does not know', function (): void {
    [$workspace] = controlledBudget();

    test()->actingAs($workspace->scenario->owner)->getJson('/api/budgets/control?risk=vibes')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonPath('code', 'invalid_risk');
});

it('shows a media buyer nothing for a project they are not on, and a creative nothing at all', function (): void {
    [$workspace] = controlledBudget();
    $buyer = $workspace->scenario->addMember(SystemRole::MediaBuyer);
    $creative = $workspace->scenario->addMember(SystemRole::Creative);

    test()->actingAs($buyer)->getJson('/api/budgets/control')->assertOk()->assertJsonCount(0, 'data');
    test()->actingAs($creative)->getJson('/api/budgets/control')->assertForbidden();
});
