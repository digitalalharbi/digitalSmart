<?php

declare(strict_types=1);

use App\Application\Portal\Query\GetPortalPerformance;
use App\Domain\Access\SystemRole;
use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| The client portal: what a client actually reads
|--------------------------------------------------------------------------
|
| Asserted against data the real sync wrote from the SANDBOX fixtures, so a client's figures are the figures the
| pipeline produces — including the source label that says they are not production data.
|
| The four that matter most: nothing is readable until the agency exposed it; a module that was not exposed answers
| 404 rather than 403, because a client told a surface about them is being withheld has been told about the
| agency's intentions; the agency's suspension reason never reaches the client in any form; and an internal report
| is invisible here whatever the portal says.
|
*/

/** @return array{workspace: SyncedWorkspace, clientId: string, viewer: User} */
function portalReadScenario(): array
{
    $workspace = SyncedWorkspace::default();
    $project = $workspace->project(Provider::Meta);

    /*
     * The client's own staff member, on the client's project. That assignment is their identity in this product:
     * there is no "client id" column on a user to be set wrongly.
     */
    $viewer = $workspace->scenario->addMember(SystemRole::ClientViewer);

    ProjectMember::query()->create([
        'project_id' => $project->getKey(),
        'user_id' => $viewer->getKey(),
        'role_id' => $workspace->scenario->role(SystemRole::ClientViewer)->getKey(),
    ]);

    return [
        'workspace' => $workspace,
        'clientId' => (string) $project->client_id,
        'viewer' => $viewer,
    ];
}

/**
 * @param  array{workspace: SyncedWorkspace, clientId: string, viewer: User}  $setup
 * @param  list<string>  $modules
 */
function openPortal(array $setup, array $modules): void
{
    $owner = $setup['workspace']->scenario->owner;

    test()->actingAs($owner)->postJson('/api/portals/'.$setup['clientId'].'/enable')->assertOk();
    test()->actingAs($owner)
        ->putJson('/api/portals/'.$setup['clientId'].'/modules', ['modules' => $modules])
        ->assertOk();
}

describe('what a client is told they may read', function (): void {
    it('says the portal has not been set up rather than returning nothing', function (): void {
        $setup = portalReadScenario();

        $entries = test()->actingAs($setup['viewer'])
            ->getJson('/api/portal')
            ->assertOk()
            ->json('data');

        expect($entries)->toHaveCount(1);
        expect($entries[0]['state'])->toBe('not_configured');
        expect($entries[0]['modules'])->toBe([]);
        /*
         * An empty list renders as "nothing has happened yet". The sentence says whose decision it is and who to
         * ask about it.
         */
        expect($entries[0]['message'])->toContain('has not been set up');
        expect($entries[0]['is_preview'])->toBeFalse();
    });

    it('always offers the thread they write in, and says it is not a setting', function (): void {
        $setup = portalReadScenario();

        $entries = test()->actingAs($setup['viewer'])->getJson('/api/portal')->assertOk()->json('data');

        /*
         * A client writes half of this thread, and a person is entitled to read a conversation they are part of.
         * It is deliberately not a module — see `AlwaysAvailable`.
         */
        expect(array_column($entries[0]['always_available'], 'module'))->toBe(['requests']);
    });

    it('lists only what was exposed', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        $entries = test()->actingAs($setup['viewer'])->getJson('/api/portal')->assertOk()->json('data');

        expect($entries[0]['state'])->toBe('active');
        expect(array_column($entries[0]['modules'], 'module'))->toBe(['performance']);
        expect($entries[0]['message'])->toBeNull();
    });
});

