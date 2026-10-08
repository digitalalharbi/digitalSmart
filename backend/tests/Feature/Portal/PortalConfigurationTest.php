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
| The client portal: deciding what a client can read
|--------------------------------------------------------------------------
|
| Every write on this surface changes what somebody outside the agency can see, so the tests are mostly about the
| record it leaves and the things it refuses.
|
| The four that matter most: a portal opens exposing nothing; exposing a module writes its own audit entry naming
| the module rather than one entry naming a set; a suspension cannot exist without a reason and that reason never
| reaches the client; and a surface this product has decided never to expose cannot be exposed by a payload.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function portalScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'name' => 'Alhambra',
    ]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @return array<string, mixed>
 */
function enablePortal(array $setup, ?User $as = null): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['scenario']->owner)
        ->postJson('/api/portals/'.$setup['client']->getKey().'/enable')
        ->assertOk()
        ->json('data');
}

/**
 * @param  array<string, mixed>  $portal
 * @return array<string, mixed>
 */
function moduleOf(array $portal, string $module): array
{
    /** @var list<array<string, mixed>> $modules */
    $modules = $portal['modules'];

    foreach ($modules as $entry) {
        if ($entry['module'] === $module) {
            return $entry;
        }
    }

    throw new RuntimeException(sprintf('The portal carried no "%s" module.', $module));
}

