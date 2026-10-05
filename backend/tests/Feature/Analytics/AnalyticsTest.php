<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\DailyMetric;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
|
| Asserted against data the real sync wrote from the SANDBOX fixtures, so the
| figures under test are the figures the pipeline actually produces — nulls
| included. A hand-built fixture here would let the aggregation agree with itself
| while disagreeing with the ingestion.
|
*/

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function analytics(SyncedWorkspace $workspace, array $query = [], ?object $actor = null): array
{
    $response = test()->actingAs($actor ?? $workspace->scenario->owner)
        ->getJson('/api/analytics?'.http_build_query($query));

    $response->assertOk();

    /** @var array<string, mixed> $data */
    $data = $response->json('data');

    return $data;
}

/** @param array<string, mixed> $payload */
function metricValue(array $payload, string $metric, string $field = 'value'): mixed
{
    return $payload['summary']['values'][$metric][$field] ?? null;
}

/**
 * Put a user on a project, which is what grants them visibility of its data.
 *
 * A project membership carries its own role, so the assignment states what the
 * person does on that project rather than inheriting their workspace role.
 */
function assignTo(SyncedWorkspace $workspace, Provider $provider, object $user, SystemRole $role = SystemRole::MediaBuyer): void
{
    ProjectMember::query()->create([
        'project_id' => $workspace->project($provider)->getKey(),
        'user_id' => $user->getKey(),
        'role_id' => $workspace->scenario->role($role)->getKey(),
    ]);
}

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/analytics')->assertUnauthorized();
    });

    it('refuses a role without the analytics permission', function (): void {
        $workspace = SyncedWorkspace::default();
        $creative = $workspace->scenario->addMember(SystemRole::Creative);

        /*
         * Creatives produce work; they do not see client spend. The refusal is
         * server-side, so hiding the nav item would not be the only guard.
         */
        $this->actingAs($creative)
            ->getJson('/api/analytics')
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('required_permission', 'analytics.view');
    });
});

describe('totals', function (): void {
    it('sums spend from the stored rows, in minor units', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $expected = (int) DailyMetric::query()
            ->where('entity_type', 'campaign')
            ->sum('spend_minor');

        $payload = analytics($workspace, ['preset' => '90d']);

        expect($expected)->toBeGreaterThan(0)
            ->and(metricValue($payload, 'spend'))->toBe($expected)
            // Minor units all the way to the client, so nothing rounds in transit.
            ->and(metricValue($payload, 'spend', 'is_minor_units'))->toBeTrue()
            ->and($payload['summary']['currency'])->toBe('SAR');
    });

    it('reads one level only, never summing campaign and ad rows together', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $campaignSpend = (int) DailyMetric::query()->where('entity_type', 'campaign')->sum('spend_minor');
        $adSpend = (int) DailyMetric::query()->where('entity_type', 'ad')->sum('spend_minor');
        $payload = analytics($workspace, ['preset' => '90d']);

        /*
         * Both levels hold figures for the same money. Reading them together
         * would roughly double every total — the easiest way to produce a
         * confidently wrong number from this schema.
         */
        expect($adSpend)->toBeGreaterThan(0)
            ->and(metricValue($payload, 'spend'))->toBe($campaignSpend)
            ->and(metricValue($payload, 'spend'))->not->toBe($campaignSpend + $adSpend);
    });

    it('derives ratios from the totals, not from an average of daily ratios', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
        $payload = analytics($workspace, ['preset' => '90d']);

        $spend = metricValue($payload, 'spend');
        $clicks = metricValue($payload, 'clicks');
        $impressions = metricValue($payload, 'impressions');

        expect($clicks)->toBeGreaterThan(0);

        /*
         * The mean of seven daily CPCs is not the week's CPC — it weights a quiet
         * day the same as a heavy one. CPC must be total spend ÷ total clicks.
         */
        expect(metricValue($payload, 'cpc'))->toBe($spend / $clicks)
            ->and(metricValue($payload, 'ctr'))->toBe($clicks / $impressions * 100)
            ->and(metricValue($payload, 'cpm'))->toBe($spend / $impressions * 1000);
    });

    it('returns no ratio rather than infinity when the denominator is zero', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        // A range before any delivery: no clicks at all.
        $payload = analytics($workspace, ['start' => '2020-01-01', 'end' => '2020-01-31']);

        expect($payload['is_empty'])->toBeTrue()
            ->and(metricValue($payload, 'cpc'))->toBeNull()
            ->and(metricValue($payload, 'spend'))->toBeNull();
    });
});

