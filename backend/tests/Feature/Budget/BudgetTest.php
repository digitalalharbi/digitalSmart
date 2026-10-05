<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Budget\BudgetStatus;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Budget;
use App\Infrastructure\Persistence\Eloquent\Model\BudgetAllocation;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Budgets
|--------------------------------------------------------------------------
|
| A budget record exists to say what a client agreed to. Most of what matters here is
| therefore about what cannot be changed, who may change it, and what is written down
| when somebody does.
|
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function budgetPayload(string $clientId, array $overrides = []): array
{
    return [
        'client_id' => $clientId,
        'name' => 'Q4 retail',
        'period_start' => '2026-10-01',
        'period_end' => '2026-12-31',
        'amount_minor' => null,
        'amount' => '180000.00',
        'currency' => 'SAR',
        ...$overrides,
    ];
}

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function budgetScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/budgets')->assertUnauthorized();
    });

    it('refuses a role without the permission', function (): void {
        $setup = budgetScenario();
        $creative = $setup['scenario']->addMember(SystemRole::Creative);

        // Creatives produce work; they do not see what a client is spending.
        $this->actingAs($creative)->getJson('/api/budgets')->assertForbidden();
    });

    it('lets a media buyer plan but not approve', function (): void {
        $setup = budgetScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        $id = $this->actingAs($buyer)
            ->postJson('/api/budgets', budgetPayload(
                (string) $setup['client']->getKey(),
                ['project_id' => (string) $setup['project']->getKey()],
            ))
            ->assertCreated()
            ->json('data.id');

        /*
         * The separation that makes "approved" mean anything: a buyer can reshape a plan
         * all day and still not be the person who commits the client to a figure.
         */
        $this->actingAs($buyer)->postJson("/api/budgets/{$id}/approve")->assertForbidden();
    });

    it('keeps a client viewer out entirely', function (): void {
        $setup = budgetScenario();
        $client = $setup['scenario']->addMember(SystemRole::ClientViewer);

        /*
         * Deliberate. A budget carries internal notes and an agency's own allocation
         * thinking; what a client may see is decided by explicit sharing later, not by a
         * role that happens to open the screen.
         */
        $this->actingAs($client)->getJson('/api/budgets')->assertForbidden();
    });
});

describe('drafting', function (): void {
    it('creates a draft, and a draft is not paced', function (): void {
        $setup = budgetScenario();

        $budget = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->assertCreated()
            ->json('data');

        expect($budget['status'])->toBe(BudgetStatus::Draft->value)
            ->and($budget['is_paced'])->toBeFalse()
            ->and($budget['amount_is_editable'])->toBeTrue()
            // 180000.00 SAR as halalas.
            ->and($budget['amount_minor'])->toBe(18_000_000)
            ->and($budget['approved_by_name'])->toBeNull();
    });

    it('reads a decimal amount rather than minor units off the wire', function (): void {
        $setup = budgetScenario();

        /*
         * An API that takes "1500.00" and one that takes 150000 look identical in a
         * request body, and the mistake only shows up on an invoice. The field is a
         * decimal, and `amount_minor` in the payload is ignored.
         */
        $budget = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload(
                (string) $setup['client']->getKey(),
                ['amount' => '1500.50', 'amount_minor' => 999],
            ))
            ->assertCreated()
            ->json('data');

        expect($budget['amount_minor'])->toBe(150_050);
    });

    it('refuses a period that ends before it starts', function (): void {
        $setup = budgetScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload(
                (string) $setup['client']->getKey(),
                ['period_start' => '2026-12-31', 'period_end' => '2026-10-01'],
            ))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('period_end');
    });

    it('refuses an amount of nothing', function (): void {
        $setup = budgetScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey(), ['amount' => '0']))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('amount');
    });

    it('deletes a draft', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)->deleteJson("/api/budgets/{$id}")->assertNoContent();

        expect(Budget::query()->count())->toBe(0);
    });
});

