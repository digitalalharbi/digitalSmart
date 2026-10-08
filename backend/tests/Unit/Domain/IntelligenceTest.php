<?php

declare(strict_types=1);

use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Service\DeterministicNarrative;
use App\Application\Intelligence\Service\FigureScanner;
use App\Application\Intelligence\Service\NarrativeGuard;
use App\Domain\Advertising\MetricSource;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Confidence;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;
use App\Domain\Intelligence\QuestionIntent;

/*
|--------------------------------------------------------------------------
| The assistant's vocabulary and its guard
|--------------------------------------------------------------------------
|
| One claim holds this module up: **the product computes every number and the model only writes the prose.** The
| guard is what makes that claim checkable rather than aspirational, so most of these tests are about it.
|
| The rest are about the refusals. A rate with no denominator worth quoting; a question that matched no intent; a
| recommendation on evidence too weak to carry one. Each of them is a case where the easy behaviour is to answer
| anyway, and answering anyway is how an analytics tool produces a confident lie.
|
*/

function evidence(string $metric, ?float $value, ?float $comparedWith = null, MetricSource $source = MetricSource::Synced): Evidence
{
    return new Evidence(
        metric: $metric,
        label: ucfirst(str_replace('_', ' ', $metric)),
        value: $value,
        comparedWith: $comparedWith,
        period: 'the last thirty days',
        source: $source,
        readFrom: 'analytics totals',
    );
}

/** @param list<Evidence> $evidence */
function finding(array $evidence, FindingSeverity $severity = FindingSeverity::Warning, ?string $recommendation = null): Finding
{
    return new Finding(
        code: 'test.finding',
        severity: $severity,
        subject: 'The account',
        headline: 'Cost per click is worse than the period before.',
        detail: 'A move of this size is usually either a change in what is being bid on or a change in what is '
            .'being measured.',
        evidence: $evidence,
        confidence: Confidence::Firm,
        recommendation: $recommendation,
    );
}

/** @param list<Finding> $findings */
function brief(array $findings, AssistantMode $mode = AssistantMode::Analyse): NarrativeBrief
{
    return NarrativeBrief::of($mode, 'the last thirty days', $findings, 30, new FigureScanner);
}

function guard(): NarrativeGuard
{
    return new NarrativeGuard(new FigureScanner);
}

describe('the narrative guard', function (): void {
    it('accepts prose that only restates the figures it was given', function (): void {
        $brief = brief([finding([evidence('cpc', 4.2, 2.1), evidence('cpc_change_percent', 100.0)])]);

        $verdict = guard()->inspect(
            'Cost per click reached 4.2, against 2.1 in the period before — a rise of 100%.',
            $brief->allowedFigures,
        );

        expect($verdict['ok'])->toBeTrue();
    });

    it('refuses a figure the evidence does not contain', function (): void {
        $brief = brief([finding([evidence('cpc', 4.2, 2.1)])]);

        /*
         * The arithmetic is even correct. That is exactly the case worth refusing: a model that computes
         * correctly today computes incorrectly on the day a denominator is missing, and nothing downstream can
         * tell the two apart.
         */
        $verdict = guard()->inspect('Cost per click doubled, a rise of 100%.', $brief->allowedFigures);

        expect($verdict['ok'])->toBeFalse();
        expect($verdict['offending'])->toBe(['100']);
    });

    it('does not reject a figure for being rounded', function (): void {
        $brief = brief([finding([evidence('cpc', 4.2037)])]);

        /* "4.20" and 4.2037 are the same figure written differently; refusing that would refuse good prose. */
        expect(guard()->inspect('Cost per click was 4.20.', $brief->allowedFigures)['ok'])->toBeTrue();
    });

    it('allows only the error the written precision implies', function (): void {
        $brief = brief([finding([evidence('cpc', 4.2)])]);

        /*
         * The distinction that makes the tolerance principled rather than generous. One decimal place buys half
         * of one decimal place, so "4.2" is the figure and "4.5" is a different figure — even though half a unit
         * would have covered it.
         */
        expect(guard()->inspect('Cost per click was 4.2.', $brief->allowedFigures)['ok'])->toBeTrue();
        expect(guard()->inspect('Cost per click was 4.5.', $brief->allowedFigures)['ok'])->toBeFalse();
    });

    it('reads a thousands separator as a separator', function (): void {
        $brief = brief([finding([evidence('spend', 1_250_000.0)])]);

        foreach (['1,250,000', '1250000'] as $written) {
            expect(guard()->inspect("Spend was {$written}.", $brief->allowedFigures)['ok'])->toBeTrue();
        }

        // And one digit out is one digit out.
        expect(guard()->inspect('Spend was 1,250,001.', $brief->allowedFigures)['ok'])->toBeFalse();
    });

    it('does not exempt numbers that look like years', function (): void {
        $brief = brief([finding([evidence('spend', 1_250.0)])]);

        /*
         * It would be convenient to let four digits through. That is the hole "up 2024%" walks through, so prose
         * that needs a date gets it from the evidence like everything else.
         */
        expect(guard()->inspect('Spend rose through 2024 to 1250.', $brief->allowedFigures)['ok'])->toBeFalse();
    });

    it('permits a number the brief itself stated', function (): void {
        $brief = brief([finding([evidence('spend', 500.0)])]);

        /* The period's length and the number of findings are in the brief, so prose may repeat them. */
        expect(guard()->inspect('Across 30 days there was 1 finding.', $brief->allowedFigures)['ok'])
            ->toBeTrue();
    });
});

