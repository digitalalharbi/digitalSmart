<?php

declare(strict_types=1);

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Carrying out an approved change on a platform
|--------------------------------------------------------------------------
|
| The only place in this product that changes anything outside it, so these tests are almost entirely about the
| gates. Seven of them, and each exists because of a specific way this could go wrong.
|
| The four that matter most: a proposal amended after approval is refused, because carrying it out would carry out
| something nobody approved; a provider whose documentation this product could not read is refused rather than
| attempted; the attempt is audited **before** the request leaves, so an unknown outcome still leaves a trail; and
| a failed write leaves the approval exactly where it was rather than marking it done.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function writeScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * Connect a provider with the sandbox token, which is the only credential this repository holds.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 */
function connectForWrite(array $setup, Provider $provider = Provider::Meta, bool $sandbox = true): void
{
    $credentials = [];

    foreach ($provider->requiredCredentialKeys() as $key) {
        $credentials[$key] = $sandbox ? Credentials::SANDBOX_TOKEN : 'a-real-looking-secret';
    }

    app(ProviderConnectionRepository::class)->save(ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $setup['scenario']->workspace->getKey()),
        provider: $provider,
        owner: ConnectionOwner::agency(),
        name: $provider->label(),
        credentials: new Credentials($credentials),
    ));
}

/**
 * An approved request naming a platform campaign.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $proposal
 */
function approvedWrite(
    array $setup,
    string $kind = 'campaign_pause',
    ?array $proposal = null,
    ?User $as = null,
): string {
    $owner = $setup['scenario']->owner;

    $request = test()->actingAs($as ?? $owner)
        ->postJson('/api/approvals', [
            'kind' => $kind,
            'title' => 'Pause the Ramadan prospecting campaign',
            'rationale' => 'Cost per purchase has run over target for six days.',
            'proposal' => $proposal ?? [
                'provider' => 'meta',
                'external_account_id' => 'act_sandbox_1001',
                'external_campaign_id' => '23848238',
            ],
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
        ])
        ->assertCreated()
        ->json('data');

    /*
     * The decision names the fingerprint of the proposal it read — see `ApprovalTest`. That is also what makes
     * the write path's own fingerprint check meaningful.
     */
    test()->actingAs($owner)
        ->postJson("/api/approvals/{$request['id']}/decision", [
            'outcome' => 'approved',
            'fingerprint' => $request['fingerprint'],
        ])
        ->assertOk();

    return (string) $request['id'];
}

describe('the surface tells a reader whether it can be carried out', function (): void {
    it('says a request naming no campaign cannot be', function (): void {
        $setup = writeScenario();

        $request = test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/approvals', [
                'kind' => 'campaign_pause',
                'title' => 'Pause the retail campaign',
                'rationale' => 'It has run over target all week.',
                'proposal' => ['summary' => 'Pause the retail campaign'],
                'client_id' => (string) $setup['client']->getKey(),
            ])
            ->assertCreated()
            ->json('data');

        /*
         * Most approvals do not name one, and the sentence is published rather than the control hidden: a
         * missing button produces a support question, and the sentence produces somebody opening the platform.
         */
        expect($request['can_be_carried_out_here'])->toBeFalse();
        expect($request['carry_out_blocked_because'])->toContain('does not name a platform campaign');
    });

    it('says a provider it has not verified cannot be', function (): void {
        $setup = writeScenario();

        $request = test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/approvals', [
                'kind' => 'campaign_pause',
                'title' => 'Pause the TikTok campaign',
                'rationale' => 'It has run over target all week.',
                'proposal' => [
                    'provider' => 'tiktok',
                    'external_account_id' => '7000000000',
                    'external_campaign_id' => '1800000000',
                ],
                'client_id' => (string) $setup['client']->getKey(),
            ])
            ->assertCreated()
            ->json('data');

        /*
         * TikTok's own documentation could not be read from here. Third-party libraries describe the endpoint,
         * which is not the provider saying so — and a guess is a guess against a live campaign.
         */
        expect($request['can_be_carried_out_here'])->toBeFalse();
        expect($request['carry_out_blocked_because'])->toContain('provider\'s own');
    });

    it('says a documented one can be', function (): void {
        $setup = writeScenario();
        $id = approvedWrite($setup);

        $request = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/approvals/{$id}")
            ->assertOk()
            ->json('data');

        expect($request['can_be_carried_out_here'])->toBeTrue();
        expect($request['carry_out_blocked_because'])->toBeNull();

        /* And approving it still did not do it. That separation is the whole approvals argument. */
        expect($request['is_enacted_here'])->toBeFalse();
        expect($request['enactment'])->toBe('awaiting_hand');
    });
});