describe('approval', function (): void {
    it('records who approved it and when', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');

        $budget = $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/budgets/{$id}/approve")
            ->assertOk()
            ->json('data');

        expect($budget['status'])->toBe(BudgetStatus::Approved->value)
            ->and($budget['approved_by_name'])->not->toBeNull()
            ->and($budget['approved_at'])->not->toBeNull()
            ->and($budget['is_paced'])->toBeTrue();
    });

    it('fixes the amount once approved', function (): void {
        $setup = budgetScenario();
        $clientId = (string) $setup['client']->getKey();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload($clientId))
            ->json('data.id');
        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/approve")->assertOk();

        /*
         * The rule the whole record exists for. Changing the figure a client agreed to,
         * silently, is what a budget is meant to prevent — so it is refused with the way
         * out named in the message.
         */
        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}", budgetPayload($clientId, ['amount' => '200000.00']))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_budget');
    });

    it('still lets the name and notes change after approval', function (): void {
        $setup = budgetScenario();
        $clientId = (string) $setup['client']->getKey();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload($clientId))
            ->json('data.id');
        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/approve");

        // A label is not a commitment.
        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}", budgetPayload($clientId, [
                'name' => 'Q4 retail (renamed)',
                'notes' => 'Agreed on the call with Reem.',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Q4 retail (renamed)');
    });

    it('refuses to delete an approved budget, and says to archive it', function (): void {
        $setup = budgetScenario();
        $clientId = (string) $setup['client']->getKey();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload($clientId))
            ->json('data.id');
        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/approve");

        // A deleted commitment is a quarter nobody can reconcile.
        $this->actingAs($setup['scenario']->owner)
            ->deleteJson("/api/budgets/{$id}")
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/budgets/{$id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', BudgetStatus::Archived->value);
    });

    it('keeps the approver on an archived budget', function (): void {
        $setup = budgetScenario();
        $clientId = (string) $setup['client']->getKey();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload($clientId))
            ->json('data.id');
        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/approve");

        $archived = $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/budgets/{$id}/archive")
            ->json('data');

        // Who agreed to what is the reason this record exists; archiving is not a reason
        // to forget it.
        expect($archived['approved_by_name'])->not->toBeNull()
            ->and($archived['approved_at'])->not->toBeNull();
    });

    it('refuses to approve the same budget twice', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');
        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/approve")->assertOk();

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/budgets/{$id}/approve")
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    });
});

describe('the change log', function (): void {
    it('records every budget change with its before and after', function (): void {
        $setup = budgetScenario();
        $clientId = (string) $setup['client']->getKey();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload($clientId))
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}", budgetPayload($clientId, ['amount' => '150000.00']));

        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/approve");

        $actions = AuditLog::query()
            ->where('auditable_id', $id)
            ->pluck('action')
            ->map(static fn (mixed $action): string => $action instanceof AuditAction ? $action->value : (string) $action)
            ->all();

        /*
         * The immutable change log the governance hierarchy needs. It lives in the audit
         * trail rather than a second history table, which would be another record to keep
         * honest separately.
         */
        expect($actions)->toContain(
            AuditAction::BudgetCreated->value,
            AuditAction::BudgetUpdated->value,
            AuditAction::BudgetApproved->value,
        );

        $update = AuditLog::query()
            ->where('auditable_id', $id)
            ->where('action', AuditAction::BudgetUpdated->value)
            ->firstOrFail();

        expect($update->changes['before']['amount_minor'] ?? null)->toBe(18_000_000)
            ->and($update->changes['after']['amount_minor'] ?? null)->toBe(15_000_000);
    });

    it('treats approving a budget as a security event', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');
        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/approve");

        // Committing a client's money has to be as findable as a credential change.
        expect(AuditAction::BudgetApproved->isSecurityEvent())->toBeTrue();

        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/audit-logs?security_only=1')
            ->assertOk()
            ->assertJsonFragment(['action' => AuditAction::BudgetApproved->value]);
    });

    it('keeps the notes out of the change log', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload(
                (string) $setup['client']->getKey(),
                ['notes' => 'Client is nervous about December.'],
            ))
            ->json('data.id');

        $entry = AuditLog::query()->where('auditable_id', $id)->firstOrFail();

        /*
         * A working note is not part of the commitment, and copying it into an
         * append-only log would make it undeletable.
         */
        expect(json_encode($entry->changes))->not->toContain('nervous');
    });
});

