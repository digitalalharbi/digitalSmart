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
| Reports
|--------------------------------------------------------------------------
|
| A report is the most dangerous artefact this product makes: it is e-mailed, quoted in a meeting, and read months
| later by somebody who was not in the conversation. The tests here are mostly about what it refuses to let somebody
| build, and what it refuses to let a link do.
|
| The three that matter most: a ranking cannot contain a metric whose rows do not add up; a report written for
| internal eyes cannot be shared at all, not even with the notes stripped; and a share token is stored hashed, so a
| database dump hands out nothing.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function reportScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function saveReport(array $setup, array $overrides = [], ?User $as = null): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Monthly performance',
            'client_id' => (string) $setup['client']->getKey(),
            'window' => 'last_30_days',
            'comparison' => 'previous_period',
            'sections' => [
                ['kind' => 'scorecard', 'metrics' => ['spend', 'clicks', 'ctr']],
                ['kind' => 'trend', 'metrics' => ['spend']],
                ['kind' => 'breakdown', 'metrics' => ['spend'], 'dimension' => 'provider'],
            ],
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data');
}

describe('building one', function (): void {
    it('saves a definition and reads it back', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        expect($report['name'])->toBe('Monthly performance');
        expect($report['sections'])->toHaveCount(3);
        /*
         * Internal unless somebody says otherwise. A default of client-safe would mean a report written in shorthand
         * becoming shareable by omission.
         */
        expect($report['audience'])->toBe('internal');
    });

    it('refuses a ranking of a metric whose rows do not add up', function (): void {
        $setup = reportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Reach by campaign',
                'sections' => [
                    ['kind' => 'breakdown', 'metrics' => ['reach'], 'dimension' => 'campaign'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'metric_not_allowed_here');
    });

    it('allows reach where there is nothing to add it to', function (): void {
        $setup = reportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Reach over time',
                'sections' => [['kind' => 'trend', 'metrics' => ['reach']]],
            ])
            ->assertCreated();
    });

    it('refuses a report with no sections', function (): void {
        $setup = reportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', ['name' => 'Empty', 'sections' => []])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sections_required');
    });

    it('refuses more metrics than a shape can carry', function (): void {
        $setup = reportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Too much',
                'sections' => [
                    ['kind' => 'trend', 'metrics' => ['spend', 'clicks', 'impressions', 'conversions', 'revenue']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'too_many_metrics');
    });

    it('refuses a ranking with nothing to group by', function (): void {
        $setup = reportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Grouped by nothing',
                'sections' => [['kind' => 'breakdown', 'metrics' => ['spend']]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'dimension_required');
    });

    it('refuses a specific period with no dates', function (): void {
        $setup = reportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'September',
                'window' => 'custom',
                'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'dates_required');
    });

    it('replaces sections wholesale on an update', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $updated = test()->actingAs($setup['scenario']->owner)
            ->putJson("/api/reports/{$report['id']}", [
                'name' => 'Monthly performance',
                'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
            ])
            ->assertOk()
            ->json('data');

        /*
         * Sections are an ordered list, not independently editable records: reordering one is a change to all of
         * them, and a partial update would leave positions that no longer form a sequence.
         */
        expect($updated['sections'])->toHaveCount(1);
        expect(DB::table('report_sections')->where('report_id', $report['id'])->count())->toBe(1);
    });

    it('records the audience on the audit entry', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup, ['audience' => 'client_safe']);

        $entry = AuditLog::query()
            ->where('action', AuditAction::ReportSaved->value)
            ->where('auditable_id', $report['id'])
            ->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes;

        // A report flipped from internal to client-safe is the change somebody will want to find.
        expect($changes['audience'])->toBe('client_safe');
    });
});