describe('the gates', function (): void {
    it('refuses a request that has not been approved', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);

        $id = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/approvals', [
                'kind' => 'campaign_pause',
                'title' => 'Pause it',
                'rationale' => 'Over target for six days.',
                'proposal' => [
                    'provider' => 'meta',
                    'external_account_id' => 'act_sandbox_1001',
                    'external_campaign_id' => '23848238',
                ],
                'client_id' => (string) $setup['client']->getKey(),
            ])
            ->assertCreated()
            ->json('data.id');

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_approved');
    });

    it('cannot have its proposal amended after approval, and refuses one that does not match', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);
        $id = approvedWrite($setup);

        /*
         * The first half of the gate is upstream: `ApprovalState::isAmendable()` is true only while pending, so
         * an approved proposal cannot be changed through the API at all. Asserted here because that is what
         * makes the write path safe, and a future surface that loosened it would fail this test rather than
         * quietly make an amended proposal executable.
         */
        test()->actingAs($setup['scenario']->owner)
            ->patchJson("/api/approvals/{$id}", [
                'title' => 'Pause the Ramadan prospecting campaign',
                'rationale' => 'Cost per purchase has run over target for six days.',
                'proposal' => [
                    'provider' => 'meta',
                    'external_account_id' => 'act_sandbox_1001',
                    'external_campaign_id' => '99999999',
                ],
            ])
            ->assertStatus(422);

        /*
         * And the second half is in the write path itself, as defence in depth: `CarryOutApproval` compares the
         * proposal's fingerprint with the one the decision was taken against, so a change reaching the proposal
         * by any other route — a background job, a repository call, a future endpoint — still cannot be carried
         * out. The request below therefore succeeds, which is the point: the proposal is the approved one.
         */
        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertOk();

        expect($response->json('outcome.is_confirmed'))->toBeTrue();
        expect($response->json('data.enactment'))->toBe('confirmed');
    });

    it('refuses a proposal whose fingerprint no longer matches the decision', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);
        $id = approvedWrite($setup);

        /*
         * Reached by changing the stored proposal directly, which is the only way in: the state machine refuses
         * it through the API. Doing it here is how the gate gets exercised at all — and an unexercised gate on
         * the one path that changes a client's campaign is a gate nobody should rely on.
         */
        Illuminate\Support\Facades\DB::table('approval_requests')
            ->where('id', $id)
            ->update(['proposal_fingerprint' => str_repeat('a', 64)]);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertStatus(409)
            ->assertJsonPath('code', 'proposal_changed');
    });

    it('refuses when the provider is not connected', function (): void {
        $setup = writeScenario();
        $id = approvedWrite($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_connected');
    });

    it('refuses a kind this product cannot carry out', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);

        $id = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/approvals', [
                'kind' => 'targeting_change',
                'title' => 'Narrow the audience to Riyadh',
                'rationale' => 'The wider audience is not converting.',
                'proposal' => [
                    'provider' => 'meta',
                    'external_account_id' => 'act_sandbox_1001',
                    'external_campaign_id' => '23848238',
                ],
                'client_id' => (string) $setup['client']->getKey(),
            ])
            ->assertCreated()
            ->json('data.id');

        $fingerprint = (string) test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/approvals/{$id}")
            ->assertOk()
            ->json('data.fingerprint');

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/decision", [
                'outcome' => 'approved',
                'fingerprint' => $fingerprint,
            ])
            ->assertOk();

        /* Targeting is not one of the three operations read in any provider's documentation. */
        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertStatus(409)
            ->assertJsonPath('code', 'kind_not_executable');
    });

    it('refuses a reader without the permission', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);
        $id = approvedWrite($setup);

        $creative = $setup['scenario']->addMember(SystemRole::Creative);
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $creative->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::Creative)->getKey(),
        ]);

        test()->actingAs($creative)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertForbidden();
    });
});

