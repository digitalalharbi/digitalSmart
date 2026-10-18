<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The approval queue
|--------------------------------------------------------------------------
|
| What is tested is mostly what this module refuses to let somebody believe.
|
| **Approving does not make the change.** Every request says so, and an approved one sits in the outstanding
| queue until a person records having made it. A module that cleared the queue on approval would have an agency
| telling a client a campaign was stopped while it kept spending.
|
| **A decision names the proposal it read.** Amend a pending proposal and a decision sent with the old
| fingerprint is refused with 409 and the proposal as it stands.
|
| **Asking and deciding are separate.** A media buyer may ask and may not decide. A buyer who could approve
| their own spend change has an approvals screen rather than an approvals process.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function approvalScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * Raise a request and return its body.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function askApproval(array $setup, array $overrides = [], ?User $as = null): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['scenario']->owner)
        ->postJson('/api/approvals', [
            'kind' => 'spend_change',
            'title' => 'Raise the Ramadan daily budget',
            'rationale' => 'Cost per purchase has held under target for nine days.',
            'proposal' => ['from_daily_minor' => 50_000, 'to_daily_minor' => 80_000, 'currency' => 'SAR'],
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function readQueue(array $setup, array $query = []): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($setup['scenario']->owner)
        ->getJson('/api/approvals/queue'.($query === [] ? '' : '?'.http_build_query($query)))
        ->assertOk()
        ->json('data');
}

describe('asking', function (): void {
    it('refuses a request with no rationale', function (): void {
        $setup = approvalScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/approvals', [
                'kind' => 'spend_change',
                'title' => 'Raise the budget',
                'rationale' => '  ',
                'proposal' => ['to' => 1],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'rationale_required');
    });

    it('refuses a request with nothing in the proposal', function (): void {
        $setup = approvalScenario();

        /*
         * "Approve this" with no content is a signature on a blank page, and the decision would be unquotable
         * the moment anybody disagreed about what was agreed.
         */
        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/approvals', [
                'kind' => 'spend_change',
                'title' => 'Raise the budget',
                'rationale' => 'Because it is working.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'proposal_required');
    });

    it('starts pending, with nothing to carry out', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        expect($data['state'])->toBe('pending');
        // Enactment is meaningless until a decision, and a CHECK enforces it.
        expect($data['enactment'])->toBe('not_required');
        expect($data['fingerprint'])->not->toBeEmpty();
    });

    it('says on the request that approving it will not make the change', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        /*
         * The sentence this module exists for, on the row rather than in documentation. A reader scanning the
         * queue has to see it without opening anything.
         */
        expect($data['is_enacted_here'])->toBeFalse();
        expect($data['enactment_blocked_because'])->toContain('changes no live budget or bid by itself');
        expect($data['touches_live_spend'])->toBeTrue();
    });

    it('records who asked and whether it moves a client\'s money', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        $entry = AuditLog::query()
            ->where('action', AuditAction::ApprovalRequested->value)
            ->where('auditable_id', $data['id'])
            ->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes;

        // The question an audit of this module is for, answered on the entry rather than by a later join.
        expect($changes['touches_live_spend'])->toBeTrue();
        expect($changes['fingerprint'])->toBe($data['fingerprint']);
    });
});

describe('deciding', function (): void {
    it('refuses a decision that does not say which proposal it read', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/decision", ['outcome' => 'approved'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'proposal_changed');
    });

    it('refuses a decision on a proposal that has since been amended, and answers with the new one', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);
        $stale = $data['fingerprint'];

        test()->actingAs($setup['scenario']->owner)
            ->patchJson("/api/approvals/{$data['id']}", [
                'proposal' => ['from_daily_minor' => 50_000, 'to_daily_minor' => 120_000, 'currency' => 'SAR'],
            ])
            ->assertOk();

        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'approved',
                'fingerprint' => $stale,
            ])
            ->assertStatus(409);

        /*
         * 409 rather than 422: the request was correct when its sender read the screen, and only a re-read
         * resolves it. The new proposal comes back so the interface can show what changed.
         */
        $response->assertJsonPath('sent_fingerprint', $stale);
        expect($response->json('data.proposal.to_daily_minor'))->toBe(120_000);
        expect($response->json('data.state'))->toBe('pending');
    });

    it('approves, and leaves the change waiting on a person', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        $decided = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'approved',
                'fingerprint' => $data['fingerprint'],
            ])
            ->assertOk()
            ->json('data');

        expect($decided['state'])->toBe('approved');
        /*
         * The one assertion in this file that matters most. Approving did not pause, launch or change anything,
         * and the record says the change is still waiting on somebody.
         */
        expect($decided['enactment'])->toBe('awaiting_hand');
        expect($decided['enacted_at'])->toBeNull();
    });

    it('refuses a decline with no reason', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'declined',
                'fingerprint' => $data['fingerprint'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'reason_required');
    });

    it('refuses to re-decide something settled', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'approved',
                'fingerprint' => $data['fingerprint'],
            ])
            ->assertOk();

        /*
         * A trail in which one request was both declined and approved cannot say which decision the client was
         * told about. The refusal names the alternative.
         */
        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'declined',
                'fingerprint' => $data['fingerprint'],
                'reason' => 'Changed my mind.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_settled');
    });

    it('refuses to amend a decided proposal', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'approved',
                'fingerprint' => $data['fingerprint'],
            ])
            ->assertOk();

        test()->actingAs($setup['scenario']->owner)
            ->patchJson("/api/approvals/{$data['id']}", ['proposal' => ['to_daily_minor' => 999]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_settled');
    });

    it('records the fingerprint on the decision entry', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'approved',
                'fingerprint' => $data['fingerprint'],
            ])
            ->assertOk();

        $entry = AuditLog::query()
            ->where('action', AuditAction::ApprovalDecided->value)
            ->where('auditable_id', $data['id'])
            ->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes;

        /*
         * What makes "I approved the version that said 500, not 800" a settled question rather than an
         * argument.
         */
        expect($changes['fingerprint'])->toBe($data['fingerprint']);
        expect($changes['enacted_by_product'])->toBeFalse();
    });
});

