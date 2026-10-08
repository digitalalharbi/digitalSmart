<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\SavedView;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Saved views
|--------------------------------------------------------------------------
|
| A saved view is a stored analytics question. The interesting part is what gets
| stored: a preset rather than the dates it happened to resolve to, so the question
| keeps meaning what it said.
|
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function viewPayload(array $overrides = []): array
{
    return [
        'name' => 'Meta, last 28 days',
        'visibility' => 'private',
        'query' => [
            'preset' => '28d',
            'compare' => 'previous_period',
            'metrics' => ['spend', 'roas', 'conversions'],
            'dimension' => 'campaign',
            'providers' => ['meta'],
        ],
        ...$overrides,
    ];
}

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/analytics/views')->assertUnauthorized();
    });

    it('lets a buyer read views but not author them', function (): void {
        $scenario = WorkspaceScenario::create();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);

        /*
         * Reading and authoring are deliberately different permissions, and a media buyer is now the role that
         * demonstrates it: a client viewer held `analytics.view` until Phase 22 moved a client's access to their
         * own portal, so this assertion needed a role that still reads the agency's analytics.
         */
        $this->actingAs($buyer)->getJson('/api/analytics/views')->assertOk();
    });

    it('keeps the agency\'s saved views away from a client', function (): void {
        $scenario = WorkspaceScenario::create();
        $client = $scenario->addMember(SystemRole::ClientViewer);

        $this->actingAs($client)->getJson('/api/analytics/views')->assertForbidden();

        $this->actingAs($client)
            ->postJson('/api/analytics/views', viewPayload())
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('required_permission', 'saved_views.manage');
    });
});

describe('saving', function (): void {
    it('stores the question, not the dates it resolved to', function (): void {
        $scenario = WorkspaceScenario::create();

        $payload = $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload())
            ->assertCreated()
            ->json('data');

        /*
         * A view called "last 28 days" should still mean that next month. Freezing
         * the dates would turn it into a snapshot of one period, which is a different
         * feature with a different name.
         */
        expect($payload['query']['metrics'])->toBe(['spend', 'roas', 'conversions'])
            ->and($payload['query']['dimension'])->toBe('campaign')
            ->and($payload['query']['providers'])->toBe(['meta'])
            ->and($payload['can_manage'])->toBeTrue();
    });

    it('rebuilds the query through the same validation the live one uses', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload([
                'query' => ['metrics' => ['not_a_metric']],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('query.metrics.0');
    });

    it('refuses a dimension that does not exist', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload([
                'query' => ['preset' => '7d', 'dimension' => 'invented'],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    it('keeps an explicit date range when one was given', function (): void {
        $scenario = WorkspaceScenario::create();

        $payload = $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload([
                'query' => ['start' => '2026-09-01', 'end' => '2026-09-30', 'metrics' => ['spend']],
            ]))
            ->assertCreated()
            ->json('data');

        // A fixed period is a legitimate thing to save — a closed month, say — and it
        // is stored as the dates rather than as a preset.
        expect($payload['query']['start'])->toBe('2026-09-01')
            ->and($payload['query']['end'])->toBe('2026-09-30');
    });

    it('refuses a range that ends before it starts', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload([
                'query' => ['start' => '2026-09-30', 'end' => '2026-09-01'],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    it('refuses two views with the same name from one person', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)->postJson('/api/analytics/views', viewPayload())->assertCreated();

        /*
         * Two identically named views are indistinguishable in a picker, which is the
         * only place they appear. The unique index refuses it; the response is a 500
         * only if nothing catches it, so this asserts the constraint exists.
         */
        expect(SavedView::query()->count())->toBe(1);
    });
});

describe('editing', function (): void {
    it('updates a view the actor owns', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload())
            ->json('data.id');

        $payload = $this->actingAs($scenario->owner)
            ->putJson("/api/analytics/views/{$id}", viewPayload([
                'name' => 'Meta, last 7 days',
                'query' => ['preset' => '7d', 'metrics' => ['spend']],
            ]))
            ->assertOk()
            ->json('data');

        expect($payload['name'])->toBe('Meta, last 7 days')
            ->and($payload['query']['metrics'])->toBe(['spend'])
            // The old provider filter is gone, not merged into the new query.
            ->and($payload['query'])->not->toHaveKey('providers');
    });

    it('refuses to let a colleague rewrite a shared view', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload(['visibility' => 'workspace']))
            ->json('data.id');

        // A shared view anyone can rewrite is one nobody can rely on.
        $this->actingAs($manager)
            ->putJson("/api/analytics/views/{$id}", viewPayload(['name' => 'Hijacked']))
            ->assertNotFound();
    });

    it('deletes only the actor’s own', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload(['visibility' => 'workspace']))
            ->json('data.id');

        $this->actingAs($manager)->deleteJson("/api/analytics/views/{$id}")->assertNotFound();
        $this->actingAs($scenario->owner)->deleteJson("/api/analytics/views/{$id}")->assertNoContent();

        expect(SavedView::query()->count())->toBe(0);
    });
});

