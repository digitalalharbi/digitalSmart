<?php

declare(strict_types=1);

use App\Application\Intelligence\Contract\LanguageModel;
use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Exception\LanguageModelUnavailable;
use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| The assistant
|--------------------------------------------------------------------------
|
| Asserted against data the real sync wrote from the SANDBOX fixtures, so the findings under test rest on the
| figures the pipeline actually produces.
|
| The four that matter most: every figure in an answer traces to evidence; a language model's narrative is
| discarded when it states a figure the evidence does not contain; an analyser a reader may not run is **named**
| rather than silently dropped; and no mode writes anything anywhere.
|
*/

/** @return array{workspace: SyncedWorkspace, clientId: string} */
function assistantScenario(): array
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
function analyse(array $setup, ?object $as = null): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['workspace']->scenario->owner)
        ->getJson('/api/assistant/analyse?client_id='.$setup['clientId'])
        ->assertOk()
        ->json('data');
}

/** A model that writes whatever it is told to, so the guard can be put in front of it. */
function modelSaying(string $text): LanguageModel
{
    return new class($text) implements LanguageModel
    {
        public function __construct(private readonly string $text) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function name(): string
        {
            return 'test-model';
        }

        public function write(NarrativeBrief $brief): string
        {
            if ($this->text === '') {
                throw LanguageModelUnavailable::because('nothing to say');
            }

            return $this->text;
        }
    };
}

describe('what it answers with', function (): void {
    it('publishes the modes, the questions it understands, and what it does not do yet', function (): void {
        $setup = assistantScenario();

        $vocabulary = test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/assistant/vocabulary')
            ->assertOk()
            ->json('data');

        expect(array_column($vocabulary['modes'], 'mode'))
            ->toBe(['ask', 'analyse', 'investigate', 'report']);

        /* Every mode, and none of them changes anything. */
        foreach ($vocabulary['modes'] as $mode) {
            expect($mode['enacts_anything'])->toBeFalse();
        }

        /*
         * Published rather than omitted. A mode that appeared in the interface and produced nothing would be a
         * placeholder wearing a feature's clothes; one absent with no explanation gets asked about forever.
         */
        expect(array_column($vocabulary['deferred'], 'mode'))->toBe(['plan']);
        expect($vocabulary['deferred'][0]['because'])->toContain('media planner');

        expect(array_column($vocabulary['questions'], 'intent'))->not->toBeEmpty();
        expect($vocabulary['denominator_floor'])->toBe(30);
    });

    it('gives every figure a piece of evidence with a period and a source', function (): void {
        $setup = assistantScenario();
        $answer = analyse($setup);

        expect($answer['findings'])->not->toBeEmpty();

        foreach ($answer['findings'] as $finding) {
            /* A finding with no evidence is not a finding, and cannot reach a reader. */
            expect($finding['evidence'])->not->toBeEmpty();

            foreach ($finding['evidence'] as $evidence) {
                foreach (['period', 'source', 'read_from'] as $field) {
                    expect($evidence[$field])->toBeString();
                    expect($evidence[$field])->not->toBe('');
                }

                /* A figure that is absent says why. A blank becomes a zero in the next calculation. */
                if ($evidence['value'] === null) {
                    expect($evidence['reason'])->not->toBeNull();
                }
            }
        }
    });

    it('labels the whole answer with the weakest source behind it', function (): void {
        $setup = assistantScenario();
        $answer = analyse($setup);

        /*
         * Every provider in this repository is awaiting an authorization it cannot grant itself, so these are
         * sandbox figures — and the answer says so rather than reading as an analysis of a client's spend.
         */
        expect($answer['source'])->toBe('sandbox');
        expect($answer['requires_label'])->toBeTrue();
    });

    it('always says something, so an empty list cannot read as a clean bill of health', function (): void {
        $setup = assistantScenario();
        $answer = analyse($setup);

        /* `PeriodShapeAnalyser` speaks whether or not anything is wrong. */
        $codes = array_column($answer['findings'], 'code');
        expect($codes)->toContain('period_shape');
    });

    it('names the baseline every change figure is measured against', function (): void {
        $setup = assistantScenario();
        $answer = analyse($setup);

        expect($answer['comparison_period']['until'] < $answer['period']['from'])->toBeTrue();
    });
});

