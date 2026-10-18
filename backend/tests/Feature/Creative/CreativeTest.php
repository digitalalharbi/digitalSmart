<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Database\Seeders\CreativeSpecSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Creative operations
|--------------------------------------------------------------------------
|
| The tests that matter here are two refusals and one rule.
|
| The refusals: a specification check will not say "valid" about anything it did not measure, and a rejection
| will not be recorded without saying what to change.
|
| The rule: **an approval belongs to a version.** A new version onto approved work sends it back for review,
| and the earlier approval stays attached to the file it was about. A tool that got this wrong would have an
| approval trail claiming the newest work was signed off by somebody who never saw it.
|
*/

/*
 * The recorded specifications are seeded, because they are product knowledge rather than demo data — the
 * same rows that ship to production. A test suite that checked against an empty registry would only ever
 * exercise the `no_spec` branch, which is the one that needs no code.
 */
beforeEach(function (): void {
    test()->seed(CreativeSpecSeeder::class);
});

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function creativeScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * Brief a piece of work and return its id.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $overrides
 */
function briefWork(array $setup, array $overrides = []): string
{
    /** @var string */
    return test()->actingAs($setup['scenario']->owner)
        ->postJson('/api/creative', [
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'title' => 'Ramadan hero image',
            'kind' => 'image',
            'provider' => 'snapchat',
            'placement' => 'story_ad_preview_image',
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data.id');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $measurements
 * @return array<string, mixed>
 */
function addVersion(array $setup, string $requestId, array $measurements = []): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/creative/{$requestId}/versions", [
            'kind' => 'image',
            'label' => 'First cut',
            'storage_path' => 'creative/first.png',
            ...$measurements,
        ])
        ->assertCreated()
        ->json('data');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @return array<string, mixed>
 */
function readRequest(array $setup, string $requestId): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($setup['scenario']->owner)
        ->getJson("/api/creative/{$requestId}")
        ->assertOk()
        ->json('data');
}

/**
 * Put somebody on the project, which is what makes the work visible to them.
 *
 * A creative who is not on the project genuinely cannot see it, which is correct and is why this helper
 * exists rather than the visibility rule being relaxed.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 */
function assignToProject(array $setup, User $user, SystemRole $role): void
{
    ProjectMember::query()->create([
        'project_id' => $setup['project']->getKey(),
        'user_id' => $user->getKey(),
        'role_id' => $setup['scenario']->role($role)->getKey(),
    ]);
}

/**
 * Take work to the point where a version can be decided on.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @return array{id: string, version: string}
 */
function workInReview(array $setup): array
{
    $id = briefWork($setup);
    $owner = $setup['scenario']->owner;

    test()->actingAs($owner)->postJson("/api/creative/{$id}/state", ['state' => 'briefed']);
    test()->actingAs($owner)->postJson("/api/creative/{$id}/state", ['state' => 'in_production']);

    $data = addVersion($setup, $id, [
        'width_px' => 360,
        'height_px' => 600,
        'format' => 'png',
        'file_size_bytes' => 500_000,
    ]);

    test()->actingAs($owner)->postJson("/api/creative/{$id}/state", ['state' => 'in_review']);

    return ['id' => $id, 'version' => (string) $data['versions'][0]['id']];
}

