<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Advertising\MetricSource;

/**
 * One number a finding rests on, and where it came from.
 *
 * Every figure the assistant states exists as one of these. That is what makes the output checkable: a reader can
 * ask "where did 4.2 come from" and get a metric, a period, a source state and the surface that produced it,
 * rather than a sentence somebody has to trust.
 *
 * It is also the whole input the language model is given. The model sees these and writes prose about them; the
 * narrative is then **rejected** if it contains a number that is not here. A model cannot invent a figure it was
 * never allowed to produce.
 */
final readonly class Evidence
{
    public function __construct(
        /** The metric's value, e.g. `cpc`. Not a label: the label is for reading, this is for matching. */
        public string $metric,
        /** What a person calls it. */
        public string $label,
        /**
         * The figure, already in the unit it will be read in.
         *
         * Null is legitimate and is not zero: a ratio with no denominator has no value, and `reason` says why.
         */
        public ?float $value,
        /** The figure it is being compared with, when the finding is about a change. */
        public ?float $comparedWith,
        /** Which period this covers, as words a reader recognises. */
        public string $period,
        /** The source state of the figure. A finding from sandbox rows is a sandbox finding. */
        public MetricSource $source,
        /** Which surface produced it, so a reader can go and look. */
        public string $readFrom,
        /** Why the value is null, when it is. */
        public ?string $reason = null,
    ) {}

    /**
     * Every number in this piece of evidence, as it would be written.
     *
     * Used by the narrative guard. Formatting is deliberately **not** applied here: the guard compares numeric
     * values rather than strings, because "4.2" and "4.20" are the same figure and a string comparison would
     * reject the model for rounding.
     *
     * @return list<float>
     */
    public function figures(): array
    {
        $figures = [];

        if ($this->value !== null) {
            $figures[] = $this->value;
        }

        if ($this->comparedWith !== null) {
            $figures[] = $this->comparedWith;
        }

        return $figures;
    }
}