describe('a write against sandbox credentials', function (): void {
    it('confirms, and labels the outcome sandbox for ever', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);
        $id = approvedWrite($setup);

        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertOk();

        expect($response->json('outcome.is_confirmed'))->toBeTrue();
        expect($response->json('outcome.confirmed_state'))->toBe('PAUSED');

        /*
         * BLOCKED_EXTERNAL: no platform has issued this repository credentials, so nothing live has been
         * changed — and an audit trail recording a simulated pause as a real one would be worse than none.
         */
        expect($response->json('outcome.source'))->toBe('sandbox');

        /* The enactment is confirmed, with a note saying the product did it rather than a person claiming so. */
        expect($response->json('data.enactment'))->toBe('confirmed');
        expect($response->json('data.enactment_note'))->toContain('Carried out by digitalSmart');
    });

    it('audits the attempt before the request leaves, and the outcome after', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);
        $id = approvedWrite($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertOk();

        /** @var AuditLog $attempt */
        $attempt = AuditLog::query()->where('action', AuditAction::ProviderWriteAttempted->value)->sole();
        /** @var AuditLog $confirmed */
        $confirmed = AuditLog::query()->where('action', AuditAction::ProviderWriteConfirmed->value)->sole();

        /** @var array<string, mixed> $attempted */
        $attempted = $attempt->changes ?? [];

        /*
         * The attempt carries what was sent and the documented contract it was sent against. An outcome of
         * `unknown` has no response to record, and this entry is the only thing that can say a request was made.
         */
        expect($attempted['operation'])->toBe('pause_campaign');
        expect($attempted['external_campaign_id'])->toBe('23848238');
        expect($attempted['reference'])->toContain('Meta Marketing API');

        /** @var array<string, mixed> $result */
        $result = $confirmed->changes ?? [];

        expect($result['confirmed_state'])->toBe('PAUSED');
        expect($result['source'])->toBe('sandbox');

        /* Both are security events: a write to a client's live campaign belongs beside a credential change. */
        expect(AuditAction::ProviderWriteAttempted->isSecurityEvent())->toBeTrue();
        expect(AuditAction::ProviderWriteConfirmed->isSecurityEvent())->toBeTrue();
    });

    it('will not carry the same change out twice', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);
        $id = approvedWrite($setup);

        test()->actingAs($setup['scenario']->owner)->postJson("/api/approvals/{$id}/carry-out")->assertOk();

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_settled');
    });

    it('carries out a budget change with the amount that was approved', function (): void {
        $setup = writeScenario();
        connectForWrite($setup);

        $id = approvedWrite($setup, 'spend_change', [
            'provider' => 'meta',
            'external_account_id' => 'act_sandbox_1001',
            'external_campaign_id' => '23848238',
            'daily_budget_minor' => 80_000,
            'currency' => 'SAR',
        ]);

        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertOk();

        expect($response->json('outcome.confirmed_state'))->toBe('daily_budget=80000');
    });
});

describe('a write against real credentials with no live adapter', function (): void {
    it('refuses rather than simulating a change against a client\'s account', function (): void {
        $setup = writeScenario();
        connectForWrite($setup, sandbox: false);
        $id = approvedWrite($setup);

        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertOk();

        /*
         * The most important assertion in this file. A real credential reaching the sandbox writer means a live
         * adapter is missing and this one was reached instead; simulating success there would record a change
         * that never happened against a client's live campaign.
         */
        expect($response->json('outcome.is_confirmed'))->toBeFalse();
        expect($response->json('outcome.failure'))->toBe('not_authorised');
        expect($response->json('outcome.provider_message'))->toContain('No live write adapter exists');
        expect($response->json('outcome.provider_message'))->toContain('Meta Marketing API');

        /* And the approval is untouched: not confirmed, not abandoned, still waiting for a hand. */
        expect($response->json('data.enactment'))->toBe('awaiting_hand');

        expect(AuditLog::query()->where('action', AuditAction::ProviderWriteFailed->value)->exists())
            ->toBeTrue();
    });

    it('tells a reader whether it is safe to try again', function (): void {
        $setup = writeScenario();
        connectForWrite($setup, sandbox: false);
        $id = approvedWrite($setup);

        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/approvals/{$id}/carry-out")
            ->assertOk();

        expect($response->json('outcome.safe_to_retry'))->toBeTrue();
        expect($response->json('outcome.guidance'))->toContain('Nothing was changed');
    });
});