describe('the board', function (): void {
    it('publishes its own vocabulary so a client never carries the rules', function (): void {
        $setup = creativeScenario();

        /** @var array<string, mixed> $data */
        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/creative/vocabulary')
            ->assertOk()
            ->json('data');

        $byState = [];

        foreach ($data['states'] as $state) {
            $byState[(string) $state['state']] = $state;
        }

        /*
         * A board carrying its own copy of the transitions would eventually offer a drop target the server
         * refuses, and the person dragging the card would see an error rather than a disabled column.
         */
        expect($byState['draft']['allowed_next'])->toBe(['briefed', 'cancelled'])
            ->and($byState['approved']['requires_re_review'])->toBeTrue()
            ->and($byState['in_production']['requires_re_review'])->toBeFalse();
    });

    it('shows every column, including the empty ones', function (): void {
        $setup = creativeScenario();
        briefWork($setup);

        /** @var array<string, mixed> $board */
        $board = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/creative/board')
            ->assertOk()
            ->json('data');

        // A board whose columns appear and disappear with the work is one nobody can learn the shape of.
        expect($board['columns'])->toHaveCount(7)
            ->and($board['open'])->toBe(1)
            ->and($board['waiting_on_review'])->toBe(0);
    });

    it('gives each card the count and shape of its newest file without loading the history', function (): void {
        $setup = creativeScenario();
        workInReview($setup);

        /** @var array<string, mixed> $board */
        $board = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/creative/board')
            ->assertOk()
            ->json('data');

        $cards = array_merge(...array_map(static fn (array $column): array => $column['requests'], $board['columns']));

        // Unmeasured stays null elsewhere; here the file was measured, so the card can draw its true shape.
        expect($cards)->toHaveCount(1)
            ->and($cards[0]['version_count'])->toBe(1)
            ->and($cards[0]['preview'])->toBe(['width_px' => 360, 'height_px' => 600, 'format' => 'png']);
    });

    it('refuses an illegal move and says where the work can go', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);

        $response = $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/state", ['state' => 'in_review'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_transition');

        /*
         * "Briefed work goes into production first" is something a person fixes themselves. "Invalid
         * transition" is something they file a bug about.
         */
        expect($response->json('allowed'))->toBe(['briefed', 'cancelled']);
    });

    it('will not let the board approve anything', function (): void {
        $setup = creativeScenario();
        $work = workInReview($setup);

        /*
         * An approval needs a version and a decider. A column drag has neither, so the trail would record
         * that somebody approved something without saying who approved what.
         */
        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$work['id']}/state", ['state' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_transition');
    });

    it('refuses to cancel work without saying why', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);

        // "We decided not to make this" is a decision somebody will be asked about in three months.
        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/state", ['state' => 'cancelled'])
            ->assertUnprocessable();

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/state", [
                'state' => 'cancelled',
                'reason' => 'Client pulled the campaign.',
            ])
            ->assertOk();

        expect(readRequest($setup, $id)['cancelled_reason'])->toBe('Client pulled the campaign.');
    });
});

