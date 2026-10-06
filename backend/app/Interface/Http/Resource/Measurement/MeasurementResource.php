<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Measurement;

use App\Application\Measurement\DTO\EventDefinitionView;
use App\Application\Measurement\DTO\EventMappingView;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Measurement\DTO\TrackingHealthView;
use App\Domain\Measurement\TrackingIssue;

final readonly class MeasurementResource
{
    /** @return array<string, mixed> */
    public static function source(MeasurementSourceView $source): array
    {
        return [
            'id' => $source->id,
            'client_id' => $source->clientId,
            'client_name' => $source->clientName,
            'project_id' => $source->projectId,
            'project_name' => $source->projectName,
            'provider' => $source->provider?->value,
            'provider_label' => $source->provider?->label(),
            'kind' => $source->kind->value,
            'kind_label' => $source->kind->label(),
            'external_id' => $source->externalId,
            'name' => $source->name,
            /*
             * Worked out on every read rather than stored, so it is a statement about now. The
             * two timestamps are published alongside it because "stale" and "reporting
             * nothing" are different problems and a reader needs to see which.
             */
            'freshness' => $source->freshness->value,
            'freshness_label' => $source->freshness->label(),
            'needs_attention' => $source->freshness->needsAttention(),
            'is_revenue_authority' => $source->kind->isRevenueAuthority(),
            'is_active' => $source->isActive,
            'last_synced_at' => $source->lastSyncedAt?->format(DATE_ATOM),
            'last_event_at' => $source->lastEventAt?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function definition(EventDefinitionView $definition): array
    {
        return [
            'id' => $definition->id,
            'client_id' => $definition->clientId,
            'project_id' => $definition->projectId,
            'name' => $definition->name,
            'normalized_event' => $definition->normalizedEvent->value,
            'normalized_event_label' => $definition->normalizedEvent->label(),
            'is_conversion' => $definition->isConversion,
            'is_primary' => $definition->isPrimary,
            'expects_value' => $definition->expectsValue,
            /*
             * Published so a screen can mark the distinction the domain draws: a WhatsApp click
             * is not a conversation and a form start is not a submission, and reporting a cost
             * per click as a cost per lead is the commonest way results get overstated.
             */
            'is_intent_signal_only' => $definition->normalizedEvent->isIntentSignalOnly(),
            'description' => $definition->description,
            'mappings' => array_map(
                static fn (EventMappingView $mapping): array => [
                    'id' => $mapping->id,
                    'raw_event_name' => $mapping->rawEventName,
                    // Null means "from any source", which is a different statement from
                    // "from a source we cannot name".
                    'measurement_source_id' => $mapping->measurementSourceId,
                    'measurement_source_name' => $mapping->measurementSourceName,
                ],
                $definition->mappings,
            ),
            /** Null when no window was asked for; 0 means asked, and never seen. */
            'observed_count' => $definition->observedCount,
            'last_seen_date' => $definition->lastSeenDate,
        ];
    }

    /** @return array<string, mixed> */
    public static function health(TrackingHealthView $health): array
    {
        return [
            'is_healthy' => $health->isHealthy(),
            'critical_count' => $health->criticalCount(),
            // Published so a count of zero is readable as "none in fourteen days".
            'window_days' => $health->windowDays,
            'sources' => array_map(static fn (MeasurementSourceView $s): array => self::source($s), $health->sources),
            'definitions' => array_map(
                static fn (EventDefinitionView $d): array => self::definition($d),
                $health->definitions,
            ),
            'issues' => array_map(static fn (TrackingIssue $issue): array => [
                'code' => $issue->code,
                'severity' => $issue->severity->value,
                'severity_label' => $issue->severity->label(),
                'summary' => $issue->summary,
                'remedy' => $issue->remedy,
                'subject' => $issue->subject,
                /*
                 * The facts the diagnosis rests on. A tracking centre that says "purchase event
                 * missing" without saying how it knows is one people stop believing after the
                 * first false alarm — and a false alarm is certain, because this product can
                 * only see what a provider reports to it.
                 */
                'evidence' => $issue->evidence,
            ], $health->issues),
        ];
    }
}
