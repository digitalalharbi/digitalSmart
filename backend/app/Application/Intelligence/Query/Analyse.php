<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Query;

use App\Application\Intelligence\Contract\Analyser;
use App\Application\Intelligence\DTO\AnalysisView;
use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Service\AnalysisAssembler;
use App\Application\Intelligence\Service\FigureScanner;
use App\Application\Intelligence\Service\Narrator;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Finding;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * Everything the analysers can say about a client and a period.
 *
 * Two rules govern what comes back.
 *
 * **Only the analysers the reader may run are run**, and the ones that were not are **named** in `skipped`. An
 * assistant that silently omitted a finding a reader lacked the permission for would be an assistant whose
 * answers quietly differed by role, and the reader with fewer permissions would never know a question had gone
 * unasked.
 *
 * **Findings are ordered by severity and then by code.** By severity because that is what a reader needs first;
 * by code as the tie-break because a stable order is what lets somebody compare this week's answer with last
 * week's.
 */
final readonly class Analyse
{
    /**
     * @param  iterable<Analyser>  $analysers
     */
    public function __construct(
        private iterable $analysers,
        private AnalysisAssembler $assembler,
        private Narrator $narrator,
        private FigureScanner $figures,
        private AuthorizationGuard $guard,
    ) {}

    public function __invoke(string $clientId, string $clientName, DateRange $period): AnalysisView
    {
        $this->guard->ensure(AssistantMode::Analyse->requires());

        $context = $this->assembler->assemble($clientId, $period);

        $findings = [];
        $skipped = [];

        foreach ($this->analysers as $analyser) {
            if (! $this->guard->allows($analyser->requires())) {
                /* Named, not dropped. See the note at the top of this class. */
                $skipped[] = $analyser->code();

                continue;
            }

            foreach ($analyser->run($context) as $finding) {
                /*
                 * A finding with no evidence is not a finding. Guarded here as well as in the domain so that no
                 * analyser can put one in front of a reader by forgetting.
                 */
                if ($finding->isSupported()) {
                    $findings[] = $finding;
                }
            }
        }

        usort(
            $findings,
            static fn (Finding $a, Finding $b): int => $b->severity->weight() <=> $a->severity->weight()
                ?: strcmp($a->code, $b->code),
        );

        $brief = NarrativeBrief::of(
            AssistantMode::Analyse,
            $context->periodInWords(),
            $findings,
            $period->lengthInDays(),
            $this->figures,
        );

        return new AnalysisView(
            mode: AssistantMode::Analyse,
            clientName: $clientName,
            period: $period,
            before: $context->before,
            findings: $findings,
            narrative: $this->narrator->narrate($brief),
            skipped: $skipped,
        );
    }
}
