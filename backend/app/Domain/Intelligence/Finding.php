<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Advertising\MetricSource;

/**
 * One thing the analysers can say, and everything behind it.
 *
 * A finding is a **statement with its working shown**. `headline` is the sentence; `evidence` is every number in
 * it; `confidence` is derived from that evidence rather than asserted; and `code` is stable, so a reader can be
 * shown the same finding next month and recognise it as the same one.
 *
 * `recommendation` is null more often than not, and deliberately. A finding that says what happened is useful; a
 * recommendation that says what to do about it needs evidence firm enough to act on, and most findings are not.
 */
final readonly class Finding
{
    /**
     * @param  list<Evidence>  $evidence
     */
    public function __construct(
        /** Stable across runs, so the same problem is recognisably the same problem. */
        public string $code,
        public FindingSeverity $severity,
        /** What this is about — a provider, a campaign, the account as a whole. */
        public string $subject,
        /** One sentence, with no number in it that is not in the evidence. */
        public string $headline,
        /** Why it matters, in the agency's own terms. */
        public string $detail,
        public array $evidence,
        public Confidence $confidence,
        /**
         * What to consider doing. Null unless the evidence is firm enough to act on.
         *
         * Never an instruction and never carried out: this product does not write to an advertising platform, so
         * every recommendation is a sentence for a person to weigh — the same argument the approvals engine makes
         * about why an approval is not an execution.
         */
        public ?string $recommendation = null,
    ) {}

    /**
     * The weakest source behind this finding.
     *
     * The weakest rather than the most common, for the same reason every other surface in this product rolls up
     * that way: a finding built from one sandbox figure and four live ones is a sandbox finding.
     */
    public function source(): MetricSource
    {
        return MetricSource::weakest(array_map(
            static fn (Evidence $evidence): MetricSource => $evidence->source,
            $this->evidence,
        ));
    }

    /**
     * Every number this finding is allowed to state.
     *
     * @return list<float>
     */
    public function figures(): array
    {
        $figures = [];

        foreach ($this->evidence as $evidence) {
            foreach ($evidence->figures() as $figure) {
                $figures[] = $figure;
            }
        }

        return $figures;
    }

    /** A finding with no evidence is not a finding. Guarded here so no analyser can produce one. */
    public function isSupported(): bool
    {
        return $this->evidence !== [];
    }
}
