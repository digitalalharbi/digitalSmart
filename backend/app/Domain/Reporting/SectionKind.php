<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Analytics\Metric;

/**
 * What one block of a report is.
 *
 * Four kinds, and the restraint is the design. A report builder that offers every chart type produces reports
 * whose author chose a shape before they had a question — and this product's charting rules already say a chart
 * must answer one. So: a row of figures, a line over time, a ranking, or a table.
 *
 * There is no pie, no gauge and no dual axis, for the reasons `docs/DATA_VISUALISATION.md` gives. A reader asking
 * for one is asking for a ranking or a share, and a bar answers both better.
 */
enum SectionKind: string
{
    /** A row of headline figures, optionally against a comparison period. */
    case Scorecard = 'scorecard';

    /** One line per metric over the period. */
    case Trend = 'trend';

    /** A ranking by one dimension — the top campaigns, the providers, the days. */
    case Breakdown = 'breakdown';

    /** The same rows as a breakdown, as a table, with every metric visible at once. */
    case Table = 'table';

    /**
     * What a client's stores sold, according to **one named authority**.
     *
     * Its own kind, not a metric: the figures come from orders rather than from ad facts, and a store's revenue
     * dropped into a scorecard beside the platform's own would be two claims under one word. The section names who is
     * making the claim, and a report that wants a second authority adds a second section.
     */
    case Store = 'store';

    /**
     * How a client's recorded leads turned out: counts by stage, route and campaign, and how fast they were answered.
     *
     * Aggregates only. A lead is a person, so the section carries no name, contact point, note or message — nothing a
     * reader could use to find one. It reads the leads table, not the ad platforms' own `leads` metric, and says so:
     * "leads the platform reported" and "leads this client recorded" are different claims.
     */
    case Lead = 'lead';

    /**
     * How the creatives behind a client's advertising performed: the pictures, the figures and the plain verdicts.
     *
     * Reads the same creative layer as the Creatives page, scoped by the report's own platform and campaign filters, so
     * unlike a store or lead section it follows a visitor's narrowing. It carries a fixed set of figures and names its
     * view (`CreativeView`) rather than taking a metric list.
     */
    case Creative = 'creative';

    /**
     * How people moved from seeing an ad to acting on it: impressions, clicks, link clicks and results, with the rate
     * between each pair.
     *
     * Read from the same ad facts as a scorecard and narrowed by the same platform and campaign filters, but fixed in
     * shape: a funnel is an ordered set of stages, and an author who could reorder or drop one could draw a funnel that
     * narrows and then widens. A stage a platform does not report is a gap, never a zero.
     */
    case Traffic = 'traffic';

    /**
     * Which campaigns a client's recorded orders arrived from, by what the order itself carried.
     *
     * Internal only: attribution is the one thing a client's portal deliberately never shows (`WithheldModule`), so a
     * report written for a client cannot hold it. It names a revenue authority like a store section does, and is read
     * for one client.
     */
    case Attribution = 'attribution';

    /**
     * A view of the client's first-party tracking — link performance, form performance, the funnel, lead follow-up or
     * tracking health.
     *
     * A fixed table per view (`TrackingView`), read for the whole client and narrowed by nothing a visitor chooses. It holds
     * counts and states only: no link address, no token, no lead, no answer, no event payload — which is what makes it
     * safe to send a client.
     */
    case Tracking = 'tracking';

    public function needsDimension(): bool
    {
        return $this === self::Breakdown || $this === self::Table;
    }

    /** Whether the section carries ad metrics at all. A store section carries a revenue authority instead. */
    public function usesMetrics(): bool
    {
        return ! in_array($this, [self::Store, self::Lead, self::Creative, self::Traffic, self::Attribution, self::Tracking], true);
    }

    /** Whether the section names a revenue authority (`revenue_source`) instead of metrics. */
    public function namesRevenueSource(): bool
    {
        return $this === self::Store || $this === self::Attribution;
    }

    /** Whether the section reads one client's own records, so a report with it must name a client. */
    public function needsClient(): bool
    {
        return in_array($this, [self::Store, self::Lead, self::Attribution, self::Tracking], true);
    }

    /** Whether a report written for a client may carry this section. Attribution is the one it may not. */
    public function isClientSafe(): bool
    {
        return $this !== self::Attribution;
    }

    /**
     * Whether a comparison period means anything for this shape.
     *
     * Only a scorecard. A trend already shows change over time, and putting a second period's line on it is the
     * chart `docs/DATA_VISUALISATION.md` refuses — two periods on one x-axis have to pretend that day 3 of one is
     * day 3 of the other. A breakdown compared against another period is a table of twelve numbers nobody reads.
     */
    public function supportsComparison(): bool
    {
        return $this === self::Scorecard;
    }

    /**
     * How many metrics this shape can carry before it stops being readable.
     *
     * A trend is capped at four because that is the measured limit for a line chart where series cross; a table
     * can carry more because position, not colour, is doing the work.
     */
    public function metricCap(): int
    {
        return match ($this) {
            self::Scorecard => 6,
            self::Trend => 4,
            self::Breakdown => 3,
            self::Table => 8,
            self::Store, self::Lead, self::Creative, self::Traffic, self::Attribution, self::Tracking => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Scorecard => 'Headline figures',
            self::Trend => 'Over time',
            self::Breakdown => 'A ranking',
            self::Table => 'A table',
            self::Store => 'Store figures',
            self::Lead => 'Lead figures',
            self::Creative => 'Creatives',
            self::Traffic => 'Clicks and funnel',
            self::Attribution => 'Attributed revenue',
            self::Tracking => 'Tracking',
        };
    }

    /**
     * Whether a metric may appear in this shape at all.
     *
     * The one refusal: a non-additive metric in a breakdown. Reach counts deduplicated people, so a "reach by
     * campaign" ranking invites a reader to add the rows — and the sum double-counts anyone who saw two campaigns.
     * The read model already returns null for the total; this stops the column existing in the first place.
     */
    public function accepts(Metric $metric): bool
    {
        if (! $this->usesMetrics()) {
            return false;
        }

        if ($this === self::Scorecard || $this === self::Trend) {
            return true;
        }

        return $metric->isAdditiveAcrossEntities();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