describe('the narrative', function (): void {
    it('is written by the product when no model is configured', function (): void {
        $setup = assistantScenario();
        $answer = analyse($setup);

        /*
         * The default, and not a degraded mode: every finding and every figure is computed by the product, so a
         * missing model costs a reader better prose and nothing else.
         */
        expect($answer['narrative']['written_by'])->toBe('product');
        expect($answer['narrative']['model'])->toBeNull();
        expect($answer['narrative']['model_rejected'])->toBeFalse();
        expect($answer['narrative']['text'])->not->toBe('');
    });

    it('uses a model\'s prose when it only restates the evidence', function (): void {
        $setup = assistantScenario();

        app()->instance(LanguageModel::class, modelSaying('The account delivered against the period before.'));

        $answer = analyse($setup);

        expect($answer['narrative']['written_by'])->toBe('model');
        expect($answer['narrative']['model'])->toBe('test-model');
        expect($answer['narrative']['text'])->toBe('The account delivered against the period before.');
    });

    it('discards a model\'s prose that states a figure the evidence does not contain', function (): void {
        $setup = assistantScenario();

        /* A plausible, well-written, entirely invented figure: the exact failure this module exists to prevent. */
        app()->instance(LanguageModel::class, modelSaying('Spend rose by 4812.77 riyals on the campaign.'));

        $answer = analyse($setup);

        expect($answer['narrative']['model_rejected'])->toBeTrue();
        expect($answer['narrative']['written_by'])->toBe('product');
        expect($answer['narrative']['rejection_reason'])->toContain('4812.77');

        /*
         * Published rather than swallowed. A guard nobody can see working is a guard nobody will maintain — and a
         * reader shown the fallback with no explanation would never know a model had been overruled.
         */
        expect($answer['narrative']['text'])->not->toContain('4812.77');
    });

    it('falls back without failing when a model cannot be reached', function (): void {
        $setup = assistantScenario();

        app()->instance(LanguageModel::class, modelSaying(''));

        $answer = analyse($setup);

        /* A third party being busy must not cost a reader the ability to read their own figures. */
        expect($answer['narrative']['written_by'])->toBe('product');
        expect($answer['narrative']['model_rejected'])->toBeFalse();
    });
});

describe('asking a question', function (): void {
    it('answers one of the questions it understands', function (): void {
        $setup = assistantScenario();

        $answer = test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson('/api/assistant/ask', [
                'client_id' => $setup['clientId'],
                'question' => 'How much did we spend?',
            ])
            ->assertOk()
            ->json('data');

        expect($answer['intent'])->toBe('spend');
        expect($answer['findings'])->not->toBeEmpty();
    });

    it('refuses a question it does not recognise, and answers nothing', function (): void {
        $setup = assistantScenario();

        $answer = test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson('/api/assistant/ask', [
                'client_id' => $setup['clientId'],
                'question' => 'Write me a poem about Riyadh',
            ])
            ->assertOk()
            ->json('data');

        /*
         * The refusal *is* the answer. Handing somebody an analysis they did not ask for, in response to a
         * question that was not understood, teaches them the assistant answered.
         */
        expect($answer['intent'])->toBeNull();
        expect($answer['findings'])->toBe([]);
        expect($answer['narrative']['text'])->toContain('not understood');
    });
});

describe('investigating a finding', function (): void {
    it('adds the same figures broken down by platform', function (): void {
        $setup = assistantScenario();
        $answer = analyse($setup);

        $deeper = test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/assistant/findings/period_shape?client_id='.$setup['clientId'])
            ->assertOk()
            ->json('data');

        expect($deeper['findings'])->toHaveCount(1);

        /* A rate that moved because the mix moved is not a rate that moved, and a total cannot tell them apart. */
        $sources = array_column($deeper['findings'][0]['evidence'], 'read_from');
        expect($sources)->toContain('analytics by platform');

        expect(count($deeper['findings'][0]['evidence']))
            ->toBeGreaterThan(count($answer['findings'][0]['evidence']));
    });

    it('refuses a finding this period\'s analysis did not produce', function (): void {
        $setup = assistantScenario();

        /*
         * A stale link gets "not found" rather than an empty page: the finding may well have been real last
         * week, and its absence now is information rather than an all-clear.
         */
        test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/assistant/findings/efficiency_drift.nonsense?client_id='.$setup['clientId'])
            ->assertNotFound();
    });
});