describe('running one', function (): void {
    it('says it cannot compare periods of different lengths, instead of showing a change', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup, [
            'window' => 'month_to_date',
            'comparison' => 'previous_period',
        ]);

        $rendered = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/reports/{$report['id']}")
            ->assertOk()
            ->json('data');

        /*
         * Month-to-date against the window before it. Equal in length only because the earlier one was cut to match,
         * and the current side is still filling.
         */
        expect($rendered['comparison']['was_requested'])->toBeTrue();
        expect($rendered['comparison']['is_comparable'])->toBeFalse();
        expect($rendered['comparison']['code'])->toBe('period_incomplete');
        expect($rendered['period']['is_incomplete'])->toBeTrue();
    });

    it('compares the period before when the two are the same length', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $rendered = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/reports/{$report['id']}")
            ->assertOk()
            ->json('data');

        expect($rendered['comparison']['is_comparable'])->toBeTrue();
        expect($rendered['comparison']['reason'])->toBeNull();
        expect($rendered['period']['days'])->toBe(30);
    });

    it('says a section is empty rather than rendering zeroes', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $rendered = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/reports/{$report['id']}")
            ->assertOk()
            ->json('data');

        /*
         * No performance data in this workspace, so every section is empty. Zero spend and "nothing recorded" mean
         * opposite things to anybody deciding a budget, and the flag is what lets the interface say which it is.
         */
        expect($rendered['is_empty'])->toBeTrue();

        foreach ($rendered['sections'] as $section) {
            expect($section['is_empty'])->toBeTrue();
        }
    });

    it('carries a timestamp and a mode on every render', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $rendered = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/reports/{$report['id']}")
            ->assertOk()
            ->json('data');

        // A figure with no timestamp is a figure somebody will quote next month.
        expect($rendered['rendered_at'])->not->toBeNull();
        expect($rendered['mode'])->toBe('live');
    });

    it('states the caveat for the comparison it was asked for', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $rendered = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/reports/{$report['id']}")
            ->assertOk()
            ->json('data');

        $scorecard = $rendered['sections'][0];

        // Attached to the section the change figures are in, not to the document nobody reads the footer of.
        expect(implode(' ', $scorecard['caveats']))->toContain('seasonality');
    });
});

describe('exporting one', function (): void {
    it('carries the period, the source and the comparison warning into the file', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup, ['window' => 'month_to_date', 'comparison' => 'previous_period']);

        $csv = test()->actingAs($setup['scenario']->owner)
            ->get("/api/reports/{$report['id']}/csv")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->content();

        /*
         * A CSV that dropped the warnings would be the most dangerous artefact here: a file somebody pastes into a
         * deck with every caveat the interface showed fallen off.
         */
        expect($csv)->toContain('"Data source"');
        expect($csv)->toContain('"Comparison warning"');
        expect($csv)->toContain('"Period"');
        expect($csv)->toContain('still running');
    });

    it('says a section was empty rather than leaving blank rows', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $csv = test()->actingAs($setup['scenario']->owner)
            ->get("/api/reports/{$report['id']}/csv")
            ->assertOk()
            ->content();

        // A section with no rows and no sentence reads as an export that failed, and the next person re-runs it.
        expect($csv)->toContain('Nothing was recorded for this section');
    });

    it('names the file after the report and its period', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $response = test()->actingAs($setup['scenario']->owner)
            ->get("/api/reports/{$report['id']}/csv")
            ->assertOk();

        // A folder of files called "Monthly report.csv" is a folder nobody can tell apart.
        expect($response->headers->get('Content-Disposition'))->toContain('Monthly performance');
    });
});

describe('freezing one', function (): void {
    it('stores output that does not move when the report is changed', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $snapshotId = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report['id']}/snapshots", ['label' => 'September close'])
            ->assertCreated()
            ->json('data.id');

        $before = DB::table('report_snapshots')->where('id', $snapshotId)->value('payload');

        test()->actingAs($setup['scenario']->owner)
            ->putJson("/api/reports/{$report['id']}", [
                'name' => 'Renamed entirely',
                'sections' => [['kind' => 'scorecard', 'metrics' => ['revenue']]],
            ])
            ->assertOk();

        /*
         * The whole reason a snapshot exists. A client who was sent a figure must either see that figure or be told
         * plainly they are looking at today's — and a payload that recomputed would make "the report said 1.2
         * million" unanswerable.
         */
        expect(DB::table('report_snapshots')->where('id', $snapshotId)->value('payload'))->toBe($before);
    });

    it('records the source state at the moment it was frozen', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        $snapshotId = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report['id']}/snapshots")
            ->assertCreated()
            ->json('data.id');

        $entry = AuditLog::query()
            ->where('action', AuditAction::ReportSnapshotTaken->value)
            ->where('auditable_id', $snapshotId)
            ->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes;

        // A snapshot taken from sandbox fixtures is a sandbox snapshot forever, and the entry says so.
        expect($changes)->toHaveKey('source');
        expect($changes['period_from'])->not->toBeNull();
    });
});