describe('reach', function (): void {
    it('refuses to add reach across entities, and says why', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $payload = analytics($workspace, ['preset' => '90d', 'metrics' => ['reach', 'frequency', 'impressions']]);

        /*
         * Reach counts deduplicated people. Adding two campaigns' reach
         * double-counts anyone who saw both, so the sum always overstates it and
         * no platform would agree with the figure.
         */
        expect(metricValue($payload, 'reach'))->toBeNull()
            ->and(metricValue($payload, 'reach', 'unavailable_reason'))->toContain('deduplicated')
            ->and(metricValue($payload, 'frequency'))->toBeNull()
            // Impressions are views, not people, so they add up fine.
            ->and(metricValue($payload, 'impressions'))->toBeGreaterThan(0);
    });

    it('reports reach once the query covers a single entity', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
        $campaign = Campaign::query()->where('external_id', '23850000000000001')->firstOrFail();

        $payload = analytics($workspace, [
            'preset' => '90d',
            'campaign_ids' => [$campaign->getKey()],
            'metrics' => ['reach', 'frequency', 'impressions'],
        ]);

        expect(metricValue($payload, 'reach'))->toBeGreaterThan(0)
            ->and(metricValue($payload, 'frequency'))->toBeGreaterThan(1.0);
    });
});

describe('currency', function (): void {
    it('refuses to total money across currencies', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        // One campaign reporting in a second currency, as a multi-market client
        // would have. Selected by key for the same reason as above.
        $other = DailyMetric::query()->where('entity_type', 'campaign')->firstOrFail();
        DailyMetric::query()->whereKey($other->getKey())->update(['currency' => 'USD']);

        $payload = analytics($workspace, ['preset' => '90d']);

        /*
         * Converting would need a rate, a date, and a decision about which rate —
         * none of which belongs in a read model, and all of which a client would
         * want stated. A number that adds riyals to dollars is worse than none.
         */
        expect(metricValue($payload, 'spend'))->toBeNull()
            ->and(metricValue($payload, 'spend', 'unavailable_reason'))->toContain('more than one currency')
            // Non-monetary metrics are unaffected: a click is a click.
            ->and(metricValue($payload, 'clicks'))->toBeGreaterThan(0);
    });
});

describe('data honesty', function (): void {
    it('labels the whole surface sandbox when any row is', function (): void {
        $workspace = SyncedWorkspace::default();
        $payload = analytics($workspace, ['preset' => '90d']);

        expect($payload['source'])->toBe(MetricSource::Sandbox->value)
            ->and($payload['requires_label'])->toBeTrue()
            ->and(metricValue($payload, 'spend', 'source'))->toBe('sandbox');
    });

    it('carries the weakest source, so one stale row marks the total', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        // Most rows live, one stale: the total can only be as trustworthy as its
        // weakest input.
        DailyMetric::query()->update(['source' => MetricSource::Live->value]);

        // Targeted by key, not by limit: PostgreSQL has no LIMIT on UPDATE, so a
        // `limit(1)->update()` quietly rewrites the whole table or nothing.
        $one = DailyMetric::query()->where('entity_type', 'campaign')->firstOrFail();
        DailyMetric::query()->whereKey($one->getKey())->update(['source' => MetricSource::Stale->value]);

        expect(analytics($workspace, ['preset' => '90d'])['source'])
            ->toBe(MetricSource::Stale->value);
    });

    it('says a metric was not reported rather than showing zero', function (): void {
        $workspace = SyncedWorkspace::with([Provider::GoogleAds->value => '7788990011']);

        $payload = analytics($workspace, ['preset' => '90d', 'metrics' => ['link_clicks', 'clicks']]);

        /*
         * Google has no link-click metric distinct from clicks. Zero would read
         * as "nobody clicked through", which is a performance claim about
         * something the platform never measured.
         */
        expect(metricValue($payload, 'link_clicks'))->toBeNull()
            ->and(metricValue($payload, 'link_clicks', 'unavailable_reason'))->toContain('Not reported')
            ->and(metricValue($payload, 'clicks'))->toBeGreaterThan(0);
    });

    it('explains which input a missing ratio needed', function (): void {
        $workspace = SyncedWorkspace::with([Provider::GoogleAds->value => '7788990011']);

        $payload = analytics($workspace, ['preset' => '90d', 'metrics' => ['link_ctr']]);

        expect(metricValue($payload, 'link_ctr'))->toBeNull()
            // Actionable: it names the metric that is missing, not just "n/a".
            ->and(metricValue($payload, 'link_ctr', 'unavailable_reason'))->toContain('link clicks');
    });
});