describe('proposing a report', function (): void {
    it('proposes a definition the reporting module would accept, and saves nothing', function (): void {
        $setup = assistantScenario();

        $before = Illuminate\Support\Facades\DB::table('reports')->count();

        $proposal = test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/assistant/report?client_id='.$setup['clientId'])
            ->assertOk()
            ->json('data.proposal');

        expect($proposal['audience'])->toBe('internal');
        expect($proposal['window'])->toBe('last_30_days');
        expect($proposal['sections'])->not->toBeEmpty();

        /* Proposed, not saved. The reporting module is where a person decides. */
        expect(Illuminate\Support\Facades\DB::table('reports')->count())->toBe($before);

        // And the proposal is one the reporting module accepts, which is the point of validating it first.
        test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson('/api/reports', [
                'name' => $proposal['name'],
                'client_id' => $proposal['client_id'],
                'window' => $proposal['window'],
                'comparison' => $proposal['comparison'],
                'audience' => $proposal['audience'],
                'sections' => array_map(
                    static fn (array $section): array => array_filter(
                        [
                            'kind' => $section['kind'],
                            'metrics' => $section['metrics'],
                            'dimension' => $section['dimension'],
                        ],
                        static fn (mixed $value): bool => $value !== null,
                    ),
                    $proposal['sections'],
                ),
            ])
            ->assertCreated();
    });

    it('is refused to a reader who could not save a report anyway', function (): void {
        $setup = assistantScenario();
        $creative = $setup['workspace']->scenario->addMember(SystemRole::Creative);

        ProjectMember::query()->create([
            'project_id' => $setup['workspace']->project(Provider::Meta)->getKey(),
            'user_id' => $creative->getKey(),
            'role_id' => $setup['workspace']->scenario->role(SystemRole::Creative)->getKey(),
        ]);

        /* A proposal nobody may act on is a waste of a reader's attention. */
        test()->actingAs($creative)
            ->getJson('/api/assistant/report?client_id='.$setup['clientId'])
            ->assertForbidden();
    });
});

describe('who may ask', function (): void {
    it('names the analysers a reader\'s permissions kept out of the answer', function (): void {
        $setup = assistantScenario();

        $buyer = $setup['workspace']->scenario->addMember(SystemRole::MediaBuyer);
        ProjectMember::query()->create([
            'project_id' => $setup['workspace']->project(Provider::Meta)->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['workspace']->scenario->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        /* A buyer holds `tracking.view`, so nothing is skipped for them. */
        expect(analyse($setup, $buyer)['skipped'])->toBe([]);

        $creative = $setup['workspace']->scenario->addMember(SystemRole::Creative);
        ProjectMember::query()->create([
            'project_id' => $setup['workspace']->project(Provider::Meta)->getKey(),
            'user_id' => $creative->getKey(),
            'role_id' => $setup['workspace']->scenario->role(SystemRole::Creative)->getKey(),
        ]);

        /* And a creative holds no analytics permission at all, so there is nothing to answer. */
        test()->actingAs($creative)
            ->getJson('/api/assistant/analyse?client_id='.$setup['clientId'])
            ->assertForbidden();
    });

    it('reports a client it cannot see as not found, rather than naming it', function (): void {
        $setup = assistantScenario();

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

        /*
         * Visibility is checked before the name is read. The other way round would let a reader learn that a
         * client exists, and what it is called, by asking about one they are not assigned to.
         */
        test()->actingAs($buyer)
            ->getJson('/api/assistant/analyse?client_id='.$other->getKey())
            ->assertNotFound();
    });

    it('requires a client rather than averaging across every one a reader can see', function (): void {
        $setup = assistantScenario();

        /* A cost per click averaged over four unrelated accounts is a number with no owner. */
        test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson('/api/assistant/analyse')
            ->assertNotFound();
    });
});
