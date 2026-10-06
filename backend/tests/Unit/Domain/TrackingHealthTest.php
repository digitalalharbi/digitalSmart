<?php

declare(strict_types=1);

use App\Domain\Measurement\DefinitionFacts;
use App\Domain\Measurement\MeasurementFreshness;
use App\Domain\Measurement\MeasurementSourceKind;
use App\Domain\Measurement\NormalizedEvent;
use App\Domain\Measurement\SourceFacts;
use App\Domain\Measurement\TrackingHealth;
use App\Domain\Measurement\TrackingIssue;
use App\Domain\Measurement\TrackingSeverity;

/*
|--------------------------------------------------------------------------
| Tracking health
|--------------------------------------------------------------------------
|
| A tracking centre is only worth having if people believe it, and they stop believing it
| after the first false alarm. So these tests are mostly about restraint: what it refuses to
| call a fault, what it refuses to infer from an event name, and which of two similar-looking
| situations it treats as urgent.
|
*/

/** Fixed so 'now' is a fact of the test rather than a property of the machine running it. */
const HEALTH_NOW = '2026-10-06 12:00:00';

function healthNow(): DateTimeImmutable
{
    return new DateTimeImmutable(HEALTH_NOW);
}

function trackingSource(
    string $name = 'GA4 property',
    MeasurementSourceKind $kind = MeasurementSourceKind::Analytics,
    ?string $syncedAt = '2026-10-06 11:00:00',
    ?string $eventAt = '2026-10-06 10:00:00',
): SourceFacts {
    return new SourceFacts(
        id: 'source-'.$name,
        name: $name,
        kind: $kind,
        lastSyncedAt: $syncedAt === null ? null : new DateTimeImmutable($syncedAt),
        lastEventAt: $eventAt === null ? null : new DateTimeImmutable($eventAt),
    );
}

/** @param list<string> $rawNames */
function trackingDefinition(
    string $name = 'Lead',
    NormalizedEvent $event = NormalizedEvent::GenerateLead,
    bool $isPrimary = true,
    int $observed = 42,
    bool $expectsValue = false,
    bool $hasValue = false,
    array $rawNames = ['generate_lead'],
    bool $isConversion = true,
): DefinitionFacts {
    return new DefinitionFacts(
        id: 'definition-'.$name,
        name: $name,
        normalizedEvent: $event,
        isConversion: $isConversion,
        isPrimary: $isPrimary,
        expectsValue: $expectsValue,
        observedCount: $observed,
        hasValue: $hasValue,
        rawEventNames: $rawNames,
    );
}

/**
 * The same ranks, sorted — what the order *should* be, derived rather than written out, so
 * the test states the rule (worst first) instead of restating one expected arrangement.
 *
 * @param  list<int>  $ranks
 * @return list<int>
 */
function sortedRanks(array $ranks): array
{
    sort($ranks);

    return $ranks;
}

/**
 * @param  list<TrackingIssue>  $issues
 * @return list<string>
 */
function issueCodes(array $issues): array
{
    return array_map(static fn (TrackingIssue $issue): string => $issue->code, $issues);
}

describe('a healthy project', function (): void {
    it('reports nothing when everything is arriving', function (): void {
        $issues = TrackingHealth::diagnose([trackingSource()], [trackingDefinition()], [], healthNow());

        // No news is the only acceptable output for working tracking. A list that always has
        // something in it is a list nobody reads.
        expect($issues)->toBe([]);
    });
});

