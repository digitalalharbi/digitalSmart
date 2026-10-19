<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/**
 * The agents propose; they never decide, never change a platform, and never judge a project that has no target.
 */
function agentWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
}

it('judges nothing without a target, and says how many it left alone', function (): void {
    $w = agentWorld();

    test()->artisan('agents:run', ['which' => 'analyst', '--workspace' => $w->scenario->workspace->getKey()])->assertSuccessful();

    $run = DB::table('agent_runs')->where('agent', 'analyst')->sole();
    $summary = json_decode((string) $run->summary, true);

    expect($run->status)->toBe('completed')
        ->and($summary['findings'])->toBe(0)
        ->and($summary['without_target'])->toBeGreaterThan(0)
        ->and(DB::table('approval_requests')->where('origin', 'agent')->count())->toBe(0);
});

it('proposes against the project’s own target, with the figures and where the target came from', function (): void {
    $w = agentWorld();
    $project = $w->project(Provider::Meta);
    $client = (string) $project->getAttribute('client_id');

    // A cost target so low every campaign with a cost per result is far past it.
    test()->actingAs($w->scenario->owner)->putJson('/api/operating-rules', ['client_id' => $client, 'target_cpa_minor' => 1])->assertNoContent();
    $pausedBefore = Campaign::query()->where('status', 'paused')->count();

    test()->artisan('agents:run', ['which' => 'analyst', '--workspace' => $w->scenario->workspace->getKey()])->assertSuccessful();

    $proposals = DB::table('approval_requests')->where('origin', 'agent')->get();

    expect($proposals)->not->toBeEmpty();

    foreach ($proposals as $p) {
        $evidence = json_decode((string) $p->evidence, true);

        expect($p->agent)->toBe('campaign')
            ->and($p->state)->toBe('pending')
            ->and($p->expires_at)->not->toBeNull()
            ->and($p->risk)->toBeIn(['low', 'medium', 'high']);

        // Whichever tool it used, the evidence is the product's own figures, not prose.
        expect(array_keys($evidence))->not->toBeEmpty();
    }

    // The pause or budget on the platform has not moved: a proposal is only a request.
    expect(Campaign::query()->where('status', 'paused')->count())->toBe($pausedBefore);
});

it('seats the agent as its own member whose role can ask for approvals and cannot decide or carry out anything', function (): void {
    $w = agentWorld();
    test()->artisan('agents:run', ['which' => 'finance', '--workspace' => $w->scenario->workspace->getKey()])->assertSuccessful();

    $agent = User::query()->where('email', 'like', 'agent+%')->sole();
    $permissions = DB::table('workspace_members as m')
        ->join('permission_role as rp', 'rp.role_id', '=', 'm.role_id')
        ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
        ->where('m.user_id', $agent->getKey())
        ->pluck('p.slug')->all();

    expect($permissions)->toContain('approvals.request', 'tasks.manage')
        ->and($permissions)->not->toContain('approvals.decide', 'provider_writes.execute', 'budgets.approve', 'budgets.manage', 'roles.manage');

    // And nobody can sign in as it: it has no password anyone holds.
    test()->postJson('/api/auth/login', ['email' => $agent->email, 'password' => 'password'])->assertStatus(422);
});

it('lets a person decide what an agent proposed, with the proposal and its evidence on the row', function (): void {
    $w = agentWorld();
    $client = (string) $w->project(Provider::Meta)->getAttribute('client_id');
    test()->actingAs($w->scenario->owner)->putJson('/api/operating-rules', ['client_id' => $client, 'target_cpa_minor' => 1])->assertNoContent();
    test()->artisan('agents:run', ['which' => 'analyst', '--workspace' => $w->scenario->workspace->getKey()])->assertSuccessful();

    $queue = test()->actingAs($w->scenario->owner)->getJson('/api/approvals/queue')->assertOk()->json('data.pending');
    $mine = array_values(array_filter($queue, static fn (array $row): bool => $row['origin'] === 'agent'));

    expect($mine)->not->toBeEmpty()
        ->and($mine[0])->toHaveKeys(['agent', 'evidence', 'risk', 'expires_at', 'proposal', 'fingerprint']);

    test()->actingAs($w->scenario->owner)->postJson("/api/approvals/{$mine[0]['id']}/decision", [
        'outcome' => 'declined',
        'fingerprint' => $mine[0]['fingerprint'],
        'reason' => 'Not now.',
    ])->assertOk();
});