describe('visibility', function (): void {
    it('hides a budget for a project the actor is not on', function (): void {
        $setup = budgetScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        $this->actingAs($setup['scenario']->owner)->postJson('/api/budgets', budgetPayload(
            (string) $setup['client']->getKey(),
            ['project_id' => (string) $setup['project']->getKey()],
        ));

        // Never in the result set, not filtered from the response.
        $this->actingAs($buyer)->getJson('/api/budgets')->assertOk()->assertJsonCount(0, 'data');
    });

    it('shows a client-level budget to someone on any of that client’s projects', function (): void {
        $setup = budgetScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        /*
         * A client's quarterly budget is agreed before anybody decides which project
         * will spend it, so a budget with no project is visible to the people working on
         * that client.
         */
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()));

        $this->actingAs($buyer)->getJson('/api/budgets')->assertOk()->assertJsonCount(1, 'data');
    });

    it('never crosses a workspace boundary', function (): void {
        $setup = budgetScenario();
        $other = WorkspaceScenario::create();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()));

        $this->actingAs($other->owner)->getJson('/api/budgets')->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('allocations', function (): void {
    it('saves a three-level tree', function (): void {
        $setup = budgetScenario();
        $clientId = (string) $setup['client']->getKey();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload($clientId))
            ->json('data.id');

        $budget = $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}/allocations", [
                'allocations' => [[
                    'level' => 'platform',
                    'provider' => 'meta',
                    'label' => 'Meta',
                    'amount' => '100000.00',
                    'children' => [[
                        'level' => 'campaign',
                        'provider' => 'meta',
                        'label' => 'Always-on prospecting',
                        'amount' => '60000.00',
                        'children' => [[
                            'level' => 'ad_group',
                            'provider' => 'meta',
                            'label' => 'Riyadh 25-44',
                            'amount' => '35000.00',
                        ]],
                    ]],
                ]],
            ])
            ->assertOk()
            ->json('data');

        expect($budget['allocated_minor'])->toBe(10_000_000)
            // The remainder is a decision nobody has made yet, and it is reported.
            ->and($budget['unallocated_minor'])->toBe(8_000_000)
            ->and($budget['allocations'][0]['children'][0]['children'][0]['label'])->toBe('Riyadh 25-44')
            ->and($budget['allocations'][0]['unallocated_minor'])->toBe(4_000_000);
    });

    it('refuses allocations that come to more than the budget', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');

        /*
         * The governance hierarchy is only worth something if the parts cannot add up to
         * more than the whole.
         */
        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}/allocations", [
                'allocations' => [
                    ['level' => 'platform', 'provider' => 'meta', 'label' => 'Meta', 'amount' => '150000.00'],
                    ['level' => 'platform', 'provider' => 'google_ads', 'label' => 'Google', 'amount' => '100000.00'],
                ],
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_allocation');
    });

    it('refuses a branch that comes to more than its parent', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}/allocations", [
                'allocations' => [[
                    'level' => 'platform',
                    'provider' => 'meta',
                    'label' => 'Meta',
                    'amount' => '50000.00',
                    'children' => [[
                        'level' => 'campaign',
                        'provider' => 'meta',
                        'label' => 'Too big',
                        'amount' => '60000.00',
                    ]],
                ]],
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_allocation');
    });

    it('refuses a child on a different platform than its parent', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');

        // The sum under "Meta" would otherwise include Google spend.
        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}/allocations", [
                'allocations' => [[
                    'level' => 'platform',
                    'provider' => 'meta',
                    'label' => 'Meta',
                    'amount' => '50000.00',
                    'children' => [[
                        'level' => 'campaign',
                        'provider' => 'google_ads',
                        'label' => 'Wrong platform',
                        'amount' => '10000.00',
                    ]],
                ]],
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_allocation');
    });

    it('refuses a campaign allocation hanging off the budget directly', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}/allocations", [
                'allocations' => [
                    ['level' => 'campaign', 'provider' => 'meta', 'label' => 'Orphan', 'amount' => '1000.00'],
                ],
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_allocation');
    });

    it('replaces the tree rather than merging it', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)->putJson("/api/budgets/{$id}/allocations", [
            'allocations' => [
                ['level' => 'platform', 'provider' => 'meta', 'label' => 'Meta', 'amount' => '50000.00'],
                ['level' => 'platform', 'provider' => 'tiktok', 'label' => 'TikTok', 'amount' => '30000.00'],
            ],
        ])->assertOk();

        $budget = $this->actingAs($setup['scenario']->owner)->putJson("/api/budgets/{$id}/allocations", [
            'allocations' => [
                ['level' => 'platform', 'provider' => 'meta', 'label' => 'Meta', 'amount' => '50000.00'],
            ],
        ])->assertOk()->json('data');

        // The tree a reader sees is the whole plan, so a removed allocation has to go.
        expect($budget['allocations'])->toHaveCount(1)
            ->and(BudgetAllocation::query()->count())->toBe(1);
    });

    it('still allows reallocation after approval', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');
        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/approve");

        // How a plan adapts without the commitment moving.
        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}/allocations", [
                'allocations' => [
                    ['level' => 'platform', 'provider' => 'snapchat', 'label' => 'Snapchat', 'amount' => '20000.00'],
                ],
            ])
            ->assertOk();
    });

    it('refuses to reallocate an archived budget', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');
        $this->actingAs($setup['scenario']->owner)->postJson("/api/budgets/{$id}/archive");

        // The spend recorded against it still has to reconcile.
        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/budgets/{$id}/allocations", [
                'allocations' => [
                    ['level' => 'platform', 'provider' => 'meta', 'label' => 'Meta', 'amount' => '1000.00'],
                ],
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    it('records the reallocation in the change log', function (): void {
        $setup = budgetScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/budgets', budgetPayload((string) $setup['client']->getKey()))
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)->putJson("/api/budgets/{$id}/allocations", [
            'allocations' => [
                ['level' => 'platform', 'provider' => 'meta', 'label' => 'Meta', 'amount' => '50000.00'],
            ],
        ]);

        $entry = AuditLog::query()
            ->where('auditable_id', $id)
            ->where('action', AuditAction::BudgetAllocationsChanged->value)
            ->firstOrFail();

        // Labels and amounts, not ids: the log is read long after a campaign is deleted.
        expect($entry->changes['after'][0]['label'] ?? null)->toBe('Meta')
            ->and($entry->changes['after'][0]['amount_minor'] ?? null)->toBe(5_000_000);
    });
});
