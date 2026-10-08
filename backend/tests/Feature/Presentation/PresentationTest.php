<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| Presentations
|--------------------------------------------------------------------------
|
| Asserted against data the real sync wrote from the SANDBOX fixtures, so a deck's figures are the figures the
| pipeline produces.
|
| The four that matter most: a deck reads the same aggregation as the reporting surface, so it cannot disagree with
| the report it was made from; the caveats are a **slide** rather than a footnote; nothing is saved by generating
| one; and the downloaded file is a real, openable PowerPoint rather than something named .pptx.
|
*/

/** @return array{workspace: SyncedWorkspace, clientId: string} */
function deckScenario(): array
{
    $workspace = SyncedWorkspace::default();

    return [
        'workspace' => $workspace,
        'clientId' => (string) $workspace->project(Provider::Meta)->client_id,
    ];
}

/**
 * @param  array{workspace: SyncedWorkspace, clientId: string}  $setup
 * @return array<string, mixed>
 */
function deck(array $setup, string $kind = 'monthly_report', ?object $as = null): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['workspace']->scenario->owner)
        ->getJson('/api/presentations/'.$kind.'?client_id='.$setup['clientId'])
        ->assertOk()
        ->json('data');
}

/**
 * @param  array<string, mixed>  $deck
 * @return list<array<string, mixed>>
 */
function slidesOfKind(array $deck, string $kind): array
{
    /** @var list<array<string, mixed>> $slides */
    $slides = $deck['slides'];

    return array_values(array_filter($slides, static fn (array $slide): bool => $slide['kind'] === $kind));
}

describe('the vocabulary', function (): void {
    it('publishes the decks it builds and the one it does not', function (): void {
        $setup = deckScenario();

        $vocabulary = test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/presentations/vocabulary')
            ->assertOk()
            ->json('data');

        expect(array_column($vocabulary['kinds'], 'kind'))
            ->toBe(['weekly_report', 'monthly_report', 'closing_report', 'media_plan']);

        /*
         * Published rather than omitted, and rather than offered as a template that produces empty slides. A
         * proposal is a document an agency writes, and composing one from last month's delivery would produce a
         * proposal shaped entirely by what was easiest to measure.
         */
        expect(array_column($vocabulary['deferred'], 'kind'))->toBe(['campaign_proposal']);
        expect($vocabulary['deferred'][0]['because'])->toContain('what is missing is the brief');
    });

    it('gives each deck a period that is part of what it means', function (): void {
        $setup = deckScenario();

        $kinds = test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/presentations/vocabulary')
            ->assertOk()
            ->json('data.kinds');

        $byKind = [];

        foreach ($kinds as $entry) {
            $byKind[$entry['kind']] = $entry;
        }

        /* A "weekly report" over ninety days is a different document wearing the same name. */
        expect($byKind['weekly_report']['window'])->toBe('last_7_days');
        expect($byKind['monthly_report']['window'])->toBe('last_month');
        expect($byKind['closing_report']['window'])->toBe('last_90_days');

        /* And a closing report has nothing after it to compare with. */
        expect($byKind['closing_report']['compares_periods'])->toBeFalse();
        expect($byKind['monthly_report']['compares_periods'])->toBeTrue();
    });
});