describe('what can be exposed at all', function (): void {
    it('publishes both the modules it can expose and the surfaces it never will', function (): void {
        $scenario = WorkspaceScenario::create();

        $vocabulary = test()->actingAs($scenario->owner)
            ->getJson('/api/portals/vocabulary')
            ->assertOk()
            ->json('data');

        /** @var list<array<string, mixed>> $modules */
        $modules = $vocabulary['modules'];
        /** @var list<array<string, mixed>> $withheld */
        $withheld = $vocabulary['withheld'];

        expect(array_column($modules, 'module'))
            ->toEqualCanonicalizing(['performance', 'reports', 'store_revenue', 'tracking']);

        /*
         * And the third list: the client's own intake thread, which is not a setting because a person is entitled
         * to read a conversation they are part of. Published beside the other two so the reason travels with it.
         */
        /** @var list<array<string, mixed>> $always */
        $always = $vocabulary['always_available'];
        expect(array_column($always, 'module'))->toBe(['requests']);

        /*
         * Shown rather than hidden, with the reason. A configuration screen listing only what *can* be exposed
         * makes every refusal look like a feature nobody got round to, and that question then gets asked once a
         * quarter forever.
         */
        expect(array_column($withheld, 'module'))->toContain('budgets', 'tasks', 'approvals', 'leads');

        foreach ($withheld as $entry) {
            expect($entry['because'])->toBeString();
            expect($entry['because'])->not->toBe('');
        }
    });

    it('refuses to expose a surface that is not on the list', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        test()->actingAs($setup['scenario']->owner)
            ->putJson('/api/portals/'.$setup['client']->getKey().'/modules', [
                'modules' => ['budgets'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['modules.0']);
    });
});

describe('switching a portal on', function (): void {
    it('reports a client with no portal rather than answering not found', function (): void {
        $setup = portalScenario();

        $portal = test()->actingAs($setup['scenario']->owner)
            ->getJson('/api/portals/'.$setup['client']->getKey())
            ->assertOk()
            ->json('data');

        /*
         * The absence of a portal is a state worth rendering. A 404 would make "has anybody set this up?" a
         * question answered by an error code, and the surface could not show the thing it exists to show.
         */
        expect($portal['state'])->toBe('not_configured');
        expect($portal['enabled_at'])->toBeNull();

        foreach ($portal['modules'] as $entry) {
            expect($entry['is_exposed'])->toBeFalse();
        }
    });

    it('opens exposing nothing, and says so in the record', function (): void {
        $setup = portalScenario();
        $portal = enablePortal($setup);

        expect($portal['state'])->toBe('active');
        expect($portal['enabled_by_name'])->toBe($setup['scenario']->owner->name);

        foreach ($portal['modules'] as $entry) {
            expect($entry['is_exposed'])->toBeFalse();
        }

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('action', AuditAction::PortalEnabled->value)->sole();

        expect($entry->actor_id)->toBe($setup['scenario']->owner->getKey());
        expect($entry->auditable_type)->toBe('client_portal');

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes ?? [];

        /* Stated rather than implied, so the entry proves the portal opened closed. */
        expect($changes['exposed_modules'])->toBe([]);
        expect($changes['client_id'])->toBe((string) $setup['client']->getKey());
    });

    it('is silent when the portal is already on', function (): void {
        $setup = portalScenario();
        enablePortal($setup);
        enablePortal($setup);

        // Nothing changed, so nothing is recorded: a trail that logged this would bury the real entries.
        expect(AuditLog::query()->where('action', AuditAction::PortalEnabled->value)->count())->toBe(1);
    });
});

describe('exposing a module', function (): void {
    it('records one entry per module, naming it', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $portal = test()->actingAs($setup['scenario']->owner)
            ->putJson('/api/portals/'.$setup['client']->getKey().'/modules', [
                'modules' => ['performance', 'reports'],
            ])
            ->assertOk()
            ->json('data');

        expect(moduleOf($portal, 'performance')['is_exposed'])->toBeTrue();
        expect(moduleOf($portal, 'reports')['is_exposed'])->toBeTrue();
        expect(moduleOf($portal, 'tracking')['is_exposed'])->toBeFalse();

        /*
         * Two entries, each naming its module. One entry saying "the set is now performance, reports" cannot
         * answer "when did they get the revenue figures", which is the question this trail is kept for.
         */
        $entries = AuditLog::query()
            ->where('action', AuditAction::PortalModuleExposed->value)
            ->get();

        expect($entries)->toHaveCount(2);

        $modules = $entries
            ->map(static fn (AuditLog $log): string => (string) (($log->changes ?? [])['module'] ?? ''))
            ->all();

        expect($modules)->toEqualCanonicalizing(['performance', 'reports']);
    });

    it('says whether the module had anything behind it when it was exposed', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        test()->actingAs($setup['scenario']->owner)
            ->putJson('/api/portals/'.$setup['client']->getKey().'/modules', [
                'modules' => ['performance'],
            ])
            ->assertOk();

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('action', AuditAction::PortalModuleExposed->value)->sole();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes ?? [];

        /*
         * This client has no advertising account bound to any project, so the module would show a client an empty
         * page. The entry says somebody was told so at the moment they exposed it.
         */
        expect($changes['had_data_behind_it'])->toBeFalse();
    });

    it('leads with how many exposed modules can show nothing', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $portal = test()->actingAs($setup['scenario']->owner)
            ->putJson('/api/portals/'.$setup['client']->getKey().'/modules', [
                'modules' => ['performance', 'store_revenue', 'reports'],
            ])
            ->assertOk()
            ->json('data');

        /* Reports needs nothing to exist first; the other two do, and neither exists for this client. */
        expect($portal['empty_count'])->toBe(2);
        expect(moduleOf($portal, 'reports')['is_prerequisite_met'])->toBeTrue();
        expect(moduleOf($portal, 'store_revenue')['prerequisite'])->toBe('a connected store');
    });

    it('withdraws what is no longer in the set, and records that separately', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $url = '/api/portals/'.$setup['client']->getKey().'/modules';

        test()->actingAs($setup['scenario']->owner)
            ->putJson($url, ['modules' => ['performance', 'reports']])
            ->assertOk();

        $portal = test()->actingAs($setup['scenario']->owner)
            ->putJson($url, ['modules' => ['reports']])
            ->assertOk()
            ->json('data');

        expect(moduleOf($portal, 'performance')['is_exposed'])->toBeFalse();
        expect(moduleOf($portal, 'reports')['is_exposed'])->toBeTrue();

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('action', AuditAction::PortalModuleWithdrawn->value)->sole();

        expect((($entry->changes ?? [])['module'] ?? null))->toBe('performance');
    });

    it('records nothing when the set is submitted unchanged', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $url = '/api/portals/'.$setup['client']->getKey().'/modules';

        test()->actingAs($setup['scenario']->owner)->putJson($url, ['modules' => ['reports']])->assertOk();
        test()->actingAs($setup['scenario']->owner)->putJson($url, ['modules' => ['reports']])->assertOk();

        /* Saving a form unchanged is not a disclosure. A trail that logged it would bury the real entries. */
        expect(AuditLog::query()->where('action', AuditAction::PortalModuleExposed->value)->count())->toBe(1);
    });

    it('accepts an empty set as the way to expose nothing', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $url = '/api/portals/'.$setup['client']->getKey().'/modules';

        test()->actingAs($setup['scenario']->owner)->putJson($url, ['modules' => ['reports']])->assertOk();

        $portal = test()->actingAs($setup['scenario']->owner)
            ->putJson($url, ['modules' => []])
            ->assertOk()
            ->json('data');

        expect($portal['state'])->toBe('active');

        foreach ($portal['modules'] as $entry) {
            expect($entry['is_exposed'])->toBeFalse();
        }
    });

    it('refuses to configure a portal that is not switched on', function (): void {
        $setup = portalScenario();

        test()->actingAs($setup['scenario']->owner)
            ->putJson('/api/portals/'.$setup['client']->getKey().'/modules', ['modules' => ['reports']])
            ->assertNotFound();

        /* Choosing what a closed portal would expose configures a disclosure nobody has authorised. */
        expect(DB::table('client_portal_modules')->count())->toBe(0);
    });
});

