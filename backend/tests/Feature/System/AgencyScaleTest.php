<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Database\Seeders\AgencyScaleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The agency-scale acceptance test.
 *
 * Rule 103 asks for a scale fixture and rule 104 for query budgets. Both exist here, and the second is
 * written as an **invariance** assertion rather than a wall-clock one, because wall-clock budgets fail on
 * a loaded laptop and pass on a fast one while the actual defect — a query inside a loop — is invisible
 * to both.
 *
 * The property under test: *the number of SQL statements an agency view issues does not depend on how
 * much data it covers.* A page of fifty campaigns over twenty clients and the same page over sixty
 * clients must cost the same number of statements. That is exactly what rule 71 forbids violating, it is
 * cheap to check, and it fails loudly the first time somebody writes `foreach ($projects as $project)`
 * around a query.
 *
 * The sizes here are far below the constitution's 100/300/2000. They are chosen so the suite stays
 * runnable, and they are legitimate for this purpose because the assertion is about the *slope*, not the
 * absolute cost. The full-size fixture is the seeder's own default and is run by hand.
 */
function seedAgency(int $clients, int $accounts, int $campaigns, int $days = 3): void
{
    config([
        'agency_scale.clients' => $clients,
        'agency_scale.projects_per_client' => 2,
        'agency_scale.accounts' => $accounts,
        'agency_scale.campaigns' => $campaigns,
        'agency_scale.days' => $days,
    ]);

    app(AgencyScaleSeeder::class)->run();
}

function scaleOwner(): User
{
    return User::query()->where('email', AgencyScaleSeeder::OWNER_EMAIL)->sole();
}

/**
 * Count the statements one request issues.
 *
 * @param  callable():mixed  $work
 */
function statementsFor(callable $work): int
{
    $count = 0;
    DB::listen(static function () use (&$count): void {
        $count++;
    });

    $work();

    /* Listeners accumulate across calls in one process, so each measurement gets a clean connection. */
    DB::getEventDispatcher()?->forget(Illuminate\Database\Events\QueryExecuted::class);

    return $count;
}

it('builds a synthetic agency with the shape the rules describe', function (): void {
    seedAgency(clients: 12, accounts: 24, campaigns: 60);

    $workspaceId = DB::table('workspaces')->where('slug', AgencyScaleSeeder::WORKSPACE_SLUG)->value('id');

    expect(DB::table('clients')->where('workspace_id', $workspaceId)->count())->toBe(12)
        ->and(DB::table('external_accounts')->where('workspace_id', $workspaceId)->count())->toBe(24)
        ->and(DB::table('campaigns')->where('workspace_id', $workspaceId)->count())->toBe(60)
        ->and(DB::table('daily_metrics')->where('workspace_id', $workspaceId)->count())->toBe(180);

    /*
     * The two shapes the fixture exists to produce. Without accounts bound to several projects the
     * ownership queue is permanently empty, and without an unbound account the `unbound` state has no
     * population — a fixture that only exercises the happy arrangement proves nothing about the others.
     */
    $perAccount = DB::table('project_integration_bindings')
        ->selectRaw('external_account_id, count(*) as bindings')
        ->groupBy('external_account_id')
        ->pluck('bindings');

    expect($perAccount->max())->toBeGreaterThan(1);

    $boundAccounts = DB::table('project_integration_bindings')->distinct()->count('external_account_id');
    expect($boundAccounts)->toBeLessThan(24);
});

/*
 * Re-running rebuilds rather than accumulating, which is what lets two measurements be compared. A
 * fixture that is a function of how many times it has been run is not a fixture.
 */
it('produces the same fixture twice, so a measurement can be compared with the one before it', function (): void {
    seedAgency(clients: 6, accounts: 10, campaigns: 20);
    $first = DB::table('daily_metrics')->orderBy('date')->orderBy('campaign_id')->pluck('spend_minor')->all();

    seedAgency(clients: 6, accounts: 10, campaigns: 20);
    $second = DB::table('daily_metrics')->orderBy('date')->orderBy('campaign_id')->pluck('spend_minor')->all();

    expect(count($second))->toBe(count($first))
        ->and(array_sum($second))->toBe(array_sum($first));
});

/*
 * Rule 71, as an assertion. Not "is it fast" — "does it get slower per row", which is the only one of the
 * two that a defect can hide from.
 */
it('issues the same number of statements over a bigger agency: the campaign ownership queue', function (): void {
    seedAgency(clients: 6, accounts: 10, campaigns: 20);
    $owner = scaleOwner();

    $small = statementsFor(function () use ($owner): void {
        $this->actingAs($owner)->getJson('/api/campaigns/ownership?limit=50')->assertOk();
    });

    seedAgency(clients: 18, accounts: 30, campaigns: 120);

    $large = statementsFor(function () use ($owner): void {
        $this->actingAs($owner)->getJson('/api/campaigns/ownership?limit=50')->assertOk();
    });

    expect($large)->toBe($small);
});

