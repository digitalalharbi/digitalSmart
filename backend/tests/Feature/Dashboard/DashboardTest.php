<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Dashboard\Visibility;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\DashboardWidget;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Dashboards
|--------------------------------------------------------------------------
|
| A dashboard is a set of saved intentions about other people's money, so most of
| what matters here is about who may change one and what happens to the people
| relying on it when someone does.
|
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function widget(array $overrides = []): array
{
    return [
        'type' => 'bar',
        'metrics' => ['spend'],
        'dimension' => 'campaign',
        'column' => 0,
        'row' => 0,
        'width' => 6,
        'height' => 2,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dashboardPayload(array $overrides = []): array
{
    return [
        'name' => 'Weekly performance',
        'visibility' => 'private',
        'filters' => ['preset' => '28d', 'compare' => 'previous_period'],
        'widgets' => [widget()],
        ...$overrides,
    ];
}

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/dashboards')->assertUnauthorized();
        $this->postJson('/api/dashboards', dashboardPayload())->assertUnauthorized();
    });

    it('lets a client viewer read dashboards but not build them', function (): void {
        $scenario = WorkspaceScenario::create();
        $client = $scenario->addMember(SystemRole::ClientViewer);

        /*
         * Client viewers hold analytics.view but not saved_views.manage: they see
         * what was shared with them and cannot reshape it. Reading and authoring are
         * deliberately different permissions.
         */
        $this->actingAs($client)->getJson('/api/dashboards')->assertOk();

        $this->actingAs($client)
            ->postJson('/api/dashboards', dashboardPayload())
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('required_permission', 'saved_views.manage');
    });
});

describe('creating', function (): void {
    it('saves a dashboard with its widgets and the grid it was laid out on', function (): void {
        $scenario = WorkspaceScenario::create();

        $payload = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [
                    widget(['title' => 'Spend by campaign']),
                    widget(['type' => 'line', 'dimension' => null, 'column' => 6, 'width' => 6]),
                ],
            ]))
            ->assertCreated()
            ->json('data');

        expect($payload['name'])->toBe('Weekly performance')
            ->and($payload['widgets'])->toHaveCount(2)
            ->and($payload['widgets'][0]['title'])->toBe('Spend by campaign')
            // Published so the client lays out against the same grid the server
            // validates against, rather than keeping a second copy of the number.
            ->and($payload['grid_columns'])->toBe(12)
            ->and($payload['can_manage'])->toBeTrue();
    });

    it('stores the dashboard’s own filters for its widgets to inherit', function (): void {
        $scenario = WorkspaceScenario::create();

        $payload = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload())
            ->assertCreated()
            ->json('data');

        expect($payload['filters'])->toBe(['preset' => '28d', 'compare' => 'previous_period'])
            /*
             * Null, not an empty object. "Inherit the dashboard's filters" and
             * "override them with nothing" are different instructions, and a widget
             * that collapsed them would show the wrong period.
             */
            ->and($payload['widgets'][0]['filter_overrides'])->toBeNull();
    });

    it('keeps a widget’s own filter override distinct from inheriting', function (): void {
        $scenario = WorkspaceScenario::create();

        $payload = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [widget(['filter_overrides' => ['providers' => ['meta']]])],
            ]))
            ->assertCreated()
            ->json('data');

        expect($payload['widgets'][0]['filter_overrides'])->toBe(['providers' => ['meta']]);
    });

    it('drops a filter key the analytics layer does not understand', function (): void {
        $scenario = WorkspaceScenario::create();

        $payload = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'filters' => ['preset' => '7d', 'something_invented' => 'value'],
            ]))
            ->assertCreated()
            ->json('data');

        // JSONB accepts any shape, so a stray key would sit there until something
        // tried to read it.
        expect($payload['filters'])->toBe(['preset' => '7d']);
    });
});