describe('what it refuses to call a fault', function (): void {
    it('does not call a tag manager silent for reporting no events', function (): void {
        /*
         * A container reports configuration, not events. The caller passes null for its last
         * event precisely so this cannot become a permanent false alarm — and the engine must
         * treat that null as "not applicable" rather than "nothing arrived".
         */
        $issues = TrackingHealth::diagnose(
            [trackingSource('GTM container', MeasurementSourceKind::TagManager, eventAt: null)],
            [trackingDefinition()],
            [],
            healthNow(),
        );

        expect($issues)->toBe([]);
    });

    it('says a project with nothing set up is not set up, rather than broken', function (): void {
        $issues = TrackingHealth::diagnose([], [], [], healthNow());

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->code)->toBe(TrackingIssue::NO_SOURCE)
            // The remedy is the useful half: somebody has to connect something.
            ->and($issues[0]->remedy)->toContain('Connect');
    });

    it('does not invent a primary conversion for a project with none defined', function (): void {
        // No definitions at all is a project that has not got there yet, not a misconfiguration.
        $issues = TrackingHealth::diagnose([trackingSource()], [], [], healthNow());

        expect(issueCodes($issues))->not->toContain(TrackingIssue::NO_PRIMARY_CONVERSION);
    });
});

describe('what it treats as urgent', function (): void {
    it('calls a source that syncs but reports nothing critical', function (): void {
        $issues = TrackingHealth::diagnose(
            [trackingSource(eventAt: '2026-10-01 09:00:00')],
            [trackingDefinition()],
            [],
            healthNow(),
        );

        /*
         * The failure this whole area exists to catch: reachable, syncing, measuring nothing.
         * Critical because every report built on it is quietly empty rather than visibly
         * broken, which is the kind of problem that survives a month of weekly reviews.
         */
        expect($issues)->toHaveCount(1)
            ->and($issues[0]->code)->toBe(TrackingIssue::NO_RECENT_EVENTS)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Critical)
            ->and($issues[0]->evidence)->toHaveKey('last_event_at');
    });

    it('calls a stale sync a warning, not a crisis', function (): void {
        $issues = TrackingHealth::diagnose(
            [trackingSource(syncedAt: '2026-10-03 09:00:00', eventAt: '2026-10-03 08:00:00')],
            [trackingDefinition()],
            [],
            healthNow(),
        );

        // The figures are old, not absent. A different problem from measuring nothing, and it
        // gets a different level so the urgent one stays findable.
        expect($issues)->toHaveCount(1)
            ->and($issues[0]->code)->toBe(TrackingIssue::STALE_SYNC)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Warning);
    });

    it('calls a never-read source critical', function (): void {
        $issues = TrackingHealth::diagnose([trackingSource(syncedAt: null, eventAt: null)], [], [], healthNow());

        expect($issues[0]->code)->toBe(TrackingIssue::NEVER_SYNCED)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Critical);
    });

    it('calls an unseen primary conversion critical', function (): void {
        $issues = TrackingHealth::diagnose([trackingSource()], [trackingDefinition(observed: 0)], [], healthNow());

        /*
         * The thing the client is paying to have measured has never happened as far as this
         * product can tell. Either the campaign is failing or the measurement is — and both
         * need somebody today.
         */
        expect($issues[0]->code)->toBe(TrackingIssue::PRIMARY_CONVERSION_UNSEEN)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Critical)
            ->and($issues[0]->evidence['mapped_names'])->toBe(['generate_lead']);
    });

    it('calls two definitions claiming one event name critical', function (): void {
        $issues = TrackingHealth::diagnose(
            [trackingSource()],
            [
                trackingDefinition('Lead', rawNames: ['purchase']),
                trackingDefinition('Sale', NormalizedEvent::Purchase, isPrimary: false, rawNames: ['purchase']),
            ],
            [],
            healthNow(),
        );

        // Both count every purchase, so a dashboard showing both shows the same sale twice.
        expect(issueCodes($issues))->toContain(TrackingIssue::DUPLICATE_MAPPING);

        $duplicate = array_values(array_filter(
            $issues,
            static fn (TrackingIssue $issue): bool => $issue->code === TrackingIssue::DUPLICATE_MAPPING,
        ))[0];

        expect($duplicate->severity)->toBe(TrackingSeverity::Critical)
            ->and($duplicate->evidence['definitions'])->toBe(['Lead', 'Sale']);
    });
});