/*
 * The surface most at risk of this, because every row carries two window aggregates. They arrive as
 * lateral subqueries rather than as a pass per campaign; if that ever becomes a loop, this is the test
 * that says so.
 */
it('issues the same number of statements over a bigger agency: the campaign control center', function (): void {
    seedAgency(clients: 6, accounts: 10, campaigns: 20);
    $owner = scaleOwner();

    $small = statementsFor(function () use ($owner): void {
        $this->actingAs($owner)->getJson('/api/campaigns/control?limit=50')->assertOk();
    });

    seedAgency(clients: 18, accounts: 30, campaigns: 120);

    $large = statementsFor(function () use ($owner): void {
        $this->actingAs($owner)->getJson('/api/campaigns/control?limit=50')->assertOk();
    });

    expect($large)->toBe($small);
});

/*
 * The attention queue consults eight modules, so its statement count is the sum of eight. What must not
 * change is that the sum does not grow with the agency — each module answers in a fixed number of
 * queries however much work it finds.
 */
it('issues the same number of statements over a bigger agency: the attention queue', function (): void {
    seedAgency(clients: 6, accounts: 10, campaigns: 20);
    $owner = scaleOwner();

    $small = statementsFor(function () use ($owner): void {
        $this->actingAs($owner)->getJson('/api/attention')->assertOk();
    });

    seedAgency(clients: 18, accounts: 30, campaigns: 120);

    $large = statementsFor(function () use ($owner): void {
        $this->actingAs($owner)->getJson('/api/attention')->assertOk();
    });

    expect($large)->toBe($small);
});

it('issues the same number of statements over a bigger agency: the budget control center and search', function (): void {
    seedAgency(clients: 6, accounts: 10, campaigns: 20);
    $owner = scaleOwner();
    $urls = ['/api/budgets/control', '/api/search?q=Scale', '/api/integrations/account-queue', '/api/portfolio'];

    $small = [];

    foreach ($urls as $url) {
        $small[$url] = statementsFor(function () use ($owner, $url): void {
            $this->actingAs($owner)->getJson($url)->assertOk();
        });
    }

    seedAgency(clients: 18, accounts: 30, campaigns: 120);

    foreach ($urls as $url) {
        $large = statementsFor(function () use ($owner, $url): void {
            $this->actingAs($owner)->getJson($url)->assertOk();
        });

        expect($large)->toBe($small[$url], $url);
    }
});

it('issues the same number of statements over a bigger agency: lead centre, commerce, tracking, activity, benchmarks, home, monitor', function (): void {
    seedAgency(clients: 6, accounts: 10, campaigns: 20);
    $owner = scaleOwner();
    /* Measured cost plus two, as the budget test below does: invariance says it is flat, this says it is small. */
    $ceilings = [
        '/api/leads/centre' => 10,
        '/api/leads?limit=50' => 6,
        /* +2 since commerce became a plan feature: the plan's features and its overrides, read once per request. */
        '/api/commerce/rollup?revenue_source=salla' => 8,
        '/api/measurement/agency-health' => 7,
        '/api/activity' => 6,
        '/api/benchmarks' => 8,
        '/api/home' => 5,
        '/api/integrations/operations' => 10,
        '/api/exports' => 6,
        '/api/views/campaign_control' => 6,
    ];
    $urls = array_keys($ceilings);

    $small = [];

    foreach ($urls as $url) {
        $small[$url] = statementsFor(function () use ($owner, $url): void {
            $this->actingAs($owner)->getJson($url)->assertOk();
        });
    }

    seedAgency(clients: 18, accounts: 30, campaigns: 120);

    foreach ($urls as $url) {
        $large = statementsFor(function () use ($owner, $url): void {
            $this->actingAs($owner)->getJson($url)->assertOk();
        });

        expect($large)->toBe($small[$url], $url)
            ->and($large)->toBeLessThanOrEqual($ceilings[$url], sprintf('%s used %d statements', $url, $large));
    }
});

it('issues the same number of statements over a bigger agency: the analytics breakdown', function (): void {
    seedAgency(clients: 6, accounts: 10, campaigns: 20);
    $owner = scaleOwner();
    $query = '/api/analytics?preset=28d&metrics[]=spend&metrics[]=roas&dimension=campaign&limit=25';

    $small = statementsFor(function () use ($owner, $query): void {
        $this->actingAs($owner)->getJson($query)->assertOk();
    });

    seedAgency(clients: 18, accounts: 30, campaigns: 120);

    $large = statementsFor(function () use ($owner, $query): void {
        $this->actingAs($owner)->getJson($query)->assertOk();
    });

    expect($large)->toBe($small);
});

