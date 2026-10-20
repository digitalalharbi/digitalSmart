<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Lead figures in a report
|--------------------------------------------------------------------------
|
| A lead is a person, so a lead section is aggregates and nothing else. What is asserted: no record-level field can
| appear in a response, one client's leads never reach another's report, a lead is not a qualified lead is not a sale,
| and a rate with nothing to divide by is absent rather than zero.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client} */
function leadReportScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    return ['scenario' => $scenario, 'client' => $client];
}

/**
 * Insert a lead straight into the table. The aggregates are what is under test, not the use cases that write leads.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertLead(WorkspaceScenario $scenario, Client $client, array $overrides = []): void
{
    $received = new DateTimeImmutable('-3 days');
    $stage = $overrides['stage'] ?? 'new';
    $closed = in_array($stage, ['won', 'lost', 'disqualified'], true);

    DB::table('leads')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => (string) $scenario->workspace->getKey(),
        'client_id' => (string) $client->getKey(),
        'source_kind' => 'form_submission',
        'name' => 'Private Person',
        'email' => 'private.person@example.test',
        'phone' => '+966555000111',
        'message' => 'Please call me about the villa',
        'stage' => $stage,
        'disqualified_reason' => $stage === 'disqualified' ? 'Too far from the branch' : null,
        'attribution_evidence' => 'none',
        'received_at' => $received->format('Y-m-d H:i:s'),
        'closed_at' => $closed ? $received->modify('+1 day')->format('Y-m-d H:i:s') : null,
        'created_at' => $received->format('Y-m-d H:i:s'),
        'updated_at' => $received->format('Y-m-d H:i:s'),
        ...$overrides,
    ]);
}

/** @param array{scenario: WorkspaceScenario, client: Client} $setup */
function leadReport(array $setup, string $audience = 'client_safe'): string
{
    return (string) test()->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Leads month',
            'client_id' => (string) $setup['client']->getKey(),
            'window' => 'last_30_days',
            'audience' => $audience,
            'sections' => [['kind' => 'lead']],
        ])
        ->assertCreated()
        ->json('data.id');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client}  $setup
 * @return array<string, mixed>
 */
function renderLeadReport(array $setup, string $report): array
{
    /** @var array<string, mixed> $section */
    $section = test()->actingAs($setup['scenario']->owner)
        ->getJson("/api/reports/{$report}")
        ->assertOk()
        ->json('data.sections.0');

    return $section;
}

describe('what a lead section says', function (): void {
    it('keeps a lead, a qualified lead and a sale apart', function (): void {
        $setup = leadReportScenario();

        foreach (['new', 'contacted', 'qualified', 'qualified', 'disqualified', 'won', 'lost'] as $stage) {
            insertLead($setup['scenario'], $setup['client'], ['stage' => $stage]);
        }

        $lead = renderLeadReport($setup, leadReport($setup))['lead'];

        expect($lead['total'])->toBe(7)
            ->and($lead['qualified'])->toBe(2)
            ->and($lead['won'])->toBe(1)
            // Reached is qualified + disqualified + won + lost, as the domain defines it — a contact attempt is not contact.
            ->and($lead['reached'])->toBe(5)
            ->and($lead['stages'])->toBe(['new' => 1, 'contacted' => 1, 'qualified' => 2, 'disqualified' => 1, 'won' => 1, 'lost' => 1]);
    });

    it('divides qualified leads by leads somebody reached, and wins by closed leads', function (): void {
        $setup = leadReportScenario();

        foreach (['new', 'new', 'qualified', 'disqualified', 'won', 'lost'] as $stage) {
            insertLead($setup['scenario'], $setup['client'], ['stage' => $stage]);
        }

        $lead = renderLeadReport($setup, leadReport($setup))['lead'];

        // 1 qualified of 4 reached (qualified, disqualified, won, lost) — not of all 6.
        expect($lead['qualification_rate'])->toEqual(25)
            // 1 won of 3 closed (won, lost, disqualified) — the open ones are not losses.
            ->and($lead['win_rate'])->toBe(33.3);
    });

    it('has no rate where there is nothing to divide by, rather than zero', function (): void {
        $setup = leadReportScenario();
        insertLead($setup['scenario'], $setup['client'], ['stage' => 'new']);

        $section = renderLeadReport($setup, leadReport($setup));
        $lead = $section['lead'];

        expect($lead['qualification_rate'])->toBeNull()
            ->and($lead['win_rate'])->toBeNull()
            ->and($lead['response']['median_seconds'])->toBeNull()
            ->and($lead['response']['unanswered'])->toBe(1)
            ->and($lead['response']['measured'])->toBe(0);
    });

    it('is empty, not a row of zeroes, when the client recorded no leads', function (): void {
        $setup = leadReportScenario();

        $section = renderLeadReport($setup, leadReport($setup));

        expect($section['is_empty'])->toBeTrue();
    });

    it('measures first response from recorded contacts only, with the median and the buckets', function (): void {
        $setup = leadReportScenario();
        $received = new DateTimeImmutable('-3 days');

        foreach ([120, 600, 1800, 7200, 30_000] as $seconds) {
            insertLead($setup['scenario'], $setup['client'], [
                'stage' => 'contacted',
                'first_contacted_at' => $received->modify("+{$seconds} seconds")->format('Y-m-d H:i:s'),
            ]);
        }

        insertLead($setup['scenario'], $setup['client'], ['stage' => 'new']);

        $response = renderLeadReport($setup, leadReport($setup))['lead']['response'];

        expect($response['buckets'])->toBe([1, 1, 1, 1, 1])
            ->and($response['measured'])->toBe(5)
            ->and($response['unanswered'])->toBe(1)
            ->and($response['median_seconds'])->toBe(1800);
    });

    it('says in a caveat that it is not the platforms\' own lead count', function (): void {
        $setup = leadReportScenario();
        insertLead($setup['scenario'], $setup['client']);

        $caveats = implode(' ', renderLeadReport($setup, leadReport($setup))['caveats']);

        expect($caveats)->toContain('not the ad platforms')
            ->and($caveats)->toContain('no recorded first contact');
    });

    it('groups routes once and leaves a campaign-less lead as unattributed', function (): void {
        $setup = leadReportScenario();
        insertLead($setup['scenario'], $setup['client'], ['source_kind' => 'phone_call', 'source_detail' => 'Branch line 1']);
        insertLead($setup['scenario'], $setup['client'], ['source_kind' => 'phone_call', 'source_detail' => 'Branch line 2']);
        insertLead($setup['scenario'], $setup['client']);

        $lead = renderLeadReport($setup, leadReport($setup))['lead'];
        $kinds = array_column($lead['sources'], 'total', 'kind');

        expect($kinds)->toBe(['phone_call' => 2, 'form_submission' => 1])
            ->and($lead['campaigns'])->toHaveCount(1)
            ->and($lead['campaigns'][0]['is_unattributed'])->toBeTrue()
            ->and($lead['campaigns'][0]['label'])->toBeNull();
    });
});