describe('widget coherence', function (): void {
    it('refuses a share-of-total across dates', function (): void {
        $scenario = WorkspaceScenario::create();

        /*
         * Ninety slices, one per day, summing to a number nobody asked for. It draws
         * perfectly and tells the reader nothing, so it is refused rather than
         * offered.
         */
        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [widget(['type' => 'share', 'dimension' => 'date'])],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_widget');
    });

    it('refuses a scatter with only one metric', function (): void {
        $scenario = WorkspaceScenario::create();

        $response = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [widget(['type' => 'scatter', 'metrics' => ['spend']])],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        // The message names what is wrong and what would fix it, because it reaches
        // someone composing a dashboard rather than a developer reading a log.
        expect($response->json('message'))->toContain('needs two');
    });

    it('refuses a comparison widget with nothing to group by', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [widget(['type' => 'bar', 'dimension' => null])],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    it('refuses two widgets that would sit on top of each other', function (): void {
        $scenario = WorkspaceScenario::create();

        $response = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [
                    widget(['title' => 'Left', 'column' => 0, 'width' => 8]),
                    widget(['title' => 'Right', 'column' => 4, 'width' => 8]),
                ],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        /*
         * An overlap does not error in a browser — one widget simply covers the
         * other, and the hidden one is invisible until someone moves it. Refusing
         * the save is the only way the reader finds out.
         */
        expect($response->json('message'))->toContain('on top of');
    });

    it('allows two widgets side by side on the same row', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [
                    widget(['column' => 0, 'width' => 6]),
                    // Begins exactly where the first ends: adjacent, not overlapping.
                    widget(['column' => 6, 'width' => 6]),
                ],
            ]))
            ->assertCreated();
    });

    it('refuses a widget that would run off the grid', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [widget(['column' => 8, 'width' => 6])],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_widget_placement');
    });

    it('refuses a widget too narrow to render anything', function (): void {
        $scenario = WorkspaceScenario::create();

        // Caught by validation before the domain sees it, since the grid's minimum is
        // a request-shape rule as well as a product one.
        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [widget(['width' => 1])],
            ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    });
});

describe('editing', function (): void {
    it('replaces the widget set, so a removed widget actually goes', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [widget(['column' => 0]), widget(['column' => 6])],
            ]))
            ->json('data.id');

        $payload = $this->actingAs($scenario->owner)
            ->putJson("/api/dashboards/{$id}", dashboardPayload([
                'widgets' => [widget(['column' => 0, 'width' => 12])],
            ]))
            ->assertOk()
            ->json('data');

        // The canvas the reader sees is the whole truth about the dashboard.
        expect($payload['widgets'])->toHaveCount(1)
            ->and($payload['widgets'][0]['width'])->toBe(12)
            ->and(DashboardWidget::query()->count())->toBe(1);
    });

    it('leaves the canvas alone when no widgets are sent', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload())
            ->json('data.id');

        $payload = $this->actingAs($scenario->owner)
            ->putJson("/api/dashboards/{$id}", [
                'name' => 'Renamed',
                'visibility' => 'workspace',
            ])
            ->assertOk()
            ->json('data');

        /*
         * Renaming a dashboard or changing its audience should not require resending
         * every widget — and an empty array would mean "the canvas is now empty",
         * which is a different instruction.
         */
        expect($payload['name'])->toBe('Renamed')
            ->and($payload['visibility'])->toBe('workspace')
            ->and($payload['widgets'])->toHaveCount(1);
    });

    it('can empty a canvas deliberately', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload())
            ->json('data.id');

        $payload = $this->actingAs($scenario->owner)
            ->putJson("/api/dashboards/{$id}", dashboardPayload(['widgets' => []]))
            ->assertOk()
            ->json('data');

        expect($payload['widgets'])->toBe([]);
    });

    it('persists a reorder and a resize', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'widgets' => [
                    widget(['title' => 'First', 'row' => 0]),
                    widget(['title' => 'Second', 'row' => 1]),
                ],
            ]))
            ->json('data.id');

        // Swapped rows, and the first made full width.
        $payload = $this->actingAs($scenario->owner)
            ->putJson("/api/dashboards/{$id}", dashboardPayload([
                'widgets' => [
                    widget(['title' => 'First', 'row' => 1, 'width' => 12]),
                    widget(['title' => 'Second', 'row' => 0]),
                ],
            ]))
            ->assertOk()
            ->json('data');

        // Returned in canvas order — top to bottom — so two clients cannot sort them
        // differently.
        expect($payload['widgets'][0]['title'])->toBe('Second')
            ->and($payload['widgets'][1]['title'])->toBe('First')
            ->and($payload['widgets'][1]['width'])->toBe(12);
    });

    it('refuses to let a colleague rewrite a shared dashboard', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['visibility' => 'workspace']))
            ->json('data.id');

        /*
         * A shared dashboard anyone can rewrite is one nobody can rely on, and the
         * people using it would have no way to know it had changed under them. A 404
         * rather than a 403: whether someone else's dashboard exists is not something
         * a caller learns by trying to edit it.
         */
        $this->actingAs($manager)
            ->putJson("/api/dashboards/{$id}", dashboardPayload(['name' => 'Hijacked']))
            ->assertNotFound();

        // They can still read it, which is what sharing meant.
        $this->actingAs($manager)->getJson("/api/dashboards/{$id}")->assertOk();
    });

    it('refuses to let a colleague delete a shared dashboard', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['visibility' => 'workspace']))
            ->json('data.id');

        $this->actingAs($manager)->deleteJson("/api/dashboards/{$id}")->assertNotFound();
        $this->actingAs($scenario->owner)->deleteJson("/api/dashboards/{$id}")->assertNoContent();
    });
});