describe('carrying the change out', function (): void {
    it('refuses to mark an undecided change as carried out', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/enactment", ['enactment' => 'confirmed'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_approved');
    });

    it('records who made the change and when, with no claim that the product checked', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)->postJson("/api/approvals/{$data['id']}/decision", [
            'outcome' => 'approved',
            'fingerprint' => $data['fingerprint'],
        ])->assertOk();

        $enacted = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/enactment", [
                'enactment' => 'confirmed',
                'note' => 'Raised in the platform at 10:40.',
            ])
            ->assertOk()
            ->json('data');

        expect($enacted['enactment'])->toBe('confirmed');
        expect($enacted['enacted_at'])->not->toBeNull();

        $entry = AuditLog::query()
            ->where('action', AuditAction::ApprovalEnacted->value)
            ->where('auditable_id', $data['id'])
            ->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes;

        /*
         * Stated on the entry. An audit of live spend changes has to be able to tell somebody's word from a
         * reading taken off the platform, and this product only has the former.
         */
        expect($changes['verified_by_product'])->toBeFalse();
    });

    it('refuses to abandon a change without saying why', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)->postJson("/api/approvals/{$data['id']}/decision", [
            'outcome' => 'approved',
            'fingerprint' => $data['fingerprint'],
        ])->assertOk();

        // Not carrying out an approved change is a second decision, and the one a client asks about.
        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/enactment", ['enactment' => 'abandoned'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'reason_required');
    });

    it('refuses to record it twice', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)->postJson("/api/approvals/{$data['id']}/decision", [
            'outcome' => 'approved',
            'fingerprint' => $data['fingerprint'],
        ])->assertOk();

        test()->actingAs($setup['scenario']->owner)->postJson("/api/approvals/{$data['id']}/enactment", [
            'enactment' => 'confirmed',
        ])->assertOk();

        // Recording it again would overwrite who did it and when.
        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/enactment", ['enactment' => 'confirmed'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'enactment_settled');
    });

    it('will not accept a state a person cannot assert', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        /*
         * `awaiting_hand` is the module's own state. Letting a caller write it would be a way to clear the
         * outstanding queue without anybody doing anything.
         */
        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$data['id']}/enactment", ['enactment' => 'awaiting_hand'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_enactment');
    });
});

describe('the queue', function (): void {
    it('counts approved work nobody has carried out, separately from the decisions', function (): void {
        $setup = approvalScenario();
        $waiting = askApproval($setup);
        $done = askApproval($setup, ['title' => 'Pause the retargeting set', 'kind' => 'campaign_pause']);

        foreach ([$waiting, $done] as $request) {
            test()->actingAs($setup['scenario']->owner)
                ->postJson("/api/approvals/{$request['id']}/decision", [
                    'outcome' => 'approved',
                    'fingerprint' => $request['fingerprint'],
                ])->assertOk();
        }

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$done['id']}/enactment", ['enactment' => 'confirmed'])
            ->assertOk();

        $queue = readQueue($setup);

        expect($queue['load']['approved'])->toBe(2);
        // The figure this module exists for.
        expect($queue['load']['outstanding'])->toBe(1);
        expect($queue['load']['confirmed'])->toBe(1);
        expect($queue['outstanding'])->toHaveCount(1);
        expect($queue['load']['needs_attention'])->toBeTrue();
    });

    it('ages an outstanding change from the decision, not from the request', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);

        test()->actingAs($setup['scenario']->owner)->postJson("/api/approvals/{$data['id']}/decision", [
            'outcome' => 'approved',
            'fingerprint' => $data['fingerprint'],
        ])->assertOk();

        DB::table('approval_requests')->where('id', $data['id'])->update([
            'created_at' => now()->subDays(30),
            'decided_at' => now()->subDays(4),
        ]);

        $queue = readQueue($setup);

        /*
         * Four days, not thirty. The question once a change is approved is how long it has gone unmade, and
         * measuring from the request would flatter a slow decision followed by prompt action.
         */
        expect($queue['load']['oldest_outstanding_days'])->toBe(4);
        expect($queue['outstanding'][0]['waiting_days'])->toBe(4);
        expect($queue['outstanding'][0]['age'])->toBe('week');
    });

    it('lists what is waiting for a decision oldest first', function (): void {
        $setup = approvalScenario();
        $old = askApproval($setup, ['title' => 'Asked last week']);
        askApproval($setup, ['title' => 'Asked today']);

        DB::table('approval_requests')->where('id', $old['id'])->update([
            'created_at' => now()->subDays(6),
        ]);

        $queue = readQueue($setup);

        // A queue sorted newest-first hides the row somebody most needs to see.
        expect($queue['pending'][0]['title'])->toBe('Asked last week');
        expect($queue['load']['oldest_pending_days'])->toBe(6);
    });

    it('has no rates until there is something to divide', function (): void {
        $setup = approvalScenario();
        askApproval($setup);

        $queue = readQueue($setup);

        expect($queue['load']['approval_rate'])->toBeNull();
        expect($queue['load']['carried_out_rate'])->toBeNull();
    });

    it('narrows to the requests that move a client\'s money', function (): void {
        $setup = approvalScenario();
        askApproval($setup);
        askApproval($setup, [
            'kind' => 'tracking_change',
            'title' => 'Count the newsletter sign-up',
            'proposal' => ['conversion' => 'newsletter_signup'],
        ]);

        $queue = readQueue($setup, ['spend_only' => '1']);

        expect($queue['load']['pending'])->toBe(1);
        expect($queue['load']['pending_touching_spend'])->toBe(1);
    });
});