describe('time series', function (): void {
    it('returns one point per day, including the empty ones', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $payload = analytics($workspace, ['preset' => '90d']);

        /*
         * A chart that omits a day joins the two either side with a straight
         * line, hiding the fact that delivery stopped — which is the most
         * important thing on the screen that day.
         */
        expect($payload['series'])->toHaveCount(90)
            ->and($payload['range']['days'])->toBe(90);

        $empty = array_values(array_filter(
            $payload['series'],
            static fn (array $point): bool => $point['row_count'] === 0,
        ));

        expect($empty)->not->toBeEmpty()
            ->and($empty[0]['values']['spend']['value'])->toBeNull();
    });

    it('sums to the same spend as the scorecard', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
        $payload = analytics($workspace, ['preset' => '90d']);

        $fromSeries = array_sum(array_map(
            static fn (array $point): int => (int) ($point['values']['spend']['value'] ?? 0),
            $payload['series'],
        ));

        // A scorecard disagreeing with the sum of its own chart is the fastest
        // way to lose a reader's trust in a dashboard.
        expect($fromSeries)->toBe(metricValue($payload, 'spend'));
    });
});

describe('breakdowns', function (): void {
    it('groups by platform with real labels', function (): void {
        $workspace = SyncedWorkspace::default();

        $payload = analytics($workspace, ['preset' => '90d', 'dimension' => 'provider']);

        $providers = array_column($payload['breakdown'], 'key');

        expect($providers)->toContain('meta')
            ->and($providers)->toContain('google_ads')
            ->and($payload['breakdown'][0]['values']['spend']['value'])->toBeGreaterThan(0);
    });

    it('groups by campaign and carries enough to drill down', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $payload = analytics($workspace, [
            'preset' => '90d',
            'dimension' => 'campaign',
            'order_by' => 'spend',
        ]);

        expect($payload['breakdown'])->not->toBeEmpty();

        $first = $payload['breakdown'][0];

        expect($first['label'])->toContain('SANDBOX')
            // The provider's own id, so an operator can find it in the platform.
            ->and($first['external_id'])->not->toBeNull()
            ->and($first['status'])->not->toBeNull();
    });

    it('orders a breakdown by the metric asked for', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $payload = analytics($workspace, [
            'preset' => '90d',
            'dimension' => 'campaign',
            'order_by' => 'spend',
        ]);

        $spends = array_map(
            static fn (array $row): int => (int) ($row['values']['spend']['value'] ?? 0),
            $payload['breakdown'],
        );
        $sorted = $spends;
        rsort($sorted);

        expect($spends)->toBe($sorted);
    });

    it('reports reach per campaign, because each row is one entity', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $payload = analytics($workspace, [
            'preset' => '90d',
            'dimension' => 'campaign',
            'metrics' => ['reach', 'spend'],
        ]);

        // The reason the workspace total cannot report reach does not apply to a
        // single campaign's row.
        expect($payload['breakdown'][0]['values']['reach']['value'])->toBeGreaterThan(0);
    });
});

