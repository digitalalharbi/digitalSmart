<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Portal;

use App\Application\Analytics\DTO\SeriesPoint;
use App\Application\Portal\DTO\PortalEntry;
use App\Application\Portal\DTO\PortalPerformance;
use App\Domain\Portal\AlwaysAvailable;
use App\Domain\Portal\PortalModule;
use App\Interface\Http\Resource\Analytics\MetricValueResource;

/**
 * The portal, as its reader sees it.
 *
 * Shaped separately from `PortalResource`, which is the agency's own view of the same configuration. Two resources
 * rather than one with a flag, because they are answers to different questions: the agency's carries who exposed
 * what and the suspension reason, and a client's must be incapable of carrying either. A single resource with a
 * conditional would be one careless edit from the condition being wrong in the dangerous direction.
 */
final class ClientPortalResource
{
    /** @return array<string, mixed> */
    public static function entry(PortalEntry $entry): array
    {
        return [
            'client_id' => $entry->clientId,
            'client_name' => $entry->clientName,
            'state' => $entry->state->value,
            /*
             * The sentence a reader gets instead of a surface. Null when the portal is open. It never names the
             * reason the agency recorded: that is a commercial conversation, and a portal page is the worst place
             * to have it.
             */
            'message' => $entry->state->visitorMessage(),
            'modules' => array_map(
                static fn (PortalModule $module): array => [
                    'module' => $module->value,
                    'label' => $module->label(),
                ],
                $entry->modules,
            ),
            /*
             * Always present, whatever the configuration says, and labelled as such. A client writes half of this
             * thread — see `AlwaysAvailable`.
             */
            'always_available' => array_map(
                static fn (AlwaysAvailable $module): array => [
                    'module' => $module->value,
                    'label' => $module->label(),
                ],
                AlwaysAvailable::cases(),
            ),
            /* So an agency member reading a preview cannot mistake it for their own view of the product. */
            'is_preview' => $entry->isPreview,
        ];
    }

    /** @return array<string, mixed> */
    public static function performance(PortalPerformance $performance): array
    {
        return [
            'is_preview' => $performance->reader->isPreview,
            'client_name' => $performance->reader->clientName,
            'period' => [
                'from' => $performance->period->startDate(),
                'until' => $performance->period->endDate(),
                'days' => $performance->period->lengthInDays(),
            ],
            /*
             * Stated rather than implied. A change figure whose baseline is not named is a change figure somebody
             * will read against whatever period they happen to have in mind.
             */
            'comparison_period' => [
                'from' => $performance->comparisonPeriod->startDate(),
                'until' => $performance->comparisonPeriod->endDate(),
            ],
            'source' => $performance->totals->source->value,
            'source_label' => $performance->totals->source->label(),
            'requires_label' => $performance->totals->source->requiresLabel(),
            'currency' => $performance->totals->currency,
            'is_empty' => $performance->totals->isEmpty(),
            'totals' => array_map(
                static fn (object $value): array => MetricValueResource::toArray($value),
                $performance->totals->values,
            ),
            'series' => array_map(
                static fn (SeriesPoint $point): array => [
                    'date' => $point->date->format('Y-m-d'),
                    'source' => $point->metrics->source->value,
                    'values' => array_map(
                        static fn (object $value): array => MetricValueResource::toArray($value),
                        $point->metrics->values,
                    ),
                ],
                $performance->series,
            ),
        ];
    }
}