describe('switching a portal off', function (): void {
    it('needs a reason', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/portals/'.$setup['client']->getKey().'/suspend', ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    });

    it('keeps the module grants, and names what stopped being readable', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $url = '/api/portals/'.$setup['client']->getKey();

        test()->actingAs($setup['scenario']->owner)
            ->putJson($url.'/modules', ['modules' => ['reports', 'tracking']])
            ->assertOk();

        $portal = test()->actingAs($setup['scenario']->owner)
            ->postJson($url.'/suspend', ['reason' => 'Engagement paused while the contract is renewed.'])
            ->assertOk()
            ->json('data');

        expect($portal['state'])->toBe('suspended');
        expect($portal['suspended_by_name'])->toBe($setup['scenario']->owner->name);

        /*
         * The grants survive. They are what the portal goes back to, and they are the answer to "what could they
         * see while it was on" — clearing them would make resuming an act of reconstruction from memory.
         */
        expect(moduleOf($portal, 'reports')['is_exposed'])->toBeTrue();
        expect(DB::table('client_portal_modules')->count())->toBe(2);

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('action', AuditAction::PortalSuspended->value)->sole();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes ?? [];

        expect($changes['reason'])->toBe('Engagement paused while the contract is renewed.');
        /* Named, not counted. "Two modules" does not answer the question this entry is kept for. */
        expect($changes['was_exposing'])->toEqualCanonicalizing(['reports', 'tracking']);
    });

    it('refuses to suspend a portal twice', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $url = '/api/portals/'.$setup['client']->getKey().'/suspend';

        test()->actingAs($setup['scenario']->owner)->postJson($url, ['reason' => 'Dispute opened.'])->assertOk();

        test()->actingAs($setup['scenario']->owner)
            ->postJson($url, ['reason' => 'Dispute opened.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_suspended');
    });

    it('resumes to exactly what it was exposing, and says so', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $url = '/api/portals/'.$setup['client']->getKey();

        test()->actingAs($setup['scenario']->owner)
            ->putJson($url.'/modules', ['modules' => ['reports']])
            ->assertOk();
        test()->actingAs($setup['scenario']->owner)
            ->postJson($url.'/suspend', ['reason' => 'Figures under review.'])
            ->assertOk();

        $portal = test()->actingAs($setup['scenario']->owner)
            ->postJson($url.'/resume')
            ->assertOk()
            ->json('data');

        expect($portal['state'])->toBe('active');
        expect(moduleOf($portal, 'reports')['is_exposed'])->toBeTrue();

        /* And the reason is gone: a reason left on an active portal reads as a current one. */
        expect($portal['suspended_reason'])->toBeNull();
        expect($portal['suspended_at'])->toBeNull();

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('action', AuditAction::PortalResumed->value)->sole();

        expect((($entry->changes ?? [])['exposing'] ?? null))->toBe(['reports']);
    });

    it('refuses to resume a portal that is not switched off', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/portals/'.$setup['client']->getKey().'/resume')
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_suspended');
    });
});

