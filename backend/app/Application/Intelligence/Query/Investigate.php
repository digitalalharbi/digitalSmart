<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Query;

use App\Application\Intelligence\DTO\AnalysisView;
use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Service\AnalysisAssembler;
use App\Application\Intelligence\Service\FigureScanner;
use App\Application\Intelligence\Service\Narrator;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Analytics\Metric;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * One finding, taken apart.
 *
 * It adds the **same figures broken down by platform**, which is nearly always the question a reader asks next:
 * a rate that moved because the mix moved is not a rate that moved, and a total cannot tell the two apart. The
 * per-platform rows are added as evidence rather than as prose, so the narrative may state them and the guard can
 * check every one.
 *
 * It refuses a code it did not produce. A reader who follows a stale link gets "that finding is not in this
 * period's analysis" rather than an empty page that reads as an all-clear — the finding may well have been real
 * last week, and its absence now is information.
 */
final readonly class Investigate
{
    public function __construct(
        private Analyse $analyse,
        private AnalysisAssembler $assembler,
        private Narrator $narrator,
        private FigureScanner $figures,
        private AuthorizationGuard $guard,
    ) {}

    public function __invoke(
        string $clientId,
        string $clientName,
        DateRange $period,
        string $code,
    ): AnalysisView {
        $this->guard->ensure(AssistantMode::Investigate->requires());

        $analysis = ($this->analyse)($clientId, $clientName, $period);

        $finding = null;

        foreach ($analysis->findings as $candidate) {
            if ($candidate->code === $code) {
                $finding = $candidate;
            }
        }

        if ($finding === null) {
            throw ResourceNotFound::of('Finding');
        }

        $context = $this->assembler->assemble($clientId, $period);
        $deeper = new Finding(
            code: $finding->code,
            severity: $finding->severity,
            subject: $finding->subject,
            headline: $finding->headline,
            detail: $finding->detail,
            evidence: [...$finding->evidence, ...$this->byPlatform($finding, $context->byProvider, $context->periodInWords())],
            confidence: $finding->confidence,
            recommendation: $finding->recommendation,
        );

        $brief = NarrativeBrief::of(
            AssistantMode::Investigate,
            $context->periodInWords(),
            [$deeper],
            $period->lengthInDays(),
            $this->figures,
        );

        return new AnalysisView(
            mode: AssistantMode::Investigate,
            clientName: $clientName,
            period: $period,
            before: $context->before,
            findings: [$deeper],
            narrative: $this->narrator->narrate($brief),
            skipped: $analysis->skipped,
        );
    }

    /**
     * The finding's own metrics, per platform.
     *
     * Only the metrics the finding already rests on. Adding every metric for every platform would bury the thing
     * being investigated in a table nobody asked for, and the narrative guard would then permit any number in it.
     *
     * @param  list<\App\Application\Analytics\DTO\BreakdownRow>  $rows
     * @return list<Evidence>
     */
    private function byPlatform(Finding $finding, array $rows, string $period): array
    {
        $metrics = [];

        foreach ($finding->evidence as $evidence) {
            $metric = Metric::tryFrom($evidence->metric);

            if ($metric !== null) {
                $metrics[$metric->value] = $metric;
            }
        }

        $evidence = [];

        foreach ($rows as $row) {
            foreach ($metrics as $metric) {
                $value = $row->metrics->get($metric);

                if ($value === null) {
                    continue;
                }

                $evidence[] = new Evidence(
                    metric: $metric->value,
                    label: sprintf('%s — %s', $row->label, $metric->label()),
                    value: $value->value === null ? null : (float) $value->value,
                    comparedWith: null,
                    period: $period,
                    source: $value->source,
                    readFrom: 'analytics by platform',
                    reason: $value->unavailableReason,
                );
            }
        }

        return $evidence;
    }
}