describe('reading performance', function (): void {
    it('refuses a module nobody exposed, as a 404', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['reports']);

        /*
         * Not 403. A client told that a performance surface about their own account exists and is being withheld
         * has been told something about the agency's intentions rather than about the product.
         */
        test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/performance')
            ->assertNotFound();
    });

    it('shows the figures with the source state the pipeline produced', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        $data = test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/performance')
            ->assertOk()
            ->json('data');

        /*
         * Every provider in this repository is awaiting an authorization it cannot grant itself, so these are
         * sandbox figures — and a client reading them is told so, in those words, exactly as the agency is.
         */
        expect($data['source'])->toBe('sandbox');
        expect($data['requires_label'])->toBeTrue();
    });

    it('offers a fixed set of metrics a client cannot reshape', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        $data = test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/performance?metrics[]=reach&dimension=campaign')
            ->assertOk()
            ->json('data');

        $expected = array_map(static fn (Metric $metric): string => $metric->value, GetPortalPerformance::METRICS);

        expect(array_keys($data['totals']))->toEqualCanonicalizing($expected);

        /*
         * Reach asked for and not given. It counts deduplicated people and does not add up across campaigns, so
         * on a surface covering a whole account it would be either wrong or blank for a reason the client cannot
         * act on — and there is nobody from the agency present to explain either.
         */
        expect($data['totals'])->not->toHaveKey('reach');
    });

    it('names the baseline of every change figure', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        $data = test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/performance')
            ->assertOk()
            ->json('data');

        /* A change figure with no stated baseline is one somebody reads against whatever period they had in mind. */
        expect($data['comparison_period']['from'])->toBeString();
        expect($data['comparison_period']['until'])->toBeString();
        expect($data['comparison_period']['until'] < $data['period']['from'])->toBeTrue();
    });

    it('ends the period yesterday, because today is still accumulating', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        $data = test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/performance')
            ->assertOk()
            ->json('data');

        expect($data['period']['until'])->toBe(now()->subDay()->format('Y-m-d'));
        expect($data['period']['days'])->toBe(30);
    });
});

describe('reading reports', function (): void {
    it('never lists an internal report, and 404s a direct request for one', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['reports']);

        $owner = $setup['workspace']->scenario->owner;

        $internal = test()->actingAs($owner)->postJson('/api/reports', [
            'name' => 'Internal read',
            'client_id' => $setup['clientId'],
            'window' => 'last_30_days',
            'comparison' => 'none',
            'audience' => 'internal',
            'internal_note' => 'Do not mention the churn risk on the call.',
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
        ])->assertCreated()->json('data');

        $safe = test()->actingAs($owner)->postJson('/api/reports', [
            'name' => 'Monthly performance',
            'client_id' => $setup['clientId'],
            'window' => 'last_30_days',
            'comparison' => 'none',
            'audience' => 'client_safe',
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
        ])->assertCreated()->json('data');

        $listed = test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/reports')
            ->assertOk()
            ->json('data');

        expect(array_column($listed, 'name'))->toBe(['Monthly performance']);

        /* The same rule the share link follows: an internal report cannot be read by a client at all. */
        test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/reports/'.$internal['id'])
            ->assertNotFound();

        $rendered = test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/reports/'.$safe['id'])
            ->assertOk()
            ->json('data');

        /* And the commentary is not loaded on this path rather than loaded and dropped. */
        expect($rendered['internal_note'])->toBeNull();
    });
});

describe('reading revenue', function (): void {
    it('asks which authority is counting, rather than choosing for the client', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['store_revenue']);

        $body = test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/revenue')
            ->assertStatus(422)
            ->json();

        /*
         * A Salla total counts invoices and a GA4 total counts what a browser reported before the payment may
         * have failed. Choosing for the reader is how a portal shows one labelled as the other.
         */
        expect($body['code'])->toBe('revenue_source_required');
        expect($body['available'])->not->toBeEmpty();
    });
});

describe('a portal that was switched off', function (): void {
    it('tells the client it is off and never why', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson('/api/portals/'.$setup['clientId'].'/suspend', [
                'reason' => 'Payment overdue since September.',
            ])
            ->assertOk();

        $response = test()->actingAs($setup['viewer'])->getJson('/api/portal')->assertOk();

        /** @var list<array<string, mixed>> $entries */
        $entries = $response->json('data');

        expect($entries[0]['state'])->toBe('suspended');
        expect($entries[0]['modules'])->toBe([]);
        expect($entries[0]['message'])->toContain('contact your account manager');

        /*
         * The agency's own note is nowhere in the response. A client reading a commercial position on a portal
         * page is that conversation happening in the worst possible place.
         */
        $response->assertDontSee('Payment overdue', escape: false);
        $response->assertDontSee('overdue', escape: false);

        // And the surfaces close immediately, not at the end of a billing period.
        test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/performance')
            ->assertNotFound();
    });
});