it('lets a proposal lapse once the figures it rests on are old', function (): void {
    $w = agentWorld();
    $client = (string) $w->project(Provider::Meta)->getAttribute('client_id');
    test()->actingAs($w->scenario->owner)->putJson('/api/operating-rules', ['client_id' => $client, 'target_cpa_minor' => 1])->assertNoContent();
    test()->artisan('agents:run', ['which' => 'analyst', '--workspace' => $w->scenario->workspace->getKey()])->assertSuccessful();

    DB::table('approval_requests')->where('origin', 'agent')->update(['expires_at' => now()->subHour()]);

    test()->artisan('agents:run', ['which' => 'finance', '--workspace' => $w->scenario->workspace->getKey()])->assertSuccessful();

    expect(DB::table('approval_requests')->where('origin', 'agent')->where('state', 'pending')->count())->toBe(0)
        ->and((string) DB::table('approval_requests')->where('origin', 'agent')->value('withdrawn_reason'))->toContain('Expired');
});

it('keeps one workspace’s agents out of another’s data', function (): void {
    $a = agentWorld();
    $b = agentWorld();
    $client = (string) $a->project(Provider::Meta)->getAttribute('client_id');
    test()->actingAs($a->scenario->owner)->putJson('/api/operating-rules', ['client_id' => $client, 'target_cpa_minor' => 1])->assertNoContent();

    test()->artisan('agents:run', ['which' => 'analyst', '--workspace' => $a->scenario->workspace->getKey()])->assertSuccessful();

    expect(DB::table('approval_requests')->where('workspace_id', $b->scenario->workspace->getKey())->where('origin', 'agent')->count())->toBe(0)
        ->and(DB::table('agent_runs')->where('workspace_id', $b->scenario->workspace->getKey())->count())->toBe(0);
});

it('shows administrators the machinery, runs the agents on demand, and shows it to nobody else', function (): void {
    $w = agentWorld();
    $manager = $w->scenario->addMember(App\Domain\Access\SystemRole::ProjectManager);

    test()->actingAs($w->scenario->owner)->postJson('/api/agents/run')->assertOk();

    $data = test()->actingAs($w->scenario->owner)->getJson('/api/agents/overview')->assertOk()->json('data');

    expect($data['runs'])->not->toBeEmpty()
        ->and(array_column($data['tools'], 'name'))->toContain('propose_budget_change')
        ->and(array_column($data['providers'], 'provider'))->toContain('meta')
        ->and($data['mcp_endpoint'])->toEndWith('/api/mcp');

    // The administrator's own session is intact after the agents ran as themselves.
    test()->actingAs($w->scenario->owner)->getJson('/api/portfolio')->assertOk();

    test()->actingAs($manager)->getJson('/api/agents/overview')->assertForbidden();
    test()->actingAs($manager)->postJson('/api/agents/run')->assertForbidden();
});

it('shows every agent as a worker — role, last run, success rate, open recommendations — and every routine with its next run', function (): void {
    $w = agentWorld();
    test()->artisan('agents:run', ['--workspace' => $w->scenario->workspace->getKey()])->assertSuccessful();

    $status = test()->actingAs($w->scenario->owner)->getJson('/api/agents/status')->assertOk()->json('data');
    $agents = array_column($status['agents'], null, 'agent');
    $routines = array_column($status['routines'], null, 'routine');

    expect(array_keys($agents))->toBe(['analyst', 'campaign', 'finance', 'creative', 'leads', 'reports', 'operations'])
        ->and($agents['finance']['last_run'])->not->toBeNull()
        ->and($agents['finance']['success_rate'])->toBe(100)
        ->and($routines['weekly_briefs']['schedule'])->toBe(['at' => '14:00', 'days' => [4], 'month_day' => null, 'timezone' => 'Asia/Riyadh'])
        ->and(array_keys($routines))->toContain('tracking_review', 'monthly_executive')
        ->and((new DateTimeImmutable($routines['monthly_executive']['next_run']))->setTimezone(new DateTimeZone('Asia/Riyadh'))->format('j H:i'))->toBe('1 11:00')
        // Thursday 14:00 in Riyadh, whatever day it is now.
        ->and((new DateTimeImmutable($routines['weekly_briefs']['next_run']))->setTimezone(new DateTimeZone('Asia/Riyadh'))->format('w H:i'))->toBe('4 14:00')
        ->and(new DateTimeImmutable($routines['rules']['next_run']))->toBeGreaterThan(new DateTimeImmutable('-1 minute'));

    // Not an administrator's screen: a media buyer reads it too.
    test()->actingAs($w->scenario->addMember(App\Domain\Access\SystemRole::MediaBuyer))->getJson('/api/agents/status')->assertOk();
});
