<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

use DateTimeImmutable;

/**
 * What is wrong with a project's measurement, worked out from what is known about it.
 *
 * Pure: it takes facts and returns issues. No queries, no clock of its own, no provider
 * calls — which is what makes the diagnosis testable and what keeps it from being "whatever
 * the last person who touched the tracking page believed".
 *
 * **Every issue carries its evidence.** A tracking centre that says "purchase event missing"
 * without saying how it knows is one people stop believing after the first false alarm, and
 * the false alarm is certain: this product can only see what a provider reports to it.
 *
 * Two things it will not do:
 *
 *  - **Guess from a name.** A raw event called `purchase_complete` that nobody has mapped is
 *    reported as unmapped, not silently counted as a purchase. The mapping is the record of
 *    a human decision and nothing substitutes for it.
 *  - **Call an absence a fault without qualification.** A project with no sources is not
 *    "broken tracking", it is a project nobody has set up yet, and the issue says so.
 */
final readonly class TrackingHealth
{
    /**
     * @param  list<SourceFacts>  $sources
     * @param  list<DefinitionFacts>  $definitions
     * @param  list<string>  $unmappedEventNames  raw names seen but mapped to nothing
     * @return list<TrackingIssue>
     */
    public static function diagnose(
        array $sources,
        array $definitions,
        array $unmappedEventNames,
        DateTimeImmutable $now,
    ): array {
        $issues = [];

        if ($sources === []) {
            return [new TrackingIssue(
                code: TrackingIssue::NO_SOURCE,
                severity: TrackingSeverity::Critical,
                summary: 'Nothing is measuring this work yet.',
                remedy: 'Connect an analytics property, a store or an ad platform and bind it here.',
            )];
        }

        foreach ($sources as $source) {
            $issue = self::sourceIssue($source, $now);

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        $issues = [...$issues, ...self::definitionIssues($definitions)];

        foreach ($unmappedEventNames as $name) {
            $issues[] = new TrackingIssue(
                code: TrackingIssue::UNMAPPED_EVENT,
                severity: TrackingSeverity::Warning,
                summary: sprintf('"%s" is arriving but is not mapped to anything, so it is counted as nothing.', $name),
                remedy: 'Map it to a conversion, or leave it unmapped deliberately.',
                subject: $name,
                evidence: ['raw_event_name' => $name],
            );
        }

        usort(
            $issues,
            static fn (TrackingIssue $a, TrackingIssue $b): int => $a->severity->rank() <=> $b->severity->rank(),
        );

        return $issues;
    }

    private static function sourceIssue(SourceFacts $source, DateTimeImmutable $now): ?TrackingIssue
    {
        $freshness = MeasurementFreshness::of($source->lastSyncedAt, $source->lastEventAt, $now);

        return match ($freshness) {
            MeasurementFreshness::NeverSynced => new TrackingIssue(
                code: TrackingIssue::NEVER_SYNCED,
                /*
                 * Critical: a source that has never been read is measuring nothing at all,
                 * and every figure attributed to it is absent rather than low.
                 */
                severity: TrackingSeverity::Critical,
                summary: sprintf('%s has never been read successfully.', $source->name),
                remedy: 'Check the connection and run a sync.',
                subject: $source->id,
                evidence: ['kind' => $source->kind->value],
            ),
            MeasurementFreshness::Stale => new TrackingIssue(
                code: TrackingIssue::STALE_SYNC,
                severity: TrackingSeverity::Warning,
                summary: sprintf(
                    '%s was last read %s. Its figures are not current.',
                    $source->name,
                    self::agoInWords($source->lastSyncedAt, $now),
                ),
                remedy: 'Run a sync, or check whether the connection still has access.',
                subject: $source->id,
                evidence: ['last_synced_at' => $source->lastSyncedAt?->format(DATE_ATOM)],
            ),
            MeasurementFreshness::Silent => new TrackingIssue(
                code: TrackingIssue::NO_RECENT_EVENTS,
                /*
                 * The failure this whole area exists to catch: reachable, syncing, and
                 * measuring nothing. Critical, because every report built on it will be
                 * quietly empty rather than visibly broken.
                 */
                severity: TrackingSeverity::Critical,
                summary: sprintf(
                    '%s is reachable but has reported no events since %s.',
                    $source->name,
                    self::agoInWords($source->lastEventAt, $now),
                ),
                remedy: 'Check that the tag is still deployed and firing on the live site.',
                subject: $source->id,
                evidence: ['last_event_at' => $source->lastEventAt?->format(DATE_ATOM)],
            ),
            MeasurementFreshness::Live, MeasurementFreshness::Synced => null,
        };
    }

    /**
     * @param  list<DefinitionFacts>  $definitions
     * @return list<TrackingIssue>
     */
    private static function definitionIssues(array $definitions): array
    {
        $issues = [];
        $hasPrimary = false;
        $byRawName = [];

        foreach ($definitions as $definition) {
            if ($definition->isPrimary) {
                $hasPrimary = true;
            }

            foreach ($definition->rawEventNames as $raw) {
                $byRawName[$raw][] = $definition->name;
            }

            if ($definition->isConversion && $definition->normalizedEvent->isIntentSignalOnly()) {
                $issues[] = new TrackingIssue(
                    code: TrackingIssue::INTENT_SIGNAL_AS_CONVERSION,
                    severity: TrackingSeverity::Warning,
                    summary: sprintf(
                        '"%s" counts %s as a conversion. That is a signal of intent, not a completed action.',
                        $definition->name,
                        strtolower($definition->normalizedEvent->label()),
                    ),
                    /*
                     * Reported, not refused. An agency may genuinely want to count clicks —
                     * the danger is somebody later reading the number as leads, and naming it
                     * here is what prevents that.
                     */
                    remedy: 'Keep it if that is intended, but do not report it as a lead or a sale.',
                    subject: $definition->id,
                    evidence: ['normalized_event' => $definition->normalizedEvent->value],
                );
            }

            if ($definition->isPrimary && $definition->observedCount === 0) {
                $issues[] = new TrackingIssue(
                    code: TrackingIssue::PRIMARY_CONVERSION_UNSEEN,
                    severity: TrackingSeverity::Critical,
                    summary: sprintf('"%s" is the primary conversion and has never been seen.', $definition->name),
                    remedy: 'Check the event name against what the site actually fires.',
                    subject: $definition->id,
                    evidence: ['mapped_names' => $definition->rawEventNames],
                );
            }

            if ($definition->expectsValue && $definition->observedCount > 0 && ! $definition->hasValue) {
                $issues[] = new TrackingIssue(
                    code: TrackingIssue::PURCHASE_WITHOUT_VALUE,
                    severity: TrackingSeverity::Warning,
                    summary: sprintf('"%s" is arriving without a value, so revenue cannot be reported from it.', $definition->name),
                    remedy: 'Send a value and a currency with the event, or stop expecting one here.',
                    subject: $definition->id,
                    evidence: ['observed_count' => $definition->observedCount],
                );
            }
        }

        foreach ($byRawName as $raw => $names) {
            if (count($names) > 1) {
                $issues[] = new TrackingIssue(
                    code: TrackingIssue::DUPLICATE_MAPPING,
                    severity: TrackingSeverity::Critical,
                    summary: sprintf(
                        '"%s" is mapped to %s. Every one of them counts the same event.',
                        $raw,
                        implode(' and ', $names),
                    ),
                    remedy: 'Keep one mapping, or scope each to a different source.',
                    subject: (string) $raw,
                    evidence: ['definitions' => $names],
                );
            }
        }

        if ($definitions !== [] && ! $hasPrimary) {
            $issues[] = new TrackingIssue(
                code: TrackingIssue::NO_PRIMARY_CONVERSION,
                severity: TrackingSeverity::Warning,
                summary: 'No conversion is marked primary, so nothing can say what success means here.',
                remedy: 'Mark the one that matters most.',
            );
        }

        return $issues;
    }

    private static function agoInWords(?DateTimeImmutable $then, DateTimeImmutable $now): string
    {
        if ($then === null) {
            return 'never';
        }

        $hours = (int) floor(($now->getTimestamp() - $then->getTimestamp()) / 3600);

        return match (true) {
            $hours < 1 => 'less than an hour ago',
            $hours < 48 => sprintf('%d hour%s ago', $hours, $hours === 1 ? '' : 's'),
            default => sprintf('%d days ago', intdiv($hours, 24)),
        };
    }
}
