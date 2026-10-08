<?php

declare(strict_types=1);

namespace App\Application\Intelligence\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;
use App\Domain\Shared\ValueObject\DateRange;

/** What the assistant answered, with the findings, the prose, and an account of what it could not look at. */
final readonly class AnalysisView
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $skipped  analyser codes the reader's permissions kept out of this answer
     */
    public function __construct(
        public AssistantMode $mode,
        public string $clientName,
        public DateRange $period,
        public DateRange $before,
        public array $findings,
        public Narrative $narrative,
        public array $skipped,
        /** The question asked, for `Ask`. */
        public ?string $question = null,
        /** What the question was taken to mean. Null when no intent matched. */
        public ?string $intent = null,
    ) {}

    /** The count a reader reads first. */
    public function needsAttention(): int
    {
        return count(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->severity->needsAttention(),
        ));
    }

    /**
     * How many questions could not be answered from this period's data.
     *
     * Published as its own figure. A list where some questions went unanswered reads, to somebody skimming,
     * exactly like a list where they were answered favourably.
     */
    public function unanswered(): int
    {
        return count(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->severity === FindingSeverity::Insufficient,
        ));
    }

    /**
     * The weakest source behind anything in this answer.
     *
     * The whole analysis is labelled with it, for the same reason a report is: an answer built from one sandbox
     * figure is a sandbox answer, whatever the other four were.
     */
    public function source(): MetricSource
    {
        if ($this->findings === []) {
            return MetricSource::Live;
        }

        return MetricSource::weakest(array_map(
            static fn (Finding $finding): MetricSource => $finding->source(),
            $this->findings,
        ));
    }
}
