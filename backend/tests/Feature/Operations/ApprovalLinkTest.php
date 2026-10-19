<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\WorkspaceScenario;

/*
 * One-time links to decide an approval from WhatsApp or email.
 *
 * The link is a shortcut to its person's own authority, read again at the moment it is used: it names one person and
 * one approval, expires, works once, never decides on a GET, and decides nothing once the proposal or the person's
 * permission has changed. Every refusal looks the same from outside.
 */

/** @return array{scenario: WorkspaceScenario, approval: array<string, mixed>} */
function linkWorld(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();

    $approval = test()->actingAs($scenario->owner)->postJson('/api/approvals', [
        'kind' => 'spend_change',
        'title' => 'Raise the Ramadan daily budget',
        'rationale' => 'Cost per purchase has held under target for nine days.',
        'proposal' => ['from_daily_minor' => 50_000, 'to_daily_minor' => 80_000, 'currency' => 'SAR'],
        'client_id' => (string) $client->getKey(),
        'project_id' => (string) $project->getKey(),
    ])->assertCreated()->json('data');

    return ['scenario' => $scenario, 'approval' => $approval];
}

/** Ask for a link as this person and return the token the channel would have carried. */
function issueLink(User $as, string $approvalId, string $channel = 'whatsapp', int $minutes = 30): string
{
    $url = null;
    Log::listen(static function ($event) use (&$url): void {
        if (isset($event->context['url'])) {
            $url = $event->context['url'];
        }
    });

    $response = test()->actingAs($as)->postJson("/api/approvals/{$approvalId}/links", ['channel' => $channel, 'minutes' => $minutes])->assertCreated();

    // The response never carries the link: only the channel sees it.
    expect(json_encode($response->json()))->not->toContain('/approve/')
        ->and($response->json('data.delivery'))->toBe('logged');

    app('auth')->forgetGuards();

    return basename((string) $url);
}

/** @return Illuminate\Testing\TestResponse<Illuminate\Http\JsonResponse> */
function useLink(string $token, string $decision = 'approve'): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson("/api/approval-links/{$token}", ['decision' => $decision]);
}

it('decides once, as its own person, and keeps only a keyed hash of the secret', function (): void {
    ['scenario' => $s, 'approval' => $a] = linkWorld();
    $token = issueLink($s->owner, $a['id']);

    expect(DB::table('approval_links')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(DB::table('approval_links')->where('token_hash', hash('sha256', $token))->exists())->toBeFalse();

    useLink($token)->assertOk()->assertJsonPath('data.state', 'approved');

    $row = DB::table('approval_requests')->where('id', $a['id'])->sole();
    expect($row->state)->toBe('approved')
        ->and($row->decided_by)->toBe($s->owner->getKey());

    // A second tap — or a forwarded copy — finds it used.
    useLink($token)->assertStatus(410);
    expect(DB::table('audit_logs')->where('action', 'approval.link_used')->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', 'approval.link_issued')->count())->toBe(1);
});

it('declines with a recorded reason', function (): void {
    ['scenario' => $s, 'approval' => $a] = linkWorld();

    useLink(issueLink($s->owner, $a['id'], 'email'), 'reject')->assertOk()->assertJsonPath('data.state', 'declined');
    expect(DB::table('approval_requests')->where('id', $a['id'])->value('decision_reason'))->toContain('one-time email link');
});

it('decides nothing after it expires', function (): void {
    ['scenario' => $s, 'approval' => $a] = linkWorld();
    $token = issueLink($s->owner, $a['id'], minutes: 5);

    // Six minutes on: the product's clock is its own, so the link is aged rather than the test's clock moved.
    DB::table('approval_links')->update(['created_at' => now()->subMinutes(6), 'expires_at' => now()->subMinute()]);

    useLink($token)->assertStatus(410);
    expect(DB::table('approval_requests')->where('id', $a['id'])->value('state'))->toBe('pending');
});

it('decides nothing once its person can no longer decide', function (): void {
    ['scenario' => $s, 'approval' => $a] = linkWorld();
    $admin = $s->addMember(SystemRole::Admin);
    $token = issueLink($admin, $a['id']);

    DB::table('workspace_members')->where('user_id', $admin->getKey())->update(['role_id' => $s->role(SystemRole::MediaBuyer)->getKey()]);

    useLink($token)->assertStatus(410);
    expect(DB::table('approval_requests')->where('id', $a['id'])->value('state'))->toBe('pending')
        ->and(DB::table('audit_logs')->where('action', 'approval.link_used')->where('summary', 'like', '%refused_permission%')->exists())->toBeTrue();
});

it('decides nothing once the proposal has changed', function (): void {
    ['scenario' => $s, 'approval' => $a] = linkWorld();
    $token = issueLink($s->owner, $a['id']);

    DB::table('approval_requests')->where('id', $a['id'])->update(['proposal_fingerprint' => str_repeat('b', 64)]);

    useLink($token)->assertStatus(410);
    expect(DB::table('approval_requests')->where('id', $a['id'])->value('state'))->toBe('pending');
});

it('never decides on a GET, and answers an unknown or malformed token the same way as a used one', function (): void {
    ['scenario' => $s, 'approval' => $a] = linkWorld();
    $token = issueLink($s->owner, $a['id']);

    test()->getJson("/api/approval-links/{$token}")->assertStatus(405);
    useLink(str_repeat('A', 43))->assertStatus(410)->assertJsonPath('message', 'This link cannot be used. Open the approval in digitalSmart instead.');
    test()->postJson('/api/approval-links/short', ['decision' => 'approve'])->assertNotFound();

    expect(DB::table('approval_requests')->where('id', $a['id'])->value('state'))->toBe('pending');
});

it('is only ever issued to someone who could decide it themselves', function (): void {
    ['scenario' => $s, 'approval' => $a] = linkWorld();
    $buyer = $s->addMember(SystemRole::MediaBuyer);

    test()->actingAs($buyer)->postJson("/api/approvals/{$a['id']}/links", ['channel' => 'whatsapp'])->assertForbidden();
    expect(DB::table('approval_links')->count())->toBe(0);
});