describe('what a spec check will not claim', function (): void {
    it('checks an asset against a specification somebody actually recorded', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);

        $data = addVersion($setup, $id, [
            'width_px' => 360,
            'height_px' => 600,
            'format' => 'png',
            'file_size_bytes' => 500_000,
        ]);

        $spec = $data['versions'][0]['spec'];

        /*
         * The Snapchat numbers come from their own developer documentation, recorded with the page and the
         * date. Every verdict carries that source, because a specification nobody can trace is folklore.
         */
        expect($spec['verdict'])->toBe('pass')
            ->and($spec['source']['url'])->toContain('developers.snap.com')
            ->and($spec['source']['documented_at'])->not->toBeNull();
    });

    it('reports an unmeasured property as not checked, never as a pass', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);

        // A file whose dimensions this product could not read.
        $data = addVersion($setup, $id, [
            'format' => 'png',
            'file_size_bytes' => 500_000,
            'unmeasured_reason' => 'The file could not be opened.',
        ]);

        $spec = $data['versions'][0]['spec'];

        /*
         * The whole set is unchecked, not "mostly fine". A reader deciding whether to publish needs the floor
         * of what was established, and a green tick over a set containing an unknown is the lie this layer
         * exists to prevent.
         */
        expect($spec['verdict'])->toBe('unchecked')
            ->and($spec['blocks_publishing'])->toBeFalse();

        $unchecked = array_values(array_filter(
            $spec['checks'],
            static fn (array $check): bool => $check['verdict'] === 'unchecked',
        ));

        expect($unchecked)->not->toBeEmpty()
            ->and($unchecked[0]['detail'])->toBe('The file could not be opened.');
    });

    it('fails a measured breach and says both numbers', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);

        $data = addVersion($setup, $id, [
            'width_px' => 200,
            'height_px' => 333,
            'format' => 'png',
            'file_size_bytes' => 500_000,
        ]);

        $spec = $data['versions'][0]['spec'];
        $width = array_values(array_filter(
            $spec['checks'],
            static fn (array $check): bool => $check['requirement'] === 'width',
        ))[0];

        /*
         * "200 against at least 360 px" is actionable. "Fails width" is not — and only a measured failure
         * blocks a publish, because blocking on this product's own gaps teaches people to click past
         * warnings.
         */
        expect($spec['verdict'])->toBe('fail')
            ->and($spec['blocks_publishing'])->toBeTrue()
            ->and($width['measured'])->toBe('200')
            ->and($width['expected'])->toBe('at least 360 px');
    });

    it('says no spec recorded rather than passing a placement nobody documented', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup, ['provider' => 'meta', 'placement' => 'reels']);

        $data = addVersion($setup, $id, ['width_px' => 1080, 'height_px' => 1920, 'format' => 'png']);
        $spec = $data['versions'][0]['spec'];

        /*
         * The commonest outcome, and the honest one: the platforms do not publish their requirements in a
         * form a program can read, so most placements have none recorded — and saying so tells an agency
         * which placements their work goes out on unchecked.
         */
        expect($spec['verdict'])->toBe('no_spec')
            ->and($spec['checks'])->toBe([])
            ->and($spec['blocks_publishing'])->toBeFalse();
    });

    it('says no spec when nobody has decided where the work is going', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup, ['provider' => '', 'placement' => '']);

        $data = addVersion($setup, $id, ['width_px' => 1080, 'height_px' => 1920]);

        // A separate statement from "we have no spec for that placement", and both are true here.
        expect($data['versions'][0]['spec']['verdict'])->toBe('no_spec')
            ->and($data['versions'][0]['spec']['placement'])->toBe('unassigned');
    });

    it('publishes which placements it can check at all', function (): void {
        $setup = creativeScenario();

        /** @var list<array<string, mixed>> $specs */
        $specs = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/creative/specs')
            ->assertOk()
            ->json('data');

        /*
         * A page in its own right. The list of placements this product *cannot* check is the longer one, and
         * an agency needs to know which of their work goes out without a safety net.
         */
        expect($specs)->not->toBeEmpty();

        foreach ($specs as $spec) {
            expect($spec['source']['url'])->not->toBe('');
        }
    });
});

describe('an approval belongs to a version', function (): void {
    it('records the decision against the version, with the decider', function (): void {
        $setup = creativeScenario();
        $work = workInReview($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative-versions/{$work['version']}/decision", ['outcome' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.state', 'approved');

        $data = readRequest($setup, $work['id']);

        expect($data['versions'][0]['is_approved'])->toBeTrue()
            ->and($data['versions'][0]['decided_by_name'])->not->toBeNull()
            ->and($data['newest_is_approved'])->toBeTrue();
    });

    it('sends work back for review when a new version lands on an approval', function (): void {
        $setup = creativeScenario();
        $work = workInReview($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative-versions/{$work['version']}/decision", ['outcome' => 'approved'])
            ->assertOk();

        addVersion($setup, $work['id'], [
            'width_px' => 360,
            'height_px' => 600,
            'format' => 'png',
            'file_size_bytes' => 400_000,
        ]);

        $data = readRequest($setup, $work['id']);

        /*
         * The rule this module exists for. A client approved a file; the next file is unapproved until
         * somebody says otherwise. A tool that left the request approved would have a trail claiming the
         * newest work was signed off by somebody who never saw it.
         */
        expect($data['state'])->toBe('in_review')
            ->and($data['newest_is_approved'])->toBeFalse()
            // And the earlier approval stays attached to the file it was about.
            ->and($data['versions'][0]['is_approved'])->toBeTrue()
            ->and($data['versions'][1]['is_approved'])->toBeFalse();
    });

    it('refuses to send work back without saying what to change', function (): void {
        $setup = creativeScenario();
        $work = workInReview($setup);

        // The note is the only record of what the reviewer objected to; the next version is a guess without it.
        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative-versions/{$work['version']}/decision", ['outcome' => 'changes_requested'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_transition');

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative-versions/{$work['version']}/decision", [
                'outcome' => 'changes_requested',
                'reason' => 'The logo is cropped on the right.',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'changes_requested');

        expect(readRequest($setup, $work['id'])['versions'][0]['decision_reason'])
            ->toBe('The logo is cropped on the right.');
    });

    it('keeps both decisions when a reviewer changes their mind', function (): void {
        $setup = creativeScenario();
        $work = workInReview($setup);
        $owner = $setup['scenario']->owner;

        $this->actingAs($owner)->postJson("/api/creative-versions/{$work['version']}/decision", [
            'outcome' => 'changes_requested',
            'reason' => 'Too dark.',
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/creative-versions/{$work['version']}/decision", [
            'outcome' => 'approved',
        ])->assertOk();

        /*
         * Two decisions, both kept, and the one that stands is the last. Overwriting the first would lose the
         * fact that this file was ever rejected, which is half of what a trail is for.
         */
        expect(DB::table('creative_approvals')->count())->toBe(2)
            ->and(readRequest($setup, $work['id'])['versions'][0]['decision'])->toBe('approved');
    });

    it('names the version in the audit trail', function (): void {
        $setup = creativeScenario();
        $work = workInReview($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative-versions/{$work['version']}/decision", ['outcome' => 'approved'])
            ->assertOk();

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('action', AuditAction::CreativeDecided->value)->firstOrFail();

        /*
         * "Approved" with no version number is the claim a client disputes three months later and nobody can
         * settle.
         */
        expect($entry->getAttribute('changes')['version'] ?? null)->toBe(1)
            ->and((string) $entry->getAttribute('summary'))->toContain('version 1');
    });

    it('numbers versions from one, in order', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);

        addVersion($setup, $id);
        $data = addVersion($setup, $id);

        // Shown to clients, so it counts from one rather than from zero.
        expect(array_column($data['versions'], 'number'))->toBe([1, 2]);
    });
});