describe('sharing one', function (): void {
    it('refuses to share a report written for internal eyes', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup);

        /*
         * Refused rather than sanitised. A report written with internal commentary in mind has it throughout — in
         * its section titles, in which metrics were chosen, in what was left out.
         */
        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report['id']}/shares", ['days' => 7])
            ->assertStatus(422)
            ->assertJsonPath('code', 'report_is_internal');
    });

    it('returns the token once and stores only its hash', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup, ['audience' => 'client_safe']);

        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report['id']}/shares", ['days' => 7, 'recipient_label' => 'Alhambra'])
            ->assertCreated();

        $token = $response->json('meta.token');

        expect($token)->toBeString();
        expect(strlen((string) $token))->toBe(96);

        /*
         * A share link is a credential. A database dump must not hand out working links to a client's performance
         * data, so only the hash and a twelve-character hint are stored.
         */
        $row = DB::table('report_shares')->where('report_id', $report['id'])->first();

        expect($row?->token_hash)->toBe(hash('sha256', (string) $token));
        expect($row?->token_hash)->not->toBe($token);
        expect(DB::table('report_shares')->where('token_hash', $token)->count())->toBe(0);

        // And the response carries the hint, never the token, on the share itself.
        expect($response->json('data.hint'))->toBe(substr((string) $token, 0, 12));
    });

    it('refuses a link that would outlive the engagement', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup, ['audience' => 'client_safe']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report['id']}/shares", ['days' => 400])
            ->assertStatus(422)
            ->assertJsonPath('code', 'expiry_out_of_range');
    });

    it('records the hint and never the token on the audit entry', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup, ['audience' => 'client_safe']);

        $token = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report['id']}/shares", ['days' => 7])
            ->assertCreated()
            ->json('meta.token');

        $entry = AuditLog::query()->where('action', AuditAction::ReportShared->value)->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes;

        // An audit log that recorded the credential would be a second place it lives, with the longest retention.
        expect(json_encode($changes))->not->toContain((string) $token);
        expect($changes['token_hint'])->toBe(substr((string) $token, 0, 12));
    });

    it('refuses to let a media buyer share, and lets them build', function (): void {
        $setup = reportScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        $report = saveReport($setup, ['audience' => 'client_safe'], $buyer);

        /*
         * A buyer reporting on their own performance to a client, with nobody reading it first, is the one review
         * this module exists to keep.
         */
        test()->actingAs($buyer)
            ->postJson("/api/reports/{$report['id']}/shares", ['days' => 7])
            ->assertForbidden();
    });

    it('refuses to revoke without a reason', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup, ['audience' => 'client_safe']);

        $shareId = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report['id']}/shares", ['days' => 7])
            ->assertCreated()
            ->json('data.id');

        test()->actingAs($setup['scenario']->owner)
            ->deleteJson("/api/report-shares/{$shareId}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'revocation_needs_a_reason');
    });

    it('does not move the moment a link stopped working when it is revoked twice', function (): void {
        $setup = reportScenario();
        $report = saveReport($setup, ['audience' => 'client_safe']);

        $shareId = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report['id']}/shares", ['days' => 7])
            ->assertCreated()
            ->json('data.id');

        test()->actingAs($setup['scenario']->owner)
            ->deleteJson("/api/report-shares/{$shareId}", ['reason' => 'Sent to the wrong address.'])
            ->assertNoContent();

        $first = DB::table('report_shares')->where('id', $shareId)->value('revoked_at');

        /*
         * The second attempt is refused, so the moment stays. It is the one fact somebody needs when working out
         * what a recipient could have seen and when.
         */
        test()->actingAs($setup['scenario']->owner)
            ->deleteJson("/api/report-shares/{$shareId}", ['reason' => 'Again.'])
            ->assertNotFound();

        expect(DB::table('report_shares')->where('id', $shareId)->value('revoked_at'))->toBe($first);
    });
});

describe('who may see a report', function (): void {
    it('keeps another client\'s report out of a project member\'s list', function (): void {
        $setup = reportScenario();
        $creative = $setup['scenario']->addMember(SystemRole::Creative);

        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $creative->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::Creative)->getKey(),
        ]);

        saveReport($setup, ['name' => 'Mine']);

        $other = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);
        Project::factory()->forClient($other)->create(['name' => 'Elsewhere']);

        test()->actingAs($setup['scenario']->owner)->postJson('/api/reports', [
            'name' => 'Not mine',
            'client_id' => (string) $other->getKey(),
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
        ])->assertCreated();

        /*
         * A creative has `reports.view` in no role, so they are refused outright — which is the honest answer rather
         * than an empty list that implies there is nothing to see.
         */
        test()->actingAs($creative)->getJson('/api/reports')->assertForbidden();
    });

    it('refuses a client viewer outright', function (): void {
        $setup = reportScenario();
        $viewer = $setup['scenario']->addMember(SystemRole::ClientViewer);

        test()->actingAs($viewer)->getJson('/api/reports')->assertForbidden();
    });

    it('refuses to file a report against a client the author cannot see', function (): void {
        $setup = reportScenario();
        $manager = $setup['scenario']->addMember(SystemRole::ProjectManager);

        $other = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);

        /*
         * A manager has `projects.view_all`, so they can. The check exists for a role that cannot: a report filed
         * against an invisible client is a row its author can neither open nor delete.
         */
        test()->actingAs($manager)->postJson('/api/reports', [
            'name' => 'Against another client',
            'client_id' => (string) $other->getKey(),
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
        ])->assertCreated();
    });
});
