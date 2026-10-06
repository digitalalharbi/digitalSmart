<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

use App\Domain\Advertising\MetricSource;

/**
 * What a provider said about the completeness of its own answer.
 *
 * Google Analytics is unusually honest here, and this product has to be at least as honest.
 * A `runReport` response can tell us four separate things about itself, and each one means a
 * figure read from it is not the whole truth:
 *
 *  - **It was sampled.** `samplingMetadatas` reports `samplesReadCount` out of
 *    `samplingSpaceSize` — the subset of events actually analysed.
 *  - **It was thresholded.** `subjectToThresholding` means rows with few users were withheld
 *    for privacy, so a count is a floor rather than a total.
 *  - **It was truncated.** `dataTruncationReasons` means rows were dropped outright.
 *  - **High-cardinality values were rolled into `(other)`.** `dataLossFromOtherRow` means some
 *    event names are no longer individually visible.
 *
 * Every one of those produces a number that *looks* exact. A tool that reads the rows and
 * discards these flags reports 412 leads with the same confidence whether it saw all of them
 * or 4% of them — and the client is told 412 either way. So the flags travel with the figures
 * and decide their source label.
 *
 * `emptyReason` is kept separate and deliberately not treated as a quality problem: a report
 * that is legitimately empty is a fact about the client's traffic, not a defect in the read.
 */
final readonly class ReportQuality
{
    private function __construct(
        public bool $isSampled,
        /** Fraction of available events analysed, where the provider said. Null when unsampled. */
        public ?float $samplingFraction,
        public bool $isThresholded,
        public bool $isTruncated,
        public bool $hasOtherRow,
        /** The provider's own words for why a report came back empty, if it said. */
        public ?string $emptyReason = null,
    ) {}

    public static function complete(): self
    {
        return new self(false, null, false, false, false);
    }

    public static function of(
        ?float $samplingFraction = null,
        bool $isThresholded = false,
        bool $isTruncated = false,
        bool $hasOtherRow = false,
        ?string $emptyReason = null,
    ): self {
        return new self(
            /*
             * Sampling is established by the provider *reporting* it, not by the fraction being
             * below one. A fraction of exactly 1.0 inside a sampling block still means the
             * response went down a sampled path, and rounding it away would be us deciding the
             * data is complete on the provider's behalf.
             */
            isSampled: $samplingFraction !== null,
            samplingFraction: $samplingFraction,
            isThresholded: $isThresholded,
            isTruncated: $isTruncated,
            hasOtherRow: $hasOtherRow,
            emptyReason: $emptyReason,
        );
    }

    /** Whether anything about this response makes its figures less than whole. */
    public function isPartial(): bool
    {
        return $this->isSampled || $this->isThresholded || $this->isTruncated || $this->hasOtherRow;
    }

    /**
     * The honesty label a figure read from this response must carry.
     *
     * Sandbox wins outright: fixtures are never shown as production, whatever they contain.
     * Otherwise an incomplete answer is `PARTIAL` rather than `SYNCED`, because the whole
     * point of the distinction is that somebody reading a total should be able to tell whether
     * it is one.
     */
    public function metricSource(bool $isSandbox): MetricSource
    {
        if ($isSandbox) {
            return MetricSource::Sandbox;
        }

        return $this->isPartial() ? MetricSource::Partial : MetricSource::Synced;
    }

    /**
     * Why these figures are not whole, in a sentence a person can act on.
     *
     * Returned as a list because a response can be sampled *and* thresholded, and collapsing
     * two causes into one message would hide whichever is cheaper to fix.
     *
     * @return list<string>
     */
    public function caveats(): array
    {
        $caveats = [];

        if ($this->isSampled) {
            $caveats[] = $this->samplingFraction === null
                ? 'Google Analytics sampled this report, so the figures are an estimate from part of the data.'
                : sprintf(
                    'Google Analytics analysed %s%% of the available events, so the figures are an estimate.',
                    $this->formatFraction($this->samplingFraction),
                );
        }

        if ($this->isThresholded) {
            $caveats[] = 'Rows with very few users were withheld for privacy, so counts are a floor, not a total.';
        }

        if ($this->isTruncated) {
            $caveats[] = 'The provider dropped rows from this report, so some events are missing entirely.';
        }

        if ($this->hasOtherRow) {
            $caveats[] = 'Less common event names were grouped into "(other)" and cannot be counted separately.';
        }

        return $caveats;
    }

    private function formatFraction(float $fraction): string
    {
        $percentage = $fraction * 100;

        /*
         * Two decimals below 1%, because "0%" for a 0.4% sample reads as "no data" when it
         * actually means the figure is a thousandfold extrapolation — the single most
         * misleading rounding this product could do.
         */
        return $percentage < 1 ? number_format($percentage, 2) : number_format($percentage, 1);
    }
}