describe('who may change it', function (): void {
    it('lets a buyer read what a client can see and not change it', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        /*
         * Reads: a buyer answering a question about this week's figures needs to know whether the client can
         * already see them.
         */
        test()->actingAs($buyer)
            ->getJson('/api/portals/'.$setup['client']->getKey())
            ->assertOk()
            ->assertJsonPath('data.state', 'active');

        // And does not widen it. That is the account owner's decision.
        test()->actingAs($buyer)
            ->putJson('/api/portals/'.$setup['client']->getKey().'/modules', ['modules' => ['reports']])
            ->assertForbidden();
    });

    it('keeps the portal surface away from a creative entirely', function (): void {
        $setup = portalScenario();
        $creative = $setup['scenario']->addMember(SystemRole::Creative);

        test()->actingAs($creative)->getJson('/api/portals')->assertForbidden();
    });

    it('keeps a client out of the agency\'s own configuration of their portal', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $viewer = $setup['scenario']->addMember(SystemRole::ClientViewer);
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $viewer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::ClientViewer)->getKey(),
        ]);

        /*
         * Their own portal, and still not theirs to read about. This surface carries the suspension reason and
         * who exposed what — the agency's own record of a decision about them.
         */
        test()->actingAs($viewer)
            ->getJson('/api/portals/'.$setup['client']->getKey())
            ->assertForbidden();
    });

    it('narrows the list to the clients a reader can see', function (): void {
        $setup = portalScenario();
        $other = Client::factory()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'name' => 'Another client',
        ]);
        Project::factory()->forClient($other)->create();

        /*
         * A buyer, because a project manager holds `projects.view_all` and would legitimately see both. The
         * narrowing being tested is the one that applies to a reader without it: a portal is about a client, and
         * the rule is expressed through that client's projects.
         */
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        $names = test()->actingAs($buyer)
            ->getJson('/api/portals')
            ->assertOk()
            ->json('data.*.client_name');

        expect($names)->toBe(['Alhambra']);
    });

    it('lists every visible client, including the ones with no portal', function (): void {
        $setup = portalScenario();
        $other = Client::factory()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'name' => 'Not set up yet',
        ]);
        Project::factory()->forClient($other)->create();

        enablePortal($setup);

        $rows = test()->actingAs($setup['scenario']->owner)
            ->getJson('/api/portals')
            ->assertOk()
            ->json('data');

        /*
         * The question an agency asks before a quarterly review is which clients still cannot see anything, and a
         * list built from portal rows could never answer it.
         */
        /** @var list<array<string, mixed>> $rows */
        $byName = [];

        foreach ($rows as $row) {
            $byName[(string) $row['client_name']] = $row['state'];
        }

        expect($byName)->toBe(['Alhambra' => 'active', 'Not set up yet' => 'not_configured']);
    });
});

describe('the disclosure trail', function (): void {
    it('files every portal change as a security event', function (): void {
        $setup = portalScenario();
        enablePortal($setup);

        $url = '/api/portals/'.$setup['client']->getKey();

        test()->actingAs($setup['scenario']->owner)
            ->putJson($url.'/modules', ['modules' => ['reports']])
            ->assertOk();
        test()->actingAs($setup['scenario']->owner)
            ->postJson($url.'/suspend', ['reason' => 'Contract under review.'])
            ->assertOk();

        /*
         * These are data-access grants in everything but name, and they belong beside "who connected a platform"
         * rather than beside "who renamed a report".
         */
        $actions = [
            AuditAction::PortalEnabled,
            AuditAction::PortalModuleExposed,
            AuditAction::PortalSuspended,
        ];

        foreach ($actions as $action) {
            expect($action->isSecurityEvent())->toBeTrue();
            expect(AuditLog::query()->where('action', $action->value)->exists())->toBeTrue();
        }
    });
});