/*
 * An absolute ceiling beside the invariance check. Invariance alone would accept a view that issues a
 * constant forty statements; this says the constant has to be small, and names the number so a change to
 * it is a decision somebody makes rather than a drift nobody notices.
 *
 * The budgets are the measured cost plus two: enough slack that an extra authorisation read does not
 * fail the suite, tight enough that a join replaced by a loop does. Measured when first written:
 * ownership 5, control 5, analytics 6, clients 5, projects 5, attention 29 — each returning real rows,
 * which the assertions below check, because a 200 over an empty result set would pass any budget.
 *
 * Those figures are the originals and have not all been re-measured since; where one is re-measured the
 * date is recorded beside it, as on the attention entry below. A figure nobody has taken again is worth
 * less than one that says when it was taken.
 */
it('keeps the agency views within their stated query budget', function (): void {
    seedAgency(clients: 12, accounts: 24, campaigns: 60);
    $owner = scaleOwner();

    $budgets = [
        '/api/campaigns/ownership?limit=50' => 7,
        '/api/campaigns/control?limit=50' => 8,
        /*
         * Much larger than the rest, and deliberately so: the attention queue consults eight modules and
         * pays each one's existing read rather than duplicating their rules into a ninth query. The
         * count is flat with agency size — the test above proves that — and the number is written here so
         * that narrowing a source to the queue's own question shows up as a change somebody made.
         *
         * Re-measured 2026-10-19: 18 statements. It was 16 under six streams, and the budget stood at 17
         * until two streams were added without it being revised — Automation on 2026-10-18 and Agents on
         * 2026-10-19 — each issuing exactly one query (`operating_rules`, `agent_findings`). The rise is
         * therefore two new readers, not a loop: the invariance test above still passes, which is the
         * assertion that would fail if the cost had started growing with the agency.
         *
         * 19 rather than 20 keeps this entry at the +1 it adopted when the queue was halved, rather than
         * the +2 the rest of the file uses. A third stream added without thought should still show up here.
         */
        /* 21 since the campaign-launch stream (SaaS H): two constant reads — plans waiting, executions in trouble — on the measured 18, plus the +1 slack. */
        '/api/attention' => 21,
        '/api/budgets/control' => 9,
        '/api/portfolio' => 18,
        '/api/search?q=Scale' => 8,
        '/api/integrations/connections' => 8,
        '/api/integrations/account-queue' => 9,
        '/api/analytics?preset=28d&metrics[]=spend&dimension=provider&limit=10' => 8,
        '/api/leads?limit=50' => 7,
        '/api/leads/centre' => 10,
        /* +2 since commerce became a plan feature: the plan's features and its overrides, read once per request. */
        '/api/commerce/rollup?revenue_source=salla' => 8,
        '/api/clients?per_page=25' => 7,
        '/api/projects?per_page=25' => 7,
    ];

    /* The attention queue is honest about an empty workspace, so it needs something to find. */
    DB::table('tasks')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'workspace_id' => DB::table('workspaces')->where('slug', AgencyScaleSeeder::WORKSPACE_SLUG)->value('id'),
        'title' => 'Overdue by construction',
        'state' => 'todo',
        'priority' => 'normal',
        'due_on' => now()->subDays(5)->toDateString(),
        'created_by' => scaleOwner()->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($budgets as $url => $budget) {
        $rows = 0;
        $started = hrtime(true);

        $used = statementsFor(function () use ($owner, $url, &$rows): void {
            $payload = $this->actingAs($owner)->getJson($url)->assertOk()->json('data');
            $rows = is_array($payload) ? count($payload) : 0;
        });

        $elapsed = (hrtime(true) - $started) / 1e6;

        /*
         * A wall-clock ceiling, generous on purpose — it exists for the defect a statement count cannot see.
         * The budget read once issued six statements and took four minutes, because one of them was a
         * correlated sub-select evaluated per metric row. Six statements was true the whole time. A ceiling
         * of five seconds is two orders of magnitude from a healthy read and one from a broken one.
         */
        expect($rows)->toBeGreaterThan(0, sprintf('%s returned nothing to measure', $url))
            ->and($used)->toBeLessThanOrEqual($budget, sprintf('%s used %d statements', $url, $used))
            ->and($elapsed)->toBeLessThan(5_000.0, sprintf('%s took %.0f ms', $url, $elapsed));
    }
});

it('never pre-assigns a campaign to a project, so the ownership queue is real work', function (): void {
    seedAgency(clients: 6, accounts: 10, campaigns: 20);

    expect(Campaign::query()->whereNotNull('project_id')->count())->toBe(0);
});