describe('duplicating', function (): void {
    it('copies a colleague’s dashboard into a private one of your own', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'visibility' => 'workspace',
                'widgets' => [widget(['column' => 0]), widget(['column' => 6])],
            ]))
            ->json('data.id');

        $copy = $this->actingAs($manager)
            ->postJson("/api/dashboards/{$id}/duplicate")
            ->assertCreated()
            ->json('data');

        /*
         * This is how a shared dashboard gets adapted without being changed for
         * everyone else. The copy is private whatever the original was: inheriting a
         * shared audience would publish a draft the moment it was made.
         */
        expect($copy['visibility'])->toBe(Visibility::Private->value)
            ->and($copy['name'])->toBe('Weekly performance (copy)')
            ->and($copy['widgets'])->toHaveCount(2)
            ->and($copy['can_manage'])->toBeTrue()
            ->and($copy['id'])->not->toBe($id);
    });

    it('accepts a name for the copy', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload())
            ->json('data.id');

        $copy = $this->actingAs($scenario->owner)
            ->postJson("/api/dashboards/{$id}/duplicate", ['name' => 'Q4 variant'])
            ->assertCreated()
            ->json('data');

        expect($copy['name'])->toBe('Q4 variant');
    });

    it('numbers a second copy instead of failing on a name it chose itself', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload())
            ->json('data.id');

        $first = $this->actingAs($scenario->owner)
            ->postJson("/api/dashboards/{$id}/duplicate")
            ->assertCreated()
            ->json('data.name');

        /*
         * Duplicating twice is ordinary — comparing two variants of the same
         * dashboard. The server picked "(copy)", so the collision is the server's to
         * resolve; refusing a name the person never typed would be inexplicable.
         */
        $second = $this->actingAs($scenario->owner)
            ->postJson("/api/dashboards/{$id}/duplicate")
            ->assertCreated()
            ->json('data.name');

        expect($first)->toBe('Weekly performance (copy)')
            ->and($second)->toBe('Weekly performance (copy 2)');
    });

    it('refuses a copy name the caller already used', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload())
            ->json('data.id');

        // A name they typed, so theirs to change — reported rather than renamed.
        $this->actingAs($scenario->owner)
            ->postJson("/api/dashboards/{$id}/duplicate", ['name' => 'Weekly performance'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'name_already_used');
    });

    it('refuses to copy a dashboard that was never shared with you', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['visibility' => 'private']))
            ->json('data.id');

        $this->actingAs($manager)->postJson("/api/dashboards/{$id}/duplicate")->assertNotFound();
    });
});

describe('names', function (): void {
    it('refuses a second dashboard with a name the owner already used', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)->postJson('/api/dashboards', dashboardPayload())->assertCreated();

        /*
         * The unique index would refuse this either way. Checking first is what makes
         * the answer a 422 naming the dashboard, instead of a failed insert arriving
         * as a 500 with the constraint and the SQL in it — for someone who simply
         * reused a name.
         */
        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload())
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'name_already_used');
    });

    it('refuses to rename a dashboard onto another of the owner’s names', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)->postJson('/api/dashboards', dashboardPayload(['name' => 'Taken']));

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['name' => 'Free']))
            ->json('data.id');

        $this->actingAs($scenario->owner)
            ->putJson("/api/dashboards/{$id}", dashboardPayload(['name' => 'Taken']))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'name_already_used');
    });

    it('lets a dashboard keep its own name when something else changes', function (): void {
        $scenario = WorkspaceScenario::create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload())
            ->json('data.id');

        // Saving a layout without renaming must not collide with itself.
        $this->actingAs($scenario->owner)
            ->putJson("/api/dashboards/{$id}", dashboardPayload(['visibility' => 'workspace']))
            ->assertOk()
            ->assertJsonPath('data.visibility', Visibility::Workspace->value);
    });

    it('lets two people each have a dashboard by the same name', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        // The index is per owner: one person's naming is not a constraint on another's.
        $this->actingAs($scenario->owner)->postJson('/api/dashboards', dashboardPayload())->assertCreated();
        $this->actingAs($manager)->postJson('/api/dashboards', dashboardPayload())->assertCreated();
    });
});