describe('what a lead section never carries', function (): void {
    it('has no name, contact point, note, message, reason or owner anywhere in the response', function (): void {
        $setup = leadReportScenario();
        insertLead($setup['scenario'], $setup['client'], ['stage' => 'disqualified', 'source_detail' => 'Villa landing form']);
        $report = leadReport($setup);

        $json = (string) test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/reports/{$report}")->assertOk()->getContent();
        $csv = (string) test()->actingAs($setup['scenario']->owner)
            ->get("/api/reports/{$report}/csv")->assertOk()->getContent();

        foreach ([$json, $csv] as $body) {
            expect($body)->not->toContain('Private Person')
                ->and($body)->not->toContain('private.person')
                ->and($body)->not->toContain('+966555000111')
                ->and($body)->not->toContain('villa')
                ->and($body)->not->toContain('Too far from the branch')
                ->and($body)->not->toContain('Villa landing form');
        }
    });

    it('never reads another client\'s leads', function (): void {
        $setup = leadReportScenario();
        $other = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);

        insertLead($setup['scenario'], $setup['client'], ['stage' => 'qualified']);
        insertLead($setup['scenario'], $other, ['stage' => 'qualified']);
        insertLead($setup['scenario'], $other, ['stage' => 'won']);

        $lead = renderLeadReport($setup, leadReport($setup))['lead'];

        expect($lead['total'])->toBe(1)->and($lead['won'])->toBe(0);
    });

    it('refuses a lead section in a report with no client', function (): void {
        $scenario = WorkspaceScenario::create();

        test()->actingAs($scenario->owner)
            ->postJson('/api/reports', [
                'name' => 'Agency leads',
                'window' => 'last_30_days',
                'sections' => [['kind' => 'lead']],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'lead_needs_client');
    });
});

describe('where it travels', function (): void {
    it('reaches a client through a shared link as the same aggregates, and no more', function (): void {
        $setup = leadReportScenario();
        insertLead($setup['scenario'], $setup['client'], ['stage' => 'qualified']);
        $report = leadReport($setup);

        $token = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report}/shares", ['days' => 7])
            ->assertCreated()
            ->json('meta.token');

        $response = test()->getJson("/api/shared-reports/{$token}")->assertOk();
        $shared = $response->json('data.report.sections.0');

        expect($shared['kind'])->toBe('lead')
            ->and($shared['lead']['qualified'])->toBe(1);

        expect($response->getContent())->not->toContain('private.person')->and($response->getContent())->not->toContain('Private Person');
    });

    it('is frozen with the rest of a snapshot', function (): void {
        $setup = leadReportScenario();
        insertLead($setup['scenario'], $setup['client'], ['stage' => 'qualified']);
        $report = leadReport($setup);

        $snapshot = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report}/snapshots", ['label' => 'September'])
            ->assertCreated()
            ->json('data.id');

        insertLead($setup['scenario'], $setup['client'], ['stage' => 'won']);

        $token = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report}/shares", ['days' => 7, 'snapshot_id' => $snapshot])
            ->assertCreated()
            ->json('meta.token');

        $shared = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data.report.sections.0');

        expect($shared['lead']['total'])->toBe(1)->and($shared['lead']['won'])->toBe(0);
    });

    it('is written into the CSV with a missing rate as words, not a zero', function (): void {
        $setup = leadReportScenario();
        insertLead($setup['scenario'], $setup['client'], ['stage' => 'new']);
        $report = leadReport($setup);

        $csv = (string) test()->actingAs($setup['scenario']->owner)
            ->get("/api/reports/{$report}/csv")->assertOk()->getContent();

        expect($csv)->toContain('"Leads recorded","1"')
            ->and($csv)->toContain('"Qualification rate (of leads reached)","Not available"');
    });
});