describe('names', function (): void {
    it('refuses a second view with a name the owner already used', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)->postJson('/api/analytics/views', viewPayload())->assertCreated();

        /*
         * The unique index refuses this either way; asking first is what makes the
         * answer a 422 naming the view, rather than a failed insert surfacing as a
         * 500 with the constraint in it.
         */
        $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload())
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'name_already_used');
    });

    it('lets a view keep its own name while its question changes', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload())
            ->json('data.id');

        $this->actingAs($scenario->owner)
            ->putJson("/api/analytics/views/{$id}", viewPayload(['visibility' => 'workspace']))
            ->assertOk();
    });

    it('lets two people each have a view by the same name', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $this->actingAs($scenario->owner)->postJson('/api/analytics/views', viewPayload())->assertCreated();
        $this->actingAs($manager)->postJson('/api/analytics/views', viewPayload())->assertCreated();
    });
});

describe('visibility', function (): void {
    it('keeps a private view out of a colleague’s list entirely', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $this->actingAs($scenario->owner)->postJson('/api/analytics/views', viewPayload());

        $this->actingAs($manager)->getJson('/api/analytics/views')->assertJsonCount(0, 'data');
    });

    it('shows a project view only to people on that project', function (): void {
        $scenario = WorkspaceScenario::create();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create();
        $onProject = $scenario->addMember(SystemRole::MediaBuyer);
        $elsewhere = $scenario->addMember(SystemRole::MediaBuyer);

        ProjectMember::query()->create([
            'project_id' => $project->getKey(),
            'user_id' => $onProject->getKey(),
            'role_id' => $scenario->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        $this->actingAs($scenario->owner)->postJson('/api/analytics/views', viewPayload([
            'visibility' => 'project',
            'project_id' => $project->getKey(),
        ]))->assertCreated();

        $this->actingAs($onProject)->getJson('/api/analytics/views')->assertJsonCount(1, 'data');
        $this->actingAs($elsewhere)->getJson('/api/analytics/views')->assertJsonCount(0, 'data');
    });

    it('names the project a view is scoped to', function (): void {
        $scenario = WorkspaceScenario::create();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create(['name' => 'Ramadan 2027']);

        $payload = $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload([
                'visibility' => 'project',
                'project_id' => $project->getKey(),
            ]))
            ->assertCreated()
            ->json('data');

        // So the picker can say who sees it without a second request.
        expect($payload['project_name'])->toBe('Ramadan 2027')
            ->and($payload['visibility_label'])->toBe('The project team');
    });
});

describe('ordering and use', function (): void {
    it('records that a view was opened', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload())
            ->json('data.id');

        $this->actingAs($scenario->owner)
            ->postJson("/api/analytics/views/{$id}/opened")
            ->assertNoContent();

        /*
         * Tracked separately from reading the list, so the ordering reflects what
         * someone actually uses rather than what a background refresh fetched.
         */
        $payload = $this->actingAs($scenario->owner)->getJson('/api/analytics/views')->json('data');

        expect($payload[0]['last_used_at'])->not->toBeNull();
    });

    it('puts the default first', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)->postJson('/api/analytics/views', viewPayload(['name' => 'Alpha']));
        $this->actingAs($scenario->owner)
            ->postJson('/api/analytics/views', viewPayload(['name' => 'Zulu', 'is_default' => true]));

        $payload = $this->actingAs($scenario->owner)->getJson('/api/analytics/views')->json('data');

        expect($payload[0]['name'])->toBe('Zulu')
            ->and($payload[0]['is_default'])->toBeTrue();
    });
});

describe('isolation', function (): void {
    it('never shows another workspace’s view', function (): void {
        $mine = WorkspaceScenario::create();
        $theirs = WorkspaceScenario::create();

        $this->actingAs($theirs->owner)
            ->postJson('/api/analytics/views', viewPayload(['visibility' => 'workspace']));

        $this->actingAs($mine->owner)->getJson('/api/analytics/views')->assertJsonCount(0, 'data');
    });
});