describe('who sees a comment', function (): void {
    it('refuses a comment with no stated audience', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);

        /*
         * No default. An agency's internal note on a client's creative reaching that client ends a
         * relationship, and a default is exactly how it would happen.
         */
        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/comments", ['body' => 'Looks good'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_visibility');
    });

    it('marks an internal note as not for the client', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/comments", [
                'body' => 'The client hated the last one, keep it safe.',
                'visibility' => 'internal',
            ])
            ->assertCreated();

        $comment = readRequest($setup, $id)['comments'][0];

        expect($comment['visibility'])->toBe('internal')
            ->and($comment['is_visible_to_client'])->toBeFalse()
            ->and($comment['author_name'])->not->toBeNull();
    });
});

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/creative/board')->assertUnauthorized();
        $this->getJson('/api/creative/vocabulary')->assertUnauthorized();
    });

    it('lets a creative produce work and not approve it', function (): void {
        $setup = creativeScenario();
        $work = workInReview($setup);
        $creative = $setup['scenario']->addMember(SystemRole::Creative);
        assignToProject($setup, $creative, SystemRole::Creative);

        // Uploads all day.
        $this->actingAs($creative)
            ->postJson("/api/creative/{$work['id']}/versions", [
                'kind' => 'image',
                'width_px' => 360,
                'height_px' => 600,
            ])
            ->assertCreated();

        /*
         * And signs nothing off. Producing the work and approving it are different acts, and the separation
         * is the only thing that makes "approved" mean anything on a trail.
         */
        $this->actingAs($creative)
            ->postJson("/api/creative-versions/{$work['version']}/decision", ['outcome' => 'approved'])
            ->assertForbidden();
    });

    it('lets a media buyer read the work and change nothing', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        assignToProject($setup, $buyer, SystemRole::MediaBuyer);

        /*
         * A buyer reads the creative that will run on their campaigns. A buyer approving the work they asked
         * for removes the only check on it.
         */
        $this->actingAs($buyer)->getJson('/api/creative/board')->assertOk();

        $this->actingAs($buyer)
            ->postJson("/api/creative/{$id}/state", ['state' => 'briefed'])
            ->assertForbidden();
    });

    it('refuses a client viewer outright', function (): void {
        $setup = creativeScenario();
        $client = $setup['scenario']->addMember(SystemRole::ClientViewer);

        /*
         * The creative board carries internal notes and unapproved work. A client sees what they were sent,
         * through the portal — a different surface with a different filter.
         */
        $this->actingAs($client)->getJson('/api/creative/board')->assertForbidden();
    });

    it('does not reach another workspace', function (): void {
        $mine = creativeScenario();
        $theirs = creativeScenario();
        $id = briefWork($theirs);

        $this->actingAs($mine['scenario']->owner)->getJson("/api/creative/{$id}")->assertNotFound();
    });
});