describe('who may do what', function (): void {
    it('lets a media buyer ask and refuses to let them decide', function (): void {
        $setup = approvalScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        $data = askApproval($setup, [], $buyer);

        /*
         * The separation the module exists for. A buyer who could approve their own change to a client's spend
         * removes the only check on it.
         */
        test()->actingAs($buyer)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'approved',
                'fingerprint' => $data['fingerprint'],
            ])
            ->assertForbidden();
    });

    it('requires a budget approver for a spend change even with approvals.decide', function (): void {
        $setup = approvalScenario();
        $data = askApproval($setup);
        $creative = $setup['scenario']->addMember(SystemRole::Creative);

        // On the project, or the request is simply invisible to them and the 403 never gets a chance to fire.
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $creative->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::Creative)->getKey(),
        ]);

        /*
         * Granted the module's decide permission by hand, and still refused: `ApprovalKind::decidedWith()`
         * requires the permission that governs the thing being changed. Otherwise an approvals administrator
         * quietly becomes a budget approver.
         */
        $role = $setup['scenario']->role(SystemRole::Creative);
        $permission = DB::table('permissions')->where('slug', 'approvals.decide')->value('id');
        DB::table('permission_role')->insert(['role_id' => $role->getKey(), 'permission_id' => $permission]);

        test()->actingAs($creative)
            ->postJson("/api/approvals/{$data['id']}/decision", [
                'outcome' => 'approved',
                'fingerprint' => $data['fingerprint'],
            ])
            ->assertForbidden();
    });

    it('refuses the queue to a client viewer', function (): void {
        $setup = approvalScenario();
        $viewer = $setup['scenario']->addMember(SystemRole::ClientViewer);

        test()->actingAs($viewer)->getJson('/api/approvals/queue')->assertForbidden();
    });
});

describe('the vocabulary', function (): void {
    it('publishes, per kind, that approving does not make the change', function (): void {
        $setup = approvalScenario();

        $data = test()->actingAs($setup['scenario']->owner)
            ->getJson('/api/approvals/vocabulary')
            ->assertOk()
            ->json('data');

        foreach ($data['kinds'] as $kind) {
            expect($kind['is_enacted_here'])->toBeFalse();
            expect($kind['enactment_blocked_because'])->not->toBeNull();
        }

        $spend = array_values(array_filter(
            $data['kinds'],
            static fn (array $kind): bool => $kind['kind'] === 'spend_change',
        ))[0];

        expect($spend['decided_with'])->toBe('budgets.approve');
    });
});

it('will not ask for approval about another workspace’s client, project or subject', function (): void {
    $mine = approvalScenario();
    $theirs = approvalScenario();

    foreach ([
        ['client_id' => (string) $theirs['client']->getKey(), 'project_id' => null],
        ['project_id' => (string) $theirs['project']->getKey()],
        ['subject_type' => 'project', 'subject_id' => (string) $theirs['project']->getKey()],
    ] as $naming) {
        $this->actingAs($mine['scenario']->owner)
            ->postJson('/api/approvals', [
                'kind' => 'spend_change',
                'title' => 'Look at their account',
                'rationale' => 'It should not be possible.',
                'proposal' => ['change' => 'anything'],
                'client_id' => (string) $mine['client']->getKey(),
                ...$naming,
            ])
            ->assertNotFound();
    }

    $this->actingAs($mine['scenario']->owner)
        ->postJson('/api/approvals', [
            'kind' => 'spend_change', 'title' => 'x', 'rationale' => 'y', 'proposal' => ['a' => 'b'], 'client_id' => 'nope',
        ])
        ->assertUnprocessable();
});