describe('isolation', function (): void {
    it('reaches no client the reader has no project with', function (): void {
        $setup = portalReadScenario();
        $otherProject = $setup['workspace']->project(Provider::GoogleAds);
        $otherClientId = (string) $otherProject->client_id;

        $owner = $setup['workspace']->scenario->owner;
        test()->actingAs($owner)->postJson('/api/portals/'.$otherClientId.'/enable')->assertOk();
        test()->actingAs($owner)
            ->putJson('/api/portals/'.$otherClientId.'/modules', ['modules' => ['performance']])
            ->assertOk();

        /* Open, exposed, and not theirs. The index names one client and the direct read answers 404. */
        $entries = test()->actingAs($setup['viewer'])->getJson('/api/portal')->assertOk()->json('data');
        expect($entries)->toHaveCount(1);
        expect($entries[0]['client_id'])->toBe($setup['clientId']);

        test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$otherClientId.'/performance')
            ->assertNotFound();
    });

    it('no longer lets a client read the agency\'s own analytics', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        /*
         * The point of this phase. A client's access used to be `analytics.view` on a role every client shares —
         * which is not a decision anybody took about a particular client. It is now four module grants, each
         * exposed deliberately and revocable on its own.
         */
        test()->actingAs($setup['viewer'])->getJson('/api/analytics')->assertForbidden();
        test()->actingAs($setup['viewer'])->getJson('/api/dashboards')->assertForbidden();
    });
});

describe('an agency preview', function (): void {
    it('reads the client\'s own page, and says that is what it is', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        $entries = test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/portal')
            ->assertOk()
            ->json('data');

        /*
         * Reading it as the client would is the thing somebody should do before switching a module on, which is
         * the same argument the reporting module makes about a client-safe report.
         */
        /** @var list<array<string, mixed>> $entries */
        $entry = null;

        foreach ($entries as $candidate) {
            if ($candidate['client_id'] === $setup['clientId']) {
                $entry = $candidate;
            }
        }

        expect($entry)->not->toBeNull();
        expect($entry['is_preview'] ?? null)->toBeTrue();

        $data = test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/portal/'.$setup['clientId'].'/performance')
            ->assertOk()
            ->json('data');

        /* Flagged, so a preview cannot be mistaken for the agency's own view of the product. */
        expect($data['is_preview'])->toBeTrue();
    });

    it('is refused to agency staff without the portal permission', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        $creative = $setup['workspace']->scenario->addMember(SystemRole::Creative);

        ProjectMember::query()->create([
            'project_id' => $setup['workspace']->project(Provider::Meta)->getKey(),
            'user_id' => $creative->getKey(),
            'role_id' => $setup['workspace']->scenario->role(SystemRole::Creative)->getKey(),
        ]);

        /*
         * Assigned to the project and still not a reader of the portal. A creative holds neither the client's
         * identity nor the permission to preview, and the index is empty rather than refused: there is nothing
         * for them to read, which is a different fact from being denied.
         */
        expect(test()->actingAs($creative)->getJson('/api/portal')->assertOk()->json('data'))->toBe([]);

        test()->actingAs($creative)
            ->getJson('/api/portal/'.$setup['clientId'].'/performance')
            ->assertNotFound();
    });
});

describe('a client reaching the agency\'s own endpoints', function (): void {
    it('is refused before being told what it left out', function (): void {
        $setup = portalReadScenario();

        test()->actingAs($setup['viewer'])->getJson('/api/analytics')->assertForbidden();
        test()->actingAs($setup['viewer'])->getJson('/api/dashboards')->assertForbidden();

        /*
         * Found by an end-to-end test, which reported 422 where this expects 403: the commerce endpoint checked
         * its required `revenue_source` before it checked the permission, so a reader who may not see revenue at
         * all was told which parameter to add. A refusal naming a parameter is a statement that the endpoint
         * exists and would answer.
         */
        test()->actingAs($setup['viewer'])->getJson('/api/commerce/analytics')->assertForbidden();
        test()->actingAs($setup['viewer'])
            ->getJson('/api/commerce/analytics?revenue_source=salla')
            ->assertForbidden();
    });

    it('refuses a portal revenue read before naming the sources on offer', function (): void {
        $setup = portalReadScenario();
        openPortal($setup, ['performance']);

        /*
         * The same ordering on the portal's own side. Store revenue is not exposed here, so the answer is that
         * the surface is not there — not a 422 listing the authorities it would accept.
         */
        test()->actingAs($setup['viewer'])
            ->getJson('/api/portal/'.$setup['clientId'].'/revenue')
            ->assertNotFound();
    });

    it('serves the metric vocabulary to any member, deliberately', function (): void {
        $setup = portalReadScenario();

        /*
         * Not a leak and not an oversight: the catalogue is what a metric is called, how it is formatted and
         * which direction is good. It carries no workspace data and no client data, and it is served rather than
         * duplicated in the client so that formatting cannot diverge between screens.
         */
        test()->actingAs($setup['viewer'])->getJson('/api/analytics/catalogue')->assertOk();
    });
});