describe('what a deck carries', function (): void {
    it('opens with a title, and says when it was prepared', function (): void {
        $setup = deckScenario();
        $data = deck($setup);

        expect($data['slides'][0]['kind'])->toBe('title');
        /* A deck is a file that gets forwarded; six weeks later nobody remembers which Tuesday it describes. */
        expect($data['slides'][0]['notes'][0])->toContain('Prepared');
        expect($data['prepared_at'])->toBeString();
    });

    it('labels the whole deck with the weakest source behind it', function (): void {
        $setup = deckScenario();
        $data = deck($setup);

        /*
         * Every provider in this repository is awaiting an authorization it cannot grant itself, so these are
         * sandbox figures — and a deck built from one sandbox figure is a sandbox deck.
         */
        expect($data['source'])->toBe('sandbox');
        expect($data['requires_label'])->toBeTrue();
    });

    it('makes the caveats a slide rather than a footnote', function (): void {
        $setup = deckScenario();
        $data = deck($setup);

        $caveats = slidesOfKind($data, 'caveat');

        /*
         * A deck is read in a room, out loud, by somebody who may not have built it. A caveat in six-point type
         * is a caveat the projector crops.
         */
        expect($caveats)->toHaveCount(1);
        expect($caveats[0]['paragraphs'])->not->toBeEmpty();
        expect($caveats[0]['lead'])->toContain('not skipped');

        $joined = implode(' ', $caveats[0]['paragraphs']);
        expect($joined)->toContain('Sandbox');
    });

    it('states a source on every figure, because a slide gets photographed', function (): void {
        $setup = deckScenario();
        $data = deck($setup);

        $scorecards = slidesOfKind($data, 'scorecard');
        expect($scorecards)->not->toBeEmpty();

        foreach ($scorecards[0]['figures'] as $figure) {
            expect($figure['source_label'])->toBeString();
            expect($figure['source_label'])->not->toBe('');
            /* Already formatted: no renderer formats a number, so the file and the screen agree. */
            expect($figure['value'])->toBeString();
        }
    });

    it('keeps a gap in a trend as a gap', function (): void {
        $setup = deckScenario();
        $data = deck($setup);

        $trends = slidesOfKind($data, 'trend');
        expect($trends)->not->toBeEmpty();
        expect($trends[0]['series'])->not->toBeEmpty();

        /*
         * The points are numbers or nulls, never zeroes standing in for nulls: a day with no delivery drawn as
         * zero is a line through the floor of a chart somebody presents to a client.
         */
        foreach ($trends[0]['series'][0]['points'] as $point) {
            expect($point === null || is_numeric($point))->toBeTrue();
        }
    });

    it('carries the theme, and says it is a placeholder', function (): void {
        $setup = deckScenario();
        $data = deck($setup);

        /*
         * Smart Code's own presentation identity has not been provided to this repository. The theme is tokens,
         * every renderer reads them, and the deck says the identity is provisional — a placeholder presented as
         * an agency's brand is worse than an obvious placeholder.
         */
        expect($data['theme']['is_provisional'])->toBeTrue();
        expect($data['theme']['name'])->toContain('provisional');

        /* Two faces, because Arabic set in a Latin font is Arabic that has been let down. */
        expect($data['theme']['arabic_font'])->toBe('IBM Plex Sans Arabic');
        expect($data['theme']['latin_font'])->toBe('Inter');
    });

    it('builds a weekly deck without the campaign table a monthly one files', function (): void {
        $setup = deckScenario();

        expect(slidesOfKind(deck($setup, 'weekly_report'), 'table'))->toBe([]);
        expect(slidesOfKind(deck($setup, 'monthly_report'), 'table'))->not->toBe([]);
    });

    it('carries the assistant\'s own commentary, attributed', function (): void {
        $setup = deckScenario();
        $data = deck($setup);

        $commentary = slidesOfKind($data, 'commentary');
        expect($commentary)->toHaveCount(1);

        /*
         * Who wrote it, on the slide. A paragraph composed by a language model and one assembled by the product
         * are both legitimate; presenting one as the other is not, and a deck is read by somebody who was not in
         * the room when it was made.
         */
        expect($commentary[0]['notes'][0])->toContain('written by digitalSmart');
    });
});