describe('comparison', function (): void {
    it('returns the previous period alongside the current one', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $payload = analytics($workspace, ['preset' => '7d', 'compare' => 'previous_period']);

        expect($payload['compare_to'])->not->toBeNull()
            ->and($payload['compare_to']['end'] < $payload['range']['start'])->toBeTrue()
            ->and(metricValue($payload, 'spend', 'comparison_value'))->not->toBeNull()
            ->and(metricValue($payload, 'spend', 'change_ratio'))->toBeFloat();
    });

    it('knows that a falling cost is good news and falling spend is not', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $payload = analytics($workspace, ['preset' => '7d', 'compare' => 'previous_period']);

        // So the interface can colour a change without deciding what it means.
        expect(metricValue($payload, 'cpc', 'lower_is_better'))->toBeTrue()
            ->and(metricValue($payload, 'spend', 'lower_is_better'))->toBeFalse()
            ->and(metricValue($payload, 'roas', 'lower_is_better'))->toBeFalse();
    });

    it('gives no percentage change from a period with nothing in it', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        // The comparison window predates all delivery.
        $payload = analytics($workspace, [
            'start' => now()->subDays(7)->format('Y-m-d'),
            'end' => now()->subDay()->format('Y-m-d'),
            'compare' => 'previous_year',
        ]);

        // "Up from nothing" has no percentage, and +∞ or +100% is a number
        // someone will quote in a meeting.
        expect(metricValue($payload, 'spend', 'change_ratio'))->toBeNull();
    });
});

describe('visibility', function (): void {
    it('narrows a buyer to the projects they are assigned to', function (): void {
        $workspace = SyncedWorkspace::default();
        $buyer = $workspace->scenario->addMember(SystemRole::MediaBuyer);

        assignTo($workspace, Provider::Meta, $buyer);

        $all = analytics($workspace, ['preset' => '90d', 'dimension' => 'provider']);
        $theirs = analytics($workspace, ['preset' => '90d', 'dimension' => 'provider'], $buyer);

        /*
         * Assignment is what grants visibility. The buyer is on the Meta project
         * only, so Google's spend must not appear in their total — enforced by
         * narrowing the query, not by filtering the response.
         */
        expect(array_column($all['breakdown'], 'key'))->toContain('google_ads')
            ->and(array_column($theirs['breakdown'], 'key'))->toBe(['meta'])
            ->and(metricValue($theirs, 'spend'))->toBeLessThan(metricValue($all, 'spend'));
    });

    it('shows an unassigned buyer nothing at all, without an error', function (): void {
        $workspace = SyncedWorkspace::default();
        $buyer = $workspace->scenario->addMember(SystemRole::MediaBuyer);

        $payload = analytics($workspace, ['preset' => '90d'], $buyer);

        // Being unassigned is a normal state, not a failure: the screen should say
        // "nothing assigned to you yet", which needs a 200 and an empty result.
        expect($payload['is_empty'])->toBeTrue()
            ->and(metricValue($payload, 'spend'))->toBeNull();
    });

    it('refuses to widen when a restricted actor asks for someone else’s project', function (): void {
        $workspace = SyncedWorkspace::default();
        $buyer = $workspace->scenario->addMember(SystemRole::MediaBuyer);

        assignTo($workspace, Provider::Meta, $buyer);

        $payload = analytics($workspace, [
            'preset' => '90d',
            // A project they are not on.
            'project_ids' => [$workspace->project(Provider::GoogleAds)->getKey()],
        ], $buyer);

        // Intersected, not replaced: asking for a project they cannot see yields
        // nothing rather than quietly returning the ones they can.
        expect($payload['is_empty'])->toBeTrue();
    });
});

