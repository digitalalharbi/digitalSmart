<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Measurement;

use App\Application\Measurement\DTO\ContainerReadinessView;
use App\Application\Measurement\DTO\ConversionSuggestion;
use App\Application\Measurement\DTO\DataStreamRecord;
use App\Application\Measurement\DTO\DiscoveredContainer;
use App\Application\Measurement\DTO\DiscoveredProperty;
use App\Application\Measurement\DTO\EventDefinitionView;
use App\Application\Measurement\DTO\EventMappingView;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Measurement\DTO\MeasurementSyncResult;
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
    public static function property(DiscoveredProperty $property): array
    {
        return [
            // The provider's resource name, prefix included: it is what every request is addressed to.
            'property_id' => $property->propertyId,
            'display_name' => $property->displayName,
            'account_name' => $property->accountName,
            'account_display_name' => $property->accountDisplayName,
            'property_type' => $property->propertyType,
            /*
             * Flagged because a roll-up counts the same event its source properties do, so
             * measuring one client through both double-counts — and nothing later in the product
             * could detect that it had happened.
             */
            'is_aggregate' => $property->isAggregate(),
            'streams' => array_map(static fn (DataStreamRecord $stream): array => [
                'stream_id' => $stream->streamId,
                'display_name' => $stream->displayName,
                'type' => $stream->type,
                // The site and the G- id are how a person recognises the property as their client's.
                'measurement_id' => $stream->measurementId,
                'default_uri' => $stream->defaultUri,
                'app_identifier' => $stream->appIdentifier,
                'is_web' => $stream->isWeb(),
            ], $property->streams),
        ];
    }

    /** @return array<string, mixed> */
    public static function suggestion(ConversionSuggestion $suggestion): array
    {
        return [
            'raw_event_name' => $suggestion->rawEventName,
            /*
             * Null means "ask". This product places a name only from the provider's own published
             * vocabulary, so a name it cannot cite is one a person chooses — including names that
             * look obvious.
             */
            'normalized_event' => $suggestion->normalizedEvent?->value,
            'normalized_event_label' => $suggestion->normalizedEvent?->label(),
            'is_intent_signal_only' => $suggestion->normalizedEvent?->isIntentSignalOnly(),
            // So confirming the same list twice cannot create a second definition for one event.
            'already_mapped' => $suggestion->alreadyMapped,
            'is_custom' => $suggestion->isCustom,
            /*
             * Counted per session, two purchases in one visit are one key event. Published so
             * nobody reconciles it against an order count and concludes the tracking is broken.
             */
            'counted_once_per_session' => $suggestion->countedOncePerSession,
            'default_value' => $suggestion->defaultValue,
            'default_currency' => $suggestion->defaultCurrency,
        ];
    }

    /** @return array<string, mixed> */
    public static function syncResult(MeasurementSyncResult $result): array
    {
        return [
            'status' => $result->status->value,
            'days_read' => $result->daysRead,
            'rows_written' => $result->rowsWritten,
            'api_calls' => $result->apiCalls,
            /*
             * The weakest label anything in the run carries, and the caveats behind it. A run can
             * succeed completely and still have read estimates; without these a caller would
             * present them as totals.
             */
            'source' => $result->source->value,
            'source_label' => $result->source->label(),
            'caveats' => $result->caveats,
            'last_event_date' => $result->lastEventDate,
            'failure_message' => $result->failureMessage,
        ];
    }

    /** @return array<string, mixed> */
    public static function container(DiscoveredContainer $container): array
    {
        return [
            'container_id' => $container->containerId,
            'path' => $container->path,
            'name' => $container->name,
            /*
             * The one field that proves a container is the right one: `GTM-XXXXXX` is what somebody
             * will find in their client's page source. A numeric id proves nothing to anybody.
             */
            'public_id' => $container->publicId,
            'account_id' => $container->accountId,
            'usage_contexts' => $container->usageContexts,
            // So nobody expects web events from a server-side or app container.
            'is_web' => $container->isWeb(),
            'domain_names' => $container->domainNames,
            'tag_ids' => $container->tagIds,
            'tag_manager_url' => $container->tagManagerUrl,
        ];
    }

    /** @return array<string, mixed> */
    public static function readiness(ContainerReadinessView $readiness): array
    {
        return [
            'container' => self::container($readiness->container),
            'is_ready' => $readiness->isReady(),
            /*
             * Published state, not workspace state. A workspace holds what somebody is working on;
             * this is what the site is running, and believing the first is the second is the
             * commonest failure in this whole area.
             */
            'has_live_version' => $readiness->hasLiveVersion,
            'live_version_id' => $readiness->liveVersionId,
            'has_unpublished_changes' => $readiness->hasUnpublishedChanges,
            'published_tag_count' => $readiness->publishedTagCount,
            'critical_count' => $readiness->criticalCount(),
            /*
             * Both lists travel with the findings so a reader can judge the measurement-id claim
             * rather than take it on trust — the container may be loading a property through a
             * template this product cannot read.
             */
            'measurement_ids_found' => $readiness->measurementIdsFound,
            'measurement_ids_expected' => $readiness->measurementIdsExpected,
            'issues' => array_map(static fn (TrackingIssue $issue): array => [
                'code' => $issue->code,
                'severity' => $issue->severity->value,
                'severity_label' => $issue->severity->label(),
                'summary' => $issue->summary,
                'remedy' => $issue->remedy,
                'subject' => $issue->subject,
                'evidence' => $issue->evidence,
            ], $readiness->issues),
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