describe('who is making the work', function (): void {
    /*
     * `creative_requests.owner_id` could be set when a request was opened and **never afterwards**: written once
     * by the composer, read for ever by the board. A creative hub where work cannot change hands is a list.
     */
    it('hands a brief to somebody who can open it', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);
        $creative = $setup['scenario']->addMember(SystemRole::Creative);
        assignToProject($setup, $creative, SystemRole::Creative);

        $people = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/creative/{$id}/owners")
            ->assertOk()
            ->json('data');

        expect(array_column($people, 'id'))->toContain((string) $creative->getKey())
            /* Ids and names. A picker needs no email addresses. */
            ->and(array_keys($people[0]))->toEqualCanonicalizing(['id', 'name']);

        $data = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/owner", ['owner_id' => (string) $creative->getKey()])
            ->assertOk()
            ->json('data');

        expect($data['owner_id'])->toBe((string) $creative->getKey())
            ->and(AuditLog::query()->where('action', AuditAction::CreativeOwnerChanged->value)->count())
            ->toBe(1);
    });

    /* Taking it back out of somebody's name is a first-class outcome. */
    it('takes a brief back out of somebody’s name', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);
        $creative = $setup['scenario']->addMember(SystemRole::Creative);
        assignToProject($setup, $creative, SystemRole::Creative);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/owner", ['owner_id' => (string) $creative->getKey()])
            ->assertOk();

        expect(test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/owner", ['owner_id' => ''])
            ->assertOk()
            ->json('data.owner_id'))->toBeNull();
    });

    /*
     * A brief carries a client's campaign and often their unreleased plans, so an owner whose projects do not
     * reach that client would be a name on work they can never see — and the same rule hides it from them.
     */
    it('refuses an owner who could not open the brief', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);
        $elsewhere = $setup['scenario']->addMember(SystemRole::Creative);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/creative/{$id}/owner", ['owner_id' => (string) $elsewhere->getKey()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cannot_be_assigned');
    });

    /* And at brief time, not only when work changes hands afterwards. */
    it('refuses to brief work already in such a person’s name', function (): void {
        $setup = creativeScenario();
        $elsewhere = $setup['scenario']->addMember(SystemRole::Creative);

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/creative', [
                'client_id' => (string) $setup['client']->getKey(),
                'project_id' => (string) $setup['project']->getKey(),
                'title' => 'Work for somebody who cannot see it',
                'kind' => 'image',
                'owner_id' => (string) $elsewhere->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cannot_be_assigned');
    });

    it('is not a media buyer’s to hand out', function (): void {
        $setup = creativeScenario();
        $id = briefWork($setup);
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        assignToProject($setup, $buyer, SystemRole::MediaBuyer);

        test()->actingAs($buyer)
            ->postJson("/api/creative/{$id}/owner", ['owner_id' => (string) $buyer->getKey()])
            ->assertForbidden();
    });
});

it('narrows the board to one project when asked', function (): void {
    $setup = creativeScenario();
    $other = Project::factory()->forClient($setup['client'])->create(['name' => 'Other']);
    $owner = $setup['scenario']->owner;

    briefWork($setup);
    test()->actingAs($owner)->postJson('/api/creative', [
        'client_id' => (string) $setup['client']->getKey(),
        'project_id' => (string) $other->getKey(),
        'title' => 'Elsewhere',
        'kind' => 'image',
    ])->assertCreated();

    $board = test()->actingAs($owner)
        ->getJson('/api/creative/board?project_id='.$other->getKey())
        ->assertOk()
        ->json('data');
    $titles = array_merge(...array_map(static fn (array $column): array => array_column($column['requests'], 'title'), $board['columns']));

    expect($titles)->toBe(['Elsewhere']);
});