describe('what generating a deck does not do', function (): void {
    it('saves no report', function (): void {
        $setup = deckScenario();
        $before = DB::table('reports')->count();

        deck($setup);

        /* A deck is output. Nothing in the reporting module gains a row because somebody generated one. */
        expect(DB::table('reports')->count())->toBe($before);
        expect(DB::table('report_sections')->count())->toBe(0);
    });

    it('never carries the agency\'s internal note', function (): void {
        $setup = deckScenario();
        $owner = $setup['workspace']->scenario->owner;

        test()->actingAs($owner)->postJson('/api/reports', [
            'name' => 'Internal',
            'client_id' => $setup['clientId'],
            'window' => 'last_month',
            'comparison' => 'none',
            'audience' => 'internal',
            'internal_note' => 'Do not mention the churn risk on the call.',
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
        ])->assertCreated();

        $response = test()->actingAs($owner)
            ->getJson('/api/presentations/monthly_report?client_id='.$setup['clientId'])
            ->assertOk();

        /*
         * A deck is a file that gets forwarded, and one carrying the agency's commentary is one note away from a
         * difficult conversation. The audience is passed explicitly to the renderer, so the note is never loaded
         * on this path rather than loaded and dropped.
         */
        $response->assertDontSee('churn risk', escape: false);
        expect($response->json('data.is_client_safe'))->toBeTrue();
    });
});

describe('the file', function (): void {
    it('downloads a real, openable PowerPoint', function (): void {
        $setup = deckScenario();

        $response = test()->actingAs($setup['workspace']->scenario->owner)
            ->get('/api/presentations/monthly_report/pptx?client_id='.$setup['clientId']);

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        );

        $path = tempnam(sys_get_temp_dir(), 'deck-test');
        expect($path)->toBeString();
        /*
         * `getContent()`, not `streamedContent()`: the endpoint reads the written file and returns it with an
         * explicit length rather than streaming it from disk. See the note on the controller — a streamed binary
         * response worked here and returned 500 through a real server.
         */
        file_put_contents((string) $path, (string) $response->getContent());

        /*
         * Opened as a zip and read, rather than trusted because it has the right extension. "Editable" is the
         * requirement, and a file PowerPoint cannot open is a deck somebody re-makes by hand.
         */
        $zip = new ZipArchive;
        expect($zip->open((string) $path))->toBeTrue();

        expect($zip->locateName('[Content_Types].xml'))->not->toBeFalse();
        expect($zip->locateName('ppt/presentation.xml'))->not->toBeFalse();
        expect($zip->locateName('ppt/slides/slide1.xml'))->not->toBeFalse();

        $first = (string) $zip->getFromName('ppt/slides/slide1.xml');
        /* The client's name is on the title slide, as text a reader can select rather than as a picture. */
        expect($first)->toContain('<a:t>');

        $zip->close();
        @unlink((string) $path);
    });

    it('names the file after the client and the day it was made', function (): void {
        $setup = deckScenario();

        $response = test()->actingAs($setup['workspace']->scenario->owner)
            ->get('/api/presentations/weekly_report/pptx?client_id='.$setup['clientId']);

        $response->assertOk();

        /* A deck is a file that gets forwarded; a dated filename is how somebody finds it again. */
        expect($response->headers->get('content-disposition'))
            ->toContain(now()->format('Y-m-d').'.pptx');
    });
});