describe('visibility', function (): void {
    it('keeps a private dashboard out of a colleague’s list entirely', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $this->actingAs($scenario->owner)->postJson('/api/dashboards', dashboardPayload());

        // Not filtered from the response — never in the result set. A leaked name, or
        // even a count, is still a leak.
        $this->actingAs($manager)->getJson('/api/dashboards')->assertOk()->assertJsonCount(0, 'data');
    });

    it('shows a workspace dashboard to everyone who can read analytics', function (): void {
        $scenario = WorkspaceScenario::create();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['visibility' => 'workspace']));

        $payload = $this->actingAs($manager)->getJson('/api/dashboards')->assertOk()->json('data');

        expect($payload)->toHaveCount(1)
            // Visible, but not theirs to change.
            ->and($payload[0]['can_manage'])->toBeFalse();
    });

    it('shows a project dashboard only to people on that project', function (): void {
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

        $this->actingAs($scenario->owner)->postJson('/api/dashboards', dashboardPayload([
            'visibility' => 'project',
            'project_id' => $project->getKey(),
        ]))->assertCreated();

        /*
         * The case this third visibility state exists for: an agency needs a dashboard
         * one client's team sees and colleagues working for a different client do not.
         */
        $this->actingAs($onProject)->getJson('/api/dashboards')->assertJsonCount(1, 'data');
        $this->actingAs($elsewhere)->getJson('/api/dashboards')->assertJsonCount(0, 'data');
    });

    it('refuses project scope without a project', function (): void {
        $scenario = WorkspaceScenario::create();

        // The audience would be undefined. The database refuses it too.
        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['visibility' => 'project']))
            ->assertNotFound();
    });

    it('refuses to share with a project the author cannot see', function (): void {
        $scenario = WorkspaceScenario::create();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create();

        /*
         * Otherwise "share with the project team" doubles as a way to discover which
         * projects exist — so the refusal is the same 404 as an absent project.
         */
        $this->actingAs($buyer)
            ->postJson('/api/dashboards', dashboardPayload([
                'visibility' => 'project',
                'project_id' => $project->getKey(),
            ]))
            ->assertNotFound();
    });

    it('clears the project when a dashboard stops being project-scoped', function (): void {
        $scenario = WorkspaceScenario::create();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create();

        $id = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload([
                'visibility' => 'project',
                'project_id' => $project->getKey(),
            ]))
            ->json('data.id');

        $payload = $this->actingAs($scenario->owner)
            ->putJson("/api/dashboards/{$id}", dashboardPayload(['visibility' => 'workspace']))
            ->assertOk()
            ->json('data');

        // A stale project id would make a later switch back to project scope silently
        // reuse an audience nobody chose.
        expect($payload['project_id'])->toBeNull();
    });
});

describe('defaults', function (): void {
    it('keeps at most one default per person', function (): void {
        $scenario = WorkspaceScenario::create();

        $first = $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['name' => 'First', 'is_default' => true]))
            ->json('data.id');

        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['name' => 'Second', 'is_default' => true]));

        $payload = $this->actingAs($scenario->owner)->getJson('/api/dashboards')->json('data');
        $defaults = array_values(array_filter($payload, static fn (array $d): bool => $d['is_default']));

        // Two defaults would make the landing dashboard arbitrary, which is worse
        // than having none.
        expect($defaults)->toHaveCount(1)
            ->and($defaults[0]['name'])->toBe('Second')
            ->and($first)->not->toBe($defaults[0]['id']);
    });

    it('lists the default first', function (): void {
        $scenario = WorkspaceScenario::create();

        $this->actingAs($scenario->owner)->postJson('/api/dashboards', dashboardPayload(['name' => 'Alpha']));
        $this->actingAs($scenario->owner)
            ->postJson('/api/dashboards', dashboardPayload(['name' => 'Zulu', 'is_default' => true]));

        $payload = $this->actingAs($scenario->owner)->getJson('/api/dashboards')->json('data');

        expect($payload[0]['name'])->toBe('Zulu');
    });
});

describe('isolation', function (): void {
    it('never shows another workspace’s dashboard', function (): void {
        $mine = WorkspaceScenario::create();
        $theirs = WorkspaceScenario::create();

        $id = $this->actingAs($theirs->owner)
            ->postJson('/api/dashboards', dashboardPayload(['visibility' => 'workspace']))
            ->json('data.id');

        $this->actingAs($mine->owner)->getJson('/api/dashboards')->assertJsonCount(0, 'data');
        $this->actingAs($mine->owner)->getJson("/api/dashboards/{$id}")->assertNotFound();
    });
});
