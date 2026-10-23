<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exception;

use App\Domain\Analytics\Metric;
use App\Domain\Reporting\SectionKind;
use RuntimeException;

/** A report somebody could not read, or could not trust. */
final class InvalidReportDefinition extends RuntimeException
{
    /** @param list<string> $allowed */
    private function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly array $allowed = [],
    ) {
        parent::__construct($message);
    }

    public static function nameRequired(): self
    {
        return new self(
            'A report needs a name. It is what somebody will look for in a month.',
            'name_required',
        );
    }

    /**
     * A report with no sections.
     *
     * Refused rather than saved empty. A saved report that renders nothing is one somebody opens, assumes is
     * broken, and rebuilds — and the rebuilt one is the second report with the same name.
     */
    public static function sectionsRequired(): self
    {
        return new self(
            'A report needs at least one section. An empty one reads as a broken one.',
            'sections_required',
        );
    }

    public static function metricsRequired(SectionKind $kind): self
    {
        return new self(
            sprintf('A %s needs at least one metric.', mb_strtolower($kind->label())),
            'metrics_required',
        );
    }

    /** More metrics than the shape can carry without becoming unreadable. */
    public static function tooManyMetrics(SectionKind $kind): self
    {
        return new self(
            sprintf(
                '%s carries at most %d metrics. Past that the shape stops being readable — split it into two '
                .'sections instead.',
                $kind->label(),
                $kind->metricCap(),
            ),
            'too_many_metrics',
        );
    }

    /**
     * A metric that cannot honestly appear in this shape.
     *
     * Today that means reach or frequency in a ranking: both count deduplicated people, so a reader adding the rows
     * double-counts anyone who appears in two of them. The read model already refuses the total; this refuses the
     * column, which is where the invitation to add was.
     */
    public static function metricNotAllowedHere(SectionKind $kind, Metric $metric): self
    {
        return new self(
            sprintf(
                '%s cannot appear in %s. It counts deduplicated people, so the rows do not add up to anything — '
                .'and a column of them invites exactly that.',
                $metric->label(),
                mb_strtolower($kind->label()),
            ),
            'metric_not_allowed_here',
            [$metric->value],
        );
    }

    public static function dimensionRequired(SectionKind $kind): self
    {
        return new self(
            sprintf('%s needs a dimension to group by.', $kind->label()),
            'dimension_required',
        );
    }

    /** A creative section that does not say which part of the creative picture it shows. */
    public static function creativeViewRequired(): self
    {
        return new self(
            'A creative section needs to say what it shows: the top creatives, their performance, the format mix, a '
            .'comparison or the ones to refresh.',
            'creative_view_required',
        );
    }

    /** A tracking section that does not say which view of the tracking it shows. */
    public static function trackingViewRequired(): self
    {
        return new self(
            'A tracking section needs to say what it shows: link performance, form performance, the funnel, lead follow-up or tracking health.',
            'tracking_view_required',
        );
    }

    /** A custom window with no dates. */
    public static function datesRequired(): self
    {
        return new self(
            'A report over a specific period needs both of its dates.',
            'dates_required',
        );
    }

    /** A store section that does not say who is claiming the revenue. */
    public static function revenueSourceRequired(): self
    {
        return new self(
            'Store figures need a named source. A Salla total counts invoices and a GA4 total counts what a browser '
            .'reported, so there is no unnamed total to show.',
            'revenue_source_required',
        );
    }

    /** Store figures belong to a client's stores; an agency-wide report has no single store to read. */
    public static function storeNeedsClient(): self
    {
        return new self(
            'Store figures are read for one client. Choose the client this report is about.',
            'store_needs_client',
        );
    }

    /**
     * A modelled figure in a report a client will read.
     *
     * Attributed revenue is a model's output and needs somebody who can answer a question about it; it is withheld
     * from the client portal for the same reason, and a shared link is the same disclosure by another door.
     */
    public static function modelledNotClientSafe(): self
    {
        return new self(
            'Attributed revenue is a model\'s estimate and cannot go in a report written for a client. Use the '
            .'store\'s own figures, or keep this report internal.',
            'modelled_not_client_safe',
        );
    }

    /** Attributed revenue is read from one client's orders, and is never part of a client's own report. */
    public static function attributionNeedsClient(): self
    {
        return new self(
            'Attributed revenue is read for one client. Choose the client this report is about.',
            'attribution_needs_client',
        );
    }

    public static function attributionNotClientSafe(): self
    {
        return new self(
            'Attributed revenue is internal: a client\'s portal never shows it, so a report written for a client cannot '
            .'hold it. Keep this report internal, or take the section out.',
            'attribution_not_client_safe',
        );
    }

    public static function attributionNeedsSource(): self
    {
        return new self(
            'Attributed revenue names whose orders it counts. Choose the revenue source.',
            'attribution_needs_source',
        );
    }

    /** Lead figures belong to one client's enquiries; an agency-wide report has no single client to read them for. */
    public static function leadNeedsClient(): self
    {
        return new self(
            'Lead figures are read for one client. Choose the client this report is about.',
            'lead_needs_client',
        );
    }
}