describe('who may generate one', function (): void {
    it('refuses a reader who cannot read a report', function (): void {
        $setup = deckScenario();
        $creative = $setup['workspace']->scenario->addMember(SystemRole::Creative);

        ProjectMember::query()->create([
            'project_id' => $setup['workspace']->project(Provider::Meta)->getKey(),
            'user_id' => $creative->getKey(),
            'role_id' => $setup['workspace']->scenario->role(SystemRole::Creative)->getKey(),
        ]);

        /* A deck is a report laid out for a room, so it is gated on the permission a report is. */
        test()->actingAs($creative)
            ->getJson('/api/presentations/monthly_report?client_id='.$setup['clientId'])
            ->assertForbidden();
    });

    it('reports a client it cannot see as not found', function (): void {
        $setup = deckScenario();

        $other = Client::factory()->create([
            'workspace_id' => $setup['workspace']->scenario->workspace->getKey(),
            'name' => 'Somebody else',
        ]);
        Project::factory()->forClient($other)->create();

        $buyer = $setup['workspace']->scenario->addMember(SystemRole::MediaBuyer);
        ProjectMember::query()->create([
            'project_id' => $setup['workspace']->project(Provider::Meta)->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['workspace']->scenario->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        test()->actingAs($buyer)
            ->getJson('/api/presentations/monthly_report?client_id='.$other->getKey())
            ->assertNotFound();
    });

    it('refuses a media plan asked for with a client instead of a budget', function (): void {
        $setup = deckScenario();

        /*
         * A media plan is composed from a budget somebody allocated. Answering with a performance deck wearing
         * the wrong title would be worse than refusing.
         */
        test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/presentations/media_plan?client_id='.$setup['clientId'])
            ->assertNotFound();
    });

    it('refuses a deck kind it does not build', function (): void {
        $setup = deckScenario();

        test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/presentations/campaign_proposal?client_id='.$setup['clientId'])
            ->assertNotFound();
    });
});

describe('a media plan deck', function (): void {
    it('presents a plan somebody made, with what has been spent against it', function (): void {
        $setup = deckScenario();
        $owner = $setup['workspace']->scenario->owner;

        $budgetId = test()->actingAs($owner)
            ->postJson('/api/budgets', [
                'client_id' => $setup['clientId'],
                'name' => 'Q4 retail',
                'period_start' => '2026-10-01',
                'period_end' => '2026-12-31',
                'amount' => '180000.00',
                'currency' => 'SAR',
            ])
            ->assertCreated()
            ->json('data.id');

        test()->actingAs($owner)
            ->putJson("/api/budgets/{$budgetId}/allocations", [
                'allocations' => [
                    ['level' => 'platform', 'provider' => 'meta', 'label' => 'Meta', 'amount' => '100000.00'],
                    ['level' => 'platform', 'provider' => 'google_ads', 'label' => 'Google', 'amount' => '50000.00'],
                ],
            ])
            ->assertOk();

        $data = test()->actingAs($owner)
            ->getJson('/api/presentations/media_plan?budget_id='.$budgetId)
            ->assertOk()
            ->json('data');

        expect($data['kind'])->toBe('media_plan');

        $plan = slidesOfKind($data, 'scorecard')[0];
        $labels = array_column($plan['figures'], 'label');

        /*
         * The unallocated remainder is its own figure rather than a subtraction left to the reader: it is either
         * deliberate or an oversight, and a deck that hid it makes both look the same.
         */
        expect($labels)->toContain('Budget', 'Allocated', 'Not yet allocated', 'Spent so far');

        $byPlatform = slidesOfKind($data, 'breakdown')[0];
        expect($byPlatform['categories'])->toEqualCanonicalizing(['Meta', 'Google']);

        /* The allocation is a decision somebody made, and the deck says so rather than implying a calculation. */
        $caveats = implode(' ', slidesOfKind($data, 'caveat')[0]['paragraphs']);
        expect($caveats)->toContain('a decision somebody made');
    });

    it('says the budget\'s status on the title slide', function (): void {
        $setup = deckScenario();
        $owner = $setup['workspace']->scenario->owner;

        $budgetId = test()->actingAs($owner)
            ->postJson('/api/budgets', [
                'client_id' => $setup['clientId'],
                'name' => 'Q4 retail',
                'period_start' => '2026-10-01',
                'period_end' => '2026-12-31',
                'amount' => '180000.00',
                'currency' => 'SAR',
            ])
            ->assertCreated()
            ->json('data.id');

        $data = test()->actingAs($owner)
            ->getJson('/api/presentations/media_plan?budget_id='.$budgetId)
            ->assertOk()
            ->json('data');

        /*
         * A draft allocation presented as a plan is how a number nobody agreed gets quoted back. The deck is
         * still generated — an agency may legitimately be presenting a proposal — and the status is in words.
         */
        expect(implode(' ', $data['slides'][0]['notes']))->toContain('Budget status:');
    });
});