describe('what it will not infer', function (): void {
    it('reports an unmapped event rather than guessing what it means', function (): void {
        $issues = TrackingHealth::diagnose([trackingSource()], [trackingDefinition()], ['purchase_complete'], healthNow());

        /*
         * `purchase_complete` looks exactly like a purchase and is not treated as one. One
         * property's `contact` is a form and another's is a phone tap; the mapping is the
         * record of a human decision and nothing substitutes for it.
         */
        expect(issueCodes($issues))->toBe([TrackingIssue::UNMAPPED_EVENT])
            ->and($issues[0]->summary)->toContain('counted as nothing')
            ->and($issues[0]->subject)->toBe('purchase_complete');
    });

    it('warns when an intent signal is counted as a conversion, without refusing it', function (): void {
        $issues = TrackingHealth::diagnose(
            [trackingSource()],
            [trackingDefinition('WhatsApp', NormalizedEvent::WhatsAppClick, rawNames: ['whatsapp_click'])],
            [],
            healthNow(),
        );

        /*
         * A click is not a conversation. Reported rather than refused, because an agency may
         * genuinely want to count clicks — the danger is somebody later reading the number as
         * leads, and naming it here is what prevents that.
         */
        expect(issueCodes($issues))->toContain(TrackingIssue::INTENT_SIGNAL_AS_CONVERSION);

        $issue = array_values(array_filter(
            $issues,
            static fn (TrackingIssue $i): bool => $i->code === TrackingIssue::INTENT_SIGNAL_AS_CONVERSION,
        ))[0];

        expect($issue->severity)->toBe(TrackingSeverity::Warning)
            ->and($issue->remedy)->toContain('do not report it as a lead');
    });
});

describe('values and money', function (): void {
    it('notices purchases arriving with no value', function (): void {
        $issues = TrackingHealth::diagnose(
            [trackingSource()],
            [trackingDefinition('Sale', NormalizedEvent::Purchase, expectsValue: true, hasValue: false, rawNames: ['purchase'])],
            [],
            healthNow(),
        );

        // Revenue cannot be reported from a count. Declared expectations are what make this
        // detectable rather than a column of nulls nobody notices.
        expect(issueCodes($issues))->toContain(TrackingIssue::PURCHASE_WITHOUT_VALUE);
    });

    it('says nothing about a value it was never promised', function (): void {
        $issues = TrackingHealth::diagnose(
            [trackingSource()],
            [trackingDefinition('Lead', expectsValue: false, hasValue: false)],
            [],
            healthNow(),
        );

        // A lead has no value and is not supposed to.
        expect($issues)->toBe([]);
    });

    it('does not complain about a missing value for an event never seen', function (): void {
        $issues = TrackingHealth::diagnose(
            [trackingSource()],
            [trackingDefinition('Sale', NormalizedEvent::Purchase, observed: 0, expectsValue: true, rawNames: ['purchase'])],
            [],
            healthNow(),
        );

        /*
         * One problem, not two. An event that has never fired cannot be faulted for arriving
         * without a value, and reporting both would double the noise for one cause.
         */
        expect(issueCodes($issues))->toBe([TrackingIssue::PRIMARY_CONVERSION_UNSEEN]);
    });
});

describe('ordering', function (): void {
    it('puts the worst first, because that is the order somebody works through them', function (): void {
        $issues = TrackingHealth::diagnose(
            [trackingSource(eventAt: '2026-10-01 09:00:00')],
            [trackingDefinition('Clicks', NormalizedEvent::PhoneClick, isPrimary: false, rawNames: ['phone_click'])],
            ['unmapped_thing'],
            healthNow(),
        );

        $severities = array_map(
            static fn (TrackingIssue $issue): int => $issue->severity->rank(),
            $issues,
        );

        expect($severities)->toBe(sortedRanks($severities))
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Critical);
    });
});

