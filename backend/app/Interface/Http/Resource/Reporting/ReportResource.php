<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Reporting;

use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\MetricValue;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Application\Reporting\Command\ShareReport;
use App\Application\Reporting\Contract\SnapshotSerialiser;
use App\Application\Reporting\DTO\RenderedReport;
use App\Application\Reporting\DTO\RenderedSection;
use App\Application\Reporting\DTO\ReportSummary;
use App\Application\Reporting\DTO\SavedReport;
use App\Application\Reporting\DTO\SectionDefinition;
use App\Application\Reporting\DTO\ShareView;
use App\Application\Reporting\DTO\StoreFigure;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use App\Domain\Reporting\SectionKind;
use App\Interface\Http\Resource\Analytics\MetricValueResource;
use DateTimeImmutable;

/**
 * Formatting a report for a reader — and, through `SnapshotSerialiser`, for storage.
 *
 * **One implementation for both.** A snapshot stores exactly what a live render returns, because the alternative is
 * a frozen report and a live one rendering differently in the same interface — the one difference this module cannot
 * afford. So this class implements the serialiser port, and `TakeSnapshot` writes what a reader would have seen.
 */
final readonly class ReportResource implements SnapshotSerialiser
{
    /** @return array<string, mixed> */
    public function toArray(RenderedReport $report): array
    {
        return self::rendered($report);
    }

    /** @return array<string, mixed> */
    public static function vocabulary(): array
    {
        return [
            'windows' => array_map(static fn (ReportWindow $window): array => [
                'window' => $window->value,
                'label' => $window->label(),
                'needs_dates' => $window->needsDates(),
                /* Month-to-date. Every surface showing it says the period is still running. */
                'is_incomplete' => $window->isIncomplete(),
            ], ReportWindow::cases()),
            'comparisons' => array_map(static fn (ComparisonMode $mode): array => [
                'comparison' => $mode->value,
                'label' => $mode->label(),
                /* What the comparison cannot account for. Stated per mode: the two fail differently. */
                'caveat' => $mode->caveat(),
            ], ComparisonMode::cases()),
            'audiences' => array_map(static fn (ReportAudience $audience): array => [
                'audience' => $audience->value,
                'label' => $audience->label(),
                'allows_internal_notes' => $audience->allowsInternalNotes(),
            ], ReportAudience::cases()),
            'sections' => array_map(static fn (SectionKind $kind): array => [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'needs_dimension' => $kind->needsDimension(),
                'supports_comparison' => $kind->supportsComparison(),
                'metric_cap' => $kind->metricCap(),
                /* False for a store section, which names a revenue source instead of choosing metrics. */
                'uses_metrics' => $kind->usesMetrics(),
                /*
                 * Which metrics this shape will accept, so a builder cannot offer reach in a ranking and then be
                 * refused. The rule is the domain's; this publishes it.
                 */
                'metrics' => array_values(array_map(
                    static fn (Metric $metric): string => $metric->value,
                    array_filter(Metric::cases(), static fn (Metric $metric): bool => $kind->accepts($metric)),
                )),
            ], SectionKind::cases()),
            'metrics' => array_map(static fn (Metric $metric): array => [
                'metric' => $metric->value,
                'label' => $metric->label(),
                'format' => $metric->format()->value,
                'is_monetary' => $metric->isMonetary(),
                'lower_is_better' => $metric->lowerIsBetter(),
                /* False for reach and frequency, which is why they cannot appear in a ranking. */
                'is_additive' => $metric->isAdditiveAcrossEntities(),
            ], Metric::cases()),
            'dimensions' => array_map(static fn (Dimension $dimension): array => [
                'dimension' => $dimension->value,
                'label' => $dimension->label(),
            ], Dimension::cases()),
            /*
             * Who may be named as the claimant of a store's revenue. `is_modelled` is published so a builder can keep
             * an attributed figure out of a report written for a client — the server refuses it either way.
             */
            'revenue_sources' => array_map(static fn (RevenueSource $source): array => [
                'source' => $source->value,
                'label' => $source->label(),
                'is_invoiced' => $source->isInvoiced(),
                'is_modelled' => $source->isModelled(),
            ], RevenueSource::cases()),
            'max_share_days' => ShareReport::MAX_DAYS,
        ];
    }

    /** @return array<string, mixed> */
    public static function rendered(RenderedReport $report): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'description' => $report->description,
            'client_name' => $report->clientName,
            'period' => [
                'from' => $report->period->start->format('Y-m-d'),
                'until' => $report->period->end->format('Y-m-d'),
                'days' => $report->period->lengthInDays(),
                /* Said out loud: a report over a running period is the one most likely to be compared wrongly. */
                'is_incomplete' => $report->isPeriodIncomplete,
            ],
            'comparison' => [
                'mode' => $report->comparison->value,
                'label' => $report->comparison->label(),
                'from' => $report->comparisonPeriod?->start->format('Y-m-d'),
                'until' => $report->comparisonPeriod?->end->format('Y-m-d'),
                /*
                 * Whether the change figures may be read at all, and the sentence to show in their place when they
                 * may not. Not a boolean — the reason is the useful part.
                 */
                'was_requested' => $report->comparability->wasRequested(),
                'is_comparable' => $report->comparability->isComparable,
                'reason' => $report->comparability->reason,
                'code' => $report->comparability->code,
            ],
            'audience' => $report->audience->value,
            'mode' => $report->mode->value,
            'mode_label' => $report->mode->label(),
            'source' => $report->source->value,
            'source_label' => $report->source->label(),
            /* Whether the interface must visibly mark the whole document. */
            'requires_label' => $report->requiresLabel(),
            'is_empty' => $report->isEmpty(),
            /* Null in a client-safe render, because it was never read. */
            'internal_note' => $report->internalNote,
            'rendered_at' => $report->renderedAt->format(DateTimeImmutable::ATOM),
            'sections' => array_map(
                static fn (RenderedSection $section): array => self::section($section),
                $report->sections,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(ReportSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'name' => $summary->name,
            'description' => $summary->description,
            'window' => $summary->window->value,
            'window_label' => $summary->window->label(),
            'audience' => $summary->audience->value,
            'client_name' => $summary->clientName,
            'section_count' => $summary->sectionCount,
            'snapshot_count' => $summary->snapshotCount,
            /* The figure somebody checks before saying "nobody outside can see this". */
            'active_share_count' => $summary->activeShareCount,
            'updated_at' => $summary->updatedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function definition(SavedReport $report): array
    {
        $definition = $report->definition;

        return [
            'id' => $report->id,
            'name' => $definition->name,
            'description' => $definition->description,
            'client_id' => $definition->clientId,
            'client_name' => $report->clientName,
            'window' => $definition->window->value,
            'custom_from' => $definition->customFrom?->format('Y-m-d'),
            'custom_until' => $definition->customUntil?->format('Y-m-d'),
            'comparison' => $definition->comparison->value,
            'audience' => $definition->audience->value,
            'internal_note' => $definition->internalNote,
            'filters' => [
                'providers' => $definition->filters->providers,
                'campaign_ids' => $definition->filters->campaignIds,
                'project_ids' => $definition->filters->projectIds,
                'client_id' => $definition->filters->clientId,
            ],
            'sections' => array_map(static fn (SectionDefinition $section): array => [
                'kind' => $section->kind->value,
                'title' => $section->title,
                'metrics' => array_map(static fn (Metric $metric): string => $metric->value, $section->metrics),
                'dimension' => $section->dimension?->value,
                'row_limit' => $section->rowLimit,
                'revenue_source' => $section->revenueSource?->value,
            ], $definition->sections),
            'created_by_name' => $report->createdByName,
            'updated_at' => $report->updatedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function share(ShareView $share, DateTimeImmutable $now): array
    {
        $state = $share->state($now);

        return [
            'id' => $share->id,
            /* The hint, never the token. The token was returned once, when the link was created. */
            'hint' => $share->hint,
            'state' => $state->value,
            'state_label' => $state->label(),
            /* Whether the link shows frozen figures or today's. A recipient is always told which. */
            'is_frozen' => $share->isFrozen(),
            'expires_at' => $share->expiresAt->format(DateTimeImmutable::ATOM),
            'revoked_at' => $share->revokedAt?->format(DateTimeImmutable::ATOM),
            'revoked_reason' => $share->revokedReason,
            'recipient_label' => $share->recipientLabel,
            'view_count' => $share->viewCount,
            'last_viewed_at' => $share->lastViewedAt?->format(DateTimeImmutable::ATOM),
            'created_by_name' => $share->createdByName,
            'created_at' => $share->createdAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function section(RenderedSection $section): array
    {
        return [
            'kind' => $section->kind->value,
            'title' => $section->title,
            'metrics' => array_map(static fn (Metric $metric): string => $metric->value, $section->metrics),
            'dimension' => $section->dimension?->value,
            'dimension_label' => $section->dimension?->label(),
            'source' => $section->source->value,
            'source_label' => $section->source->label(),
            /*
             * Per section, not per report. A trend from synced rows beside a ranking from sandbox fixtures is two
             * claims, and one badge over both would present the weaker as the stronger.
             */
            'requires_label' => $section->requiresLabel(),
            /* True when there is nothing to draw, which is different from drawing zeroes. */
            'is_empty' => $section->isEmpty,
            'caveats' => $section->caveats,
            'currency' => $section->currency,
            /*
             * Who is claiming the revenue, and the figures as the store read model published them. Absent for every
             * kind but a store section.
             */
            'revenue_source' => $section->store?->revenueSource->value,
            'revenue_source_label' => $section->store?->revenueSource->label(),
            'store' => $section->store === null ? null : array_map(static fn (StoreFigure $figure): array => [
                'key' => $figure->key,
                'label' => $figure->label,
                'kind' => $figure->kind,
                'value' => $figure->value,
                'currency' => $figure->currency,
            ], $section->store->figures()),
            'totals' => $section->totals === null ? null : self::metricSet($section->totals),
            'series' => array_map(static fn (SeriesPoint $point): array => [
                'date' => $point->date->format('Y-m-d'),
                'metrics' => self::metricSet($point->metrics),
            ], $section->series),
            'rows' => array_map(static fn (BreakdownRow $row): array => [
                'key' => $row->key,
                'label' => $row->label,
                'status' => $row->status,
                'metrics' => self::metricSet($row->metrics),
            ], $section->rows),
        ];
    }

    /** @return array<string, mixed> */
    private static function metricSet(MetricSet $set): array
    {
        $values = [];

        foreach ($set->values as $key => $value) {
            $values[$key] = self::metricValue($value);
        }

        return [
            'values' => $values,
            'source' => $set->source->value,
            'currency' => $set->currency,
            /* Zero means genuinely no data, which is not the same as a total of zero. */
            'row_count' => $set->rowCount,
            'is_empty' => $set->isEmpty(),
        ];
    }

    /**
     * One figure, in the shape the analytics surfaces already publish.
     *
     * Delegated to `MetricValueResource` rather than written again. The front end has one formatter for a metric —
     * which knows that currency arrives in minor units, that a percentage arrives as 2.35 rather than 0.0235, and
     * that a null is a missing figure rather than a zero — and a report emitting a near-identical shape would mean
     * either a second formatter or a subtle disagreement with the first.
     *
     * @return array<string, mixed>
     */
    private static function metricValue(MetricValue $value): array
    {
        return MetricValueResource::toArray($value);
    }
}
