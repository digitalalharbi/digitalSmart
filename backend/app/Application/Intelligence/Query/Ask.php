<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Query;

use App\Application\Intelligence\DTO\AnalysisView;
use App\Application\Intelligence\DTO\Narrative;
use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Service\FigureScanner;
use App\Application\Intelligence\Service\Narrator;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;
use App\Domain\Intelligence\QuestionIntent;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * A question in words, answered from the intents this product recognises.
 *
 * It runs the full analysis and then **selects** from it, rather than running a query the question described. That
 * is the whole safety argument: the figures are computed the same way whatever is typed, so a question cannot
 * choose its own denominator. A reader asking "why did our cost per click go up" and a reader asking for an
 * analysis are looking at the same arithmetic.
 *
 * **A question that matches no intent is refused**, with the list of questions that are understood. That is less
 * impressive than a plausible answer and far more useful: an assistant that guesses which question was meant will
 * eventually answer a different one convincingly, and nobody will notice because the answer looks fine.
 */
final readonly class Ask
{
    public function __construct(
        private Analyse $analyse,
        private Narrator $narrator,
        private FigureScanner $figures,
        private AuthorizationGuard $guard,
    ) {}

    public function __invoke(
        string $clientId,
        string $clientName,
        DateRange $period,
        string $question,
    ): AnalysisView {
        $this->guard->ensure(AssistantMode::Ask->requires());

        $intent = QuestionIntent::match($question);
        $analysis = ($this->analyse)($clientId, $clientName, $period);

        if ($intent === null) {
            /*
             * Refused, and the refusal is the answer. The findings are dropped rather than offered as a
             * consolation: handing somebody an analysis they did not ask for, in response to a question that was
             * not understood, teaches them the assistant answered.
             */
            return new AnalysisView(
                mode: AssistantMode::Ask,
                clientName: $clientName,
                period: $period,
                before: $analysis->before,
                findings: [],
                narrative: Narrative::fromProduct(
                    'That question was not understood, so it has not been answered. The questions this '
                    .'assistant recognises are listed beside the box; an analysis of the whole period is always '
                    .'available from the Analyse tab.',
                ),
                skipped: $analysis->skipped,
                question: $question,
                intent: null,
            );
        }

        $selected = $this->select($analysis->findings, $intent);

        $brief = NarrativeBrief::of(
            AssistantMode::Ask,
            sprintf('%s to %s', $period->startDate(), $period->endDate()),
            $selected,
            $period->lengthInDays(),
            $this->figures,
        );

        return new AnalysisView(
            mode: AssistantMode::Ask,
            clientName: $clientName,
            period: $period,
            before: $analysis->before,
            findings: $selected,
            narrative: $this->narrator->narrate($brief),
            skipped: $analysis->skipped,
            question: $question,
            intent: $intent->value,
        );
    }

    /**
     * The findings that bear on this intent.
     *
     * Selection is by the metric names in the evidence rather than by analyser, because an intent is about a
     * figure and several analysers can speak to the same one. When nothing matches, the period's own shape is
     * returned — which is the honest answer to "what did we spend" in a period where nothing unusual happened,
     * and is never an empty answer to a question that was understood.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function select(array $findings, QuestionIntent $intent): array
    {
        $wanted = match ($intent) {
            QuestionIntent::Spend => ['spend', 'spend_change_percent', 'spend_share_percent'],
            QuestionIntent::Efficiency => ['cpc', 'cpc_change_percent', 'cost_per_conversion', 'cost_per_conversion_change_percent'],
            QuestionIntent::Delivery => ['impressions', 'clicks', 'ctr'],
            QuestionIntent::Conversions => ['conversions', 'cost_per_conversion'],
            QuestionIntent::Change => ['spend_change_percent', 'cpc_change_percent', 'cost_per_conversion_change_percent'],
            QuestionIntent::Tracking => ['tracking_critical_issues'],
        };

        $selected = array_values(array_filter(
            $findings,
            static function (Finding $finding) use ($wanted): bool {
                foreach ($finding->evidence as $evidence) {
                    if (in_array($evidence->metric, $wanted, true)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        if ($selected !== []) {
            return $selected;
        }

        /*
         * Nothing bore on the question. The period's own shape is the answer — and if even that is absent, the
         * one finding saying the period recorded nothing.
         */
        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => str_starts_with($finding->code, 'period_shape')
                || $finding->severity === FindingSeverity::Insufficient,
        ));
    }
}