describe('freshness', function (): void {
    it('tells never-synced from stale from silent from live', function (): void {
        $at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable($time);

        expect(MeasurementFreshness::of(null, null, healthNow()))->toBe(MeasurementFreshness::NeverSynced)
            ->and(MeasurementFreshness::of($at('2026-10-01 09:00'), $at('2026-10-01 09:00'), healthNow()))
            ->toBe(MeasurementFreshness::Stale)
            ->and(MeasurementFreshness::of($at('2026-10-06 11:00'), $at('2026-10-02 09:00'), healthNow()))
            ->toBe(MeasurementFreshness::Silent)
            ->and(MeasurementFreshness::of($at('2026-10-06 11:00'), $at('2026-10-06 10:00'), healthNow()))
            ->toBe(MeasurementFreshness::Live)
            /*
             * A source that syncs but has no event timestamp at all — a tag manager — is
             * synced, not silent. The distinction is the caller's to make, and the engine
             * honours it.
             */
            ->and(MeasurementFreshness::of($at('2026-10-06 11:00'), null, healthNow()))
            ->toBe(MeasurementFreshness::Synced);
    });

    it('calls stale and silent states that need attention, and the others not', function (): void {
        expect(MeasurementFreshness::Live->needsAttention())->toBeFalse()
            ->and(MeasurementFreshness::Synced->needsAttention())->toBeFalse()
            ->and(MeasurementFreshness::Stale->needsAttention())->toBeTrue()
            ->and(MeasurementFreshness::Silent->needsAttention())->toBeTrue()
            ->and(MeasurementFreshness::NeverSynced->needsAttention())->toBeTrue();
    });
});

describe('the event vocabulary', function (): void {
    it('knows which events are conversions by nature', function (): void {
        // Defaults for a form, not a rule: the conversion registry decides per project. But
        // nobody should have to argue with an interface about whether a page view is a goal.
        expect(NormalizedEvent::Purchase->isConversionByNature())->toBeTrue()
            ->and(NormalizedEvent::GenerateLead->isConversionByNature())->toBeTrue()
            ->and(NormalizedEvent::PageView->isConversionByNature())->toBeFalse()
            ->and(NormalizedEvent::SessionStart->isConversionByNature())->toBeFalse()
            ->and(NormalizedEvent::FormStart->isConversionByNature())->toBeFalse();
    });

    it('marks the signals that are not completed actions', function (): void {
        /*
         * The distinction that stops a cost per WhatsApp click being reported as a cost per
         * lead — the commonest way an agency overstates its results.
         */
        expect(NormalizedEvent::WhatsAppClick->isIntentSignalOnly())->toBeTrue()
            ->and(NormalizedEvent::PhoneClick->isIntentSignalOnly())->toBeTrue()
            ->and(NormalizedEvent::FormStart->isIntentSignalOnly())->toBeTrue()
            ->and(NormalizedEvent::FormSubmit->isIntentSignalOnly())->toBeFalse()
            ->and(NormalizedEvent::Purchase->isIntentSignalOnly())->toBeFalse();
    });
});

describe('source kinds', function (): void {
    it('treats only a store and a CRM as authorities on money', function (): void {
        /*
         * An analytics property reports what a browser said a purchase was worth, which is a
         * measurement of a page rather than of a sale; an ad platform reports what it believes
         * it caused. Only an invoice is an invoice.
         */
        expect(MeasurementSourceKind::Store->isRevenueAuthority())->toBeTrue()
            ->and(MeasurementSourceKind::Crm->isRevenueAuthority())->toBeTrue()
            ->and(MeasurementSourceKind::Analytics->isRevenueAuthority())->toBeFalse()
            ->and(MeasurementSourceKind::AdPlatform->isRevenueAuthority())->toBeFalse();
    });
});