describe('the product\'s own narrative', function (): void {
    it('passes the guard it holds a model to', function (): void {
        $brief = brief([
            finding([evidence('cpc', 4.2, 2.1), evidence('cpc_change_percent', 100.0)]),
            finding([evidence('spend', 1_250.0)], FindingSeverity::Context),
            finding([evidence('conversions', null)], FindingSeverity::Insufficient),
        ]);

        $text = (new DeterministicNarrative)->write($brief);

        /*
         * The invariant that keeps the fallback honest. If the product's own prose could state a figure the
         * evidence does not support, the guard would be holding a model to a standard the product does not meet.
         */
        expect(guard()->inspect($text, $brief->allowedFigures)['ok'])->toBeTrue();
    });

    it('says when some questions went unanswered', function (): void {
        $brief = brief([
            finding([evidence('cpc', 4.2, 2.1)]),
            finding([evidence('conversions', null)], FindingSeverity::Insufficient),
        ]);

        /* A list where some questions were not answered reads, to somebody skimming, like a favourable one. */
        expect((new DeterministicNarrative)->write($brief))->toContain('could not be answered');
    });

    it('does not imply health when nothing was found', function (): void {
        $text = (new DeterministicNarrative)->write(brief([]));

        expect($text)->toContain('not the same as nothing having happened');
    });
});

describe('confidence', function (): void {
    it('refuses to be firm about a rate with too small a denominator', function (): void {
        expect(Confidence::of(MetricSource::Live, 9, true))->toBe(Confidence::Indicative);
        expect(Confidence::of(MetricSource::Live, Confidence::DENOMINATOR_FLOOR, true))->toBe(Confidence::Firm);
    });

    it('will not be firm about a figure the provider has not confirmed', function (): void {
        /* A pattern in fixtures is a pattern in fixtures, whatever the denominator. */
        expect(Confidence::of(MetricSource::Sandbox, 10_000, true))->toBe(Confidence::Indicative);
        expect(Confidence::of(MetricSource::Estimated, 10_000, true))->toBe(Confidence::Indicative);

        // Real but old, incomplete, or a person's assertion: worth saying, not worth acting on alone.
        foreach ([MetricSource::Stale, MetricSource::Partial, MetricSource::Manual] as $source) {
            expect(Confidence::of($source, 10_000, true))->toBe(Confidence::Tentative);
        }
    });

    it('is never the basis of a recommendation when it is only indicative', function (): void {
        expect(Confidence::Indicative->supportsRecommendation())->toBeFalse();
        expect(Confidence::Tentative->supportsRecommendation())->toBeTrue();
        expect(Confidence::Firm->supportsRecommendation())->toBeTrue();
    });

    it('treats a missing comparison period as indicative however much data there is', function (): void {
        expect(Confidence::of(MetricSource::Live, 10_000, false))->toBe(Confidence::Indicative);
    });
});

describe('question intents', function (): void {
    it('matches the questions somebody would actually type', function (): void {
        expect(QuestionIntent::match('How much did we spend last month?'))->toBe(QuestionIntent::Spend);
        expect(QuestionIntent::match('What is our cost per click?'))->toBe(QuestionIntent::Efficiency);
        expect(QuestionIntent::match('Is our tracking working?'))->toBe(QuestionIntent::Tracking);
    });

    it('matches a question asked in Arabic', function (): void {
        /* The interface is bilingual, and a reader asking in Arabic is not asking a lesser question. */
        expect(QuestionIntent::match('كم أنفقنا الشهر الماضي؟'))->toBe(QuestionIntent::Spend);
        expect(QuestionIntent::match('هل التتبع يعمل؟'))->toBe(QuestionIntent::Tracking);
    });

    it('refuses a question it does not recognise', function (): void {
        /*
         * The refusal is the point. An assistant that guessed would eventually answer a different question
         * convincingly, and nobody would notice because the answer looks fine.
         */
        expect(QuestionIntent::match('Write me a poem about Riyadh'))->toBeNull();
        expect(QuestionIntent::match('   '))->toBeNull();
    });

    it('publishes an example of every question it understands', function (): void {
        foreach (QuestionIntent::cases() as $intent) {
            expect($intent->example())->not->toBe('');
            expect($intent->keywords())->not->toBeEmpty();
        }
    });
});

describe('what the assistant will not do', function (): void {
    it('enacts nothing, in any mode', function (): void {
        /*
         * The sentence the whole module rests on. Nothing here writes a report, a budget, or a byte to an
         * advertising platform — the same argument the approvals engine makes about why approval is not
         * execution.
         */
        foreach (AssistantMode::cases() as $mode) {
            expect($mode->enactsAnything())->toBeFalse();
        }
    });

    it('treats a finding with no evidence as unsupported', function (): void {
        expect(finding([])->isSupported())->toBeFalse();
        expect(finding([evidence('spend', 1.0)])->isSupported())->toBeTrue();
    });

    it('labels a finding with the weakest source behind it', function (): void {
        $mixed = finding([
            evidence('spend', 100.0, null, MetricSource::Live),
            evidence('clicks', 50.0, null, MetricSource::Sandbox),
        ]);

        /* One sandbox figure among four live ones makes a sandbox finding. */
        expect($mixed->source())->toBe(MetricSource::Sandbox);
    });
});