describe('the catalogue', function (): void {
    it('publishes what can be asked for, so the client need not duplicate it', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $payload = $this->actingAs($workspace->scenario->owner)
            ->getJson('/api/analytics/catalogue')
            ->assertOk()
            ->json('data');

        $metrics = [];

        foreach (is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [] as $metric) {
            if (is_array($metric) && isset($metric['value'])) {
                $metrics[(string) $metric['value']] = $metric;
            }
        }

        $sources = array_map(
            static fn (mixed $source): mixed => is_array($source) ? $source['value'] ?? null : null,
            is_array($payload['sources'] ?? null) ? $payload['sources'] : [],
        );

        expect($metrics)->toHaveKey(Metric::Roas->value)
            ->and($metrics[Metric::Roas->value]['is_derived'])->toBeTrue()
            ->and($metrics[Metric::Spend->value]['is_monetary'])->toBeTrue()
            // So the interface can explain why reach vanishes on a multi-entity
            // selection instead of just showing a dash.
            ->and($metrics[Metric::Reach->value]['additive_across_entities'])->toBeFalse()
            ->and($metrics[Metric::CostPerConversion->value]['lower_is_better'])->toBeTrue()
            ->and($sources)->toContain('sandbox');
    });
});

describe('keeping roles in step with the code', function (): void {
    it('grants a newly added permission to existing workspaces', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
        $manager = $workspace->scenario->addMember(SystemRole::ProjectManager);
        $role = $workspace->scenario->role(SystemRole::ProjectManager);

        /*
         * Simulate the state a deploy leaves behind: the enum gained
         * analytics.view, but this workspace's roles were provisioned before it
         * existed, so nobody can open the screen and nothing says why.
         */
        $analyticsPermission = DB::table('permissions')->where('slug', 'analytics.view')->value('id');
        DB::table('permission_role')
            ->where('role_id', $role->getKey())
            ->where('permission_id', $analyticsPermission)
            ->delete();

        $this->actingAs($manager)->getJson('/api/analytics')->assertForbidden();

        $this->artisan('access:sync')->assertSuccessful();

        // Converges on the definition in code, for workspaces that already exist.
        $this->actingAs($manager)->getJson('/api/analytics')->assertOk();
    });

    it('removes a permission a role no longer has in code', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
        $creative = $workspace->scenario->addMember(SystemRole::Creative);
        $role = $workspace->scenario->role(SystemRole::Creative);

        // Granted by hand, as a migration or a mistake might have.
        DB::table('permission_role')->insert([
            'role_id' => $role->getKey(),
            'permission_id' => DB::table('permissions')->where('slug', 'analytics.view')->value('id'),
        ]);

        $this->actingAs($creative)->getJson('/api/analytics')->assertOk();

        $this->artisan('access:sync')->assertSuccessful();

        /*
         * Syncing is two-way on purpose. A role that kept a permission the code
         * no longer grants it is a quiet privilege escalation, and creatives do
         * not see client spend.
         */
        $this->actingAs($creative)->getJson('/api/analytics')->assertForbidden();
    });
});

describe('labels', function (): void {
    it('names a platform the way the product does, not by its slug', function (): void {
        $workspace = SyncedWorkspace::default();

        $payload = analytics($workspace, ['preset' => '90d', 'dimension' => 'provider']);
        $labels = array_column($payload['breakdown'], 'label', 'key');

        /*
         * Resolved server-side from the provider enum, which already defines the
         * name. Leaving the slug for the client to translate is how one screen ends
         * up saying "Meta Ads" and another "google_ads".
         */
        expect($labels['meta'] ?? null)->toBe('Meta Ads')
            ->and($labels['google_ads'] ?? null)->toBe('Google Ads');
    });

    it('removes a deleted campaign’s figures with it, leaving no nameless rows', function (): void {
        $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

        $campaign = Campaign::query()->firstOrFail();
        $before = DailyMetric::query()->where('campaign_id', $campaign->getKey())->count();

        expect($before)->toBeGreaterThan(0);

        $campaign->delete();

        /*
         * The foreign key cascades, by the schema rather than by application code
         * remembering to. That is what keeps an unnameable row out of a report: a
         * metric whose entity no longer exists could be totalled but never labelled,
         * and a reader cannot act on a figure with no name attached.
         */
        expect(DailyMetric::query()->where('campaign_id', $campaign->getKey())->count())->toBe(0);

        $payload = analytics($workspace, ['preset' => '90d', 'dimension' => 'campaign']);

        foreach (array_column($payload['breakdown'], 'label') as $label) {
            expect($label)->not->toBe('')
                ->and($label)->not->toContain('Unknown');
        }
    });
});
