<?php

declare(strict_types=1);

use App\Domain\Advertising\MetricSource;
use App\Infrastructure\Measurement\Mapper\GoogleAnalyticsMapper;

/*
|--------------------------------------------------------------------------
| Google Analytics 4 mapping
|--------------------------------------------------------------------------
|
| Raw responses in the shapes the Admin and Data APIs document, normalised records out. The
| two things most worth proving here are that a report row is read through its headers rather
| than by position — the failure that would turn every date into an event name without anything
| looking wrong — and that what GA4 says about the completeness of its own answer survives into
| the figures' source label.
|
*/

function ga4(): GoogleAnalyticsMapper
{
    return new GoogleAnalyticsMapper;
}

/**
 * A `runReport` response, built from the columns a caller asks for.
 *
 * @param  list<array{string, string, string, string|null}>  $rows  name, date, count, value
 * @param  list<string>  $dimensions
 * @param  list<string>  $metrics
 * @param  array<string, mixed>  $metadata
 * @return array<string, mixed>
 */
function runReport(
    array $rows,
    array $dimensions = ['eventName', 'date'],
    array $metrics = ['eventCount', 'purchaseRevenue'],
    array $metadata = ['currencyCode' => 'SAR', 'timeZone' => 'Asia/Riyadh'],
): array {
    $mapped = [];

    foreach ($rows as [$name, $date, $count, $value]) {
        $byDimension = ['eventName' => $name, 'date' => $date];
        $byMetric = ['eventCount' => $count, 'purchaseRevenue' => $value ?? '0'];

        $mapped[] = [
            'dimensionValues' => array_map(
                static fn (string $d): array => ['value' => $byDimension[$d] ?? ''],
                $dimensions,
            ),
            'metricValues' => array_map(
                static fn (string $m): array => ['value' => $byMetric[$m] ?? '0'],
                $metrics,
            ),
        ];
    }

    return [
        'dimensionHeaders' => array_map(static fn (string $d): array => ['name' => $d], $dimensions),
        'metricHeaders' => array_map(static fn (string $m): array => ['name' => $m], $metrics),
        'rows' => $mapped,
        'rowCount' => count($mapped),
        'metadata' => $metadata,
    ];
}

describe('reading a report by its headers', function (): void {
    it('reads the columns by name, not by the order we asked for', function (): void {
        /*
         * The whole reason this mapper indexes its headers. Here `date` comes first and
         * `purchaseRevenue` before `eventCount` — a positional reader would store the date as an
         * event name and the revenue as a count, and every figure would still look plausible.
         */
        $response = runReport(
            [['purchase', '20260601', '12', '8415.75']],
            dimensions: ['date', 'eventName'],
            metrics: ['purchaseRevenue', 'eventCount'],
        );

        $records = ga4()->observedEvents($response, 'purchaseRevenue', 'SAR');

        expect($records)->toHaveCount(1)
            ->and($records[0]->rawEventName)->toBe('purchase')
            ->and($records[0]->date)->toBe('2026-06-01')
            ->and($records[0]->count)->toBe(12)
            ->and($records[0]->valueMinor)->toBe(841_575);
    });

    it('returns nothing when a column it needs is absent', function (): void {
        // Better empty than guessed. A report without an event name column describes something
        // else, and inventing a name for its rows would put fiction in the counts table.
        $response = runReport([['purchase', '20260601', '12', null]], dimensions: ['date']);

        expect(ga4()->observedEvents($response, null, 'SAR'))->toBe([]);
    });

    it('converts the provider date format rather than storing it raw', function (): void {
        $records = ga4()->observedEvents(runReport([['page_view', '20261231', '7', null]]), null, null);

        expect($records[0]->date)->toBe('2026-12-31');
    });

    it('drops a row whose day cannot be read', function (): void {
        /*
         * GA4 puts `(other)` in the date column when it rolls days up. Dropped rather than
         * parked on today, which would move somebody else's traffic into this morning.
         */
        $records = ga4()->observedEvents(runReport([
            ['page_view', '(other)', '91', null],
            ['page_view', '20260601', '7', null],
        ]), null, null);

        expect($records)->toHaveCount(1)
            ->and($records[0]->count)->toBe(7);
    });

    it('skips the (other) event bucket instead of storing it as an event name', function (): void {
        /*
         * `(other)` is not an event. Storing it would offer somebody a conversion called
         * "(other)" to map — and whatever they mapped it to would be wrong. Its presence is
         * reported as a caveat on the response instead.
         */
        $records = ga4()->observedEvents(runReport([
            ['(other)', '20260601', '118', null],
            ['purchase', '20260601', '11', null],
        ]), null, null);

        expect(array_map(fn ($r): string => $r->rawEventName, $records))->toBe(['purchase']);
    });
});

describe('values and money', function (): void {
    it('reads no value at all when the property has no currency metric', function (): void {
        /*
         * The adapter passes null when the property's own metadata offers no currency metric.
         * Reading one anyway would mean inventing a metric name, and a zero stored here would
         * read as "this sale was worth nothing".
         */
        $records = ga4()->observedEvents(runReport([['purchase', '20260601', '12', '8415.75']]), null, 'SAR');

        expect($records[0]->valueMinor)->toBeNull()
            ->and($records[0]->currency)->toBeNull();
    });

    it('treats zero revenue as no value reported, not as nothing earned', function (): void {
        /*
         * GA4 sends 0 for every non-revenue event. Stored as null so the tracking health check
         * can still say a purchase arrived without a value — the fault somebody needs to hear
         * about, which a stored zero would hide behind a figure that looks answered.
         */
        $records = ga4()->observedEvents(runReport([['generate_lead', '20260601', '37', '0']]), 'purchaseRevenue', 'SAR');

        expect($records[0]->count)->toBe(37)
            ->and($records[0]->valueMinor)->toBeNull()
            ->and($records[0]->currency)->toBeNull();
    });

    it('refuses a value with no currency to put it in', function (): void {
        // An amount without a unit is the first thing somebody would add to another one.
        $records = ga4()->observedEvents(runReport([['purchase', '20260601', '2', '500.00']]), 'purchaseRevenue', null);

        expect($records[0]->valueMinor)->toBeNull();
    });

    it('rounds a decimal string to minor units', function (): void {
        $records = ga4()->observedEvents(runReport([['purchase', '20260601', '1', '19.995']]), 'purchaseRevenue', 'SAR');

        // Half-up at the minor unit, as every platform's own billing does.
        expect($records[0]->valueMinor)->toBe(2_000);
    });

    it('carries a currency only alongside an amount', function (): void {
        $records = ga4()->observedEvents(runReport([
            ['purchase', '20260601', '3', '120.00'],
            ['page_view', '20260601', '900', '0'],
        ]), 'purchaseRevenue', 'SAR');

        expect($records[0]->currency)->toBe('SAR')
            ->and($records[1]->currency)->toBeNull();
    });
});

describe('what the provider says about its own answer', function (): void {
    it('reports a complete response as complete', function (): void {
        $quality = ga4()->quality(runReport([['purchase', '20260601', '1', null]]));

        expect($quality->isPartial())->toBeFalse()
            ->and($quality->caveats())->toBe([])
            ->and($quality->metricSource(isSandbox: false))->toBe(MetricSource::Synced);
    });

    it('computes the sampling fraction from the counts GA4 sends as strings', function (): void {
        $quality = ga4()->quality(runReport([], metadata: [
            'samplingMetadatas' => [
                ['samplesReadCount' => '4120055', 'samplingSpaceSize' => '51500687'],
            ],
        ]));

        expect($quality->isSampled)->toBeTrue()
            ->and($quality->samplingFraction)->toBeGreaterThan(0.079)
            ->and($quality->samplingFraction)->toBeLessThan(0.081)
            // An estimate from 8% of the data is not a figure to present as a total.
            ->and($quality->metricSource(isSandbox: false))->toBe(MetricSource::Partial)
            ->and($quality->caveats()[0])->toContain('8.0%');
    });

    it('takes the worst sampling fraction across date ranges', function (): void {
        $quality = ga4()->quality(runReport([], metadata: [
            'samplingMetadatas' => [
                ['samplesReadCount' => '100', 'samplingSpaceSize' => '100'],
                ['samplesReadCount' => '5', 'samplingSpaceSize' => '100'],
            ],
        ]));

        // A total spanning a complete range and a heavily sampled one is only as good as its
        // worst part.
        expect($quality->samplingFraction)->toBe(0.05);
    });

    it('does not round a tiny sample away to zero', function (): void {
        $quality = ga4()->quality(runReport([], metadata: [
            'samplingMetadatas' => [
                ['samplesReadCount' => '400', 'samplingSpaceSize' => '100000'],
            ],
        ]));

        /*
         * "0%" would read as "no data" when it actually means the figure is a two-hundredfold
         * extrapolation — the single most misleading rounding available here.
         */
        expect($quality->caveats()[0])->toContain('0.40%');
    });

    it('notices thresholding, truncation and the (other) row separately', function (): void {
        $quality = ga4()->quality(runReport([], metadata: [
            'subjectToThresholding' => true,
            'dataTruncationReasons' => ['DATA_TRUNCATION_REASON_UNSPECIFIED'],
            'dataLossFromOtherRow' => true,
        ]));

        // Three causes, three sentences. Collapsing them would hide whichever is cheapest to fix.
        expect($quality->isThresholded)->toBeTrue()
            ->and($quality->isTruncated)->toBeTrue()
            ->and($quality->hasOtherRow)->toBeTrue()
            ->and($quality->caveats())->toHaveCount(3)
            ->and($quality->metricSource(isSandbox: false))->toBe(MetricSource::Partial);
    });

    it('labels fixtures as sandbox whatever else they claim', function (): void {
        $quality = ga4()->quality(runReport([], metadata: ['subjectToThresholding' => true]));

        // Sandbox wins outright. Fixtures are never shown as production.
        expect($quality->metricSource(isSandbox: true))->toBe(MetricSource::Sandbox);
    });

    it('does not treat an empty report as a defect', function (): void {
        $quality = ga4()->quality(runReport([], metadata: ['emptyReason' => 'NO_DATA']));

        /*
         * A property with no traffic in the window is a fact about the client, not a fault in
         * the read. Recorded, and deliberately not counted as partial.
         */
        expect($quality->emptyReason)->toBe('NO_DATA')
            ->and($quality->isPartial())->toBeFalse();
    });

    it('assumes nothing when there is no metadata block', function (): void {
        expect(ga4()->quality(['rows' => []])->isPartial())->toBeFalse();
    });

    it('reads the property currency and time zone from the response', function (): void {
        $response = runReport([]);

        // The time zone is the day boundary every date in the report was cut on.
        expect(ga4()->currencyOf($response))->toBe('SAR')
            ->and(ga4()->timeZoneOf($response))->toBe('Asia/Riyadh');
    });
});

describe('choosing the money metric', function (): void {
    /** @return array<string, mixed> */
    $metadata = static fn (array $metrics): array => ['metrics' => $metrics];

    it('picks the metric GA4 itself calls an amount of money', function () use ($metadata): void {
        $name = ga4()->valueMetric($metadata([
            ['apiName' => 'eventCount', 'type' => 'TYPE_INTEGER'],
            ['apiName' => 'purchaseRevenue', 'type' => 'TYPE_CURRENCY'],
        ]));

        // By the provider's declared type, not by matching a name we liked the look of.
        expect($name)->toBe('purchaseRevenue');
    });

    it('skips a currency metric the property has blocked', function () use ($metadata): void {
        /*
         * `NO_REVENUE_METRICS` means the property will refuse to report it. Asking anyway turns
         * every sync into a 400.
         */
        $name = ga4()->valueMetric($metadata([
            ['apiName' => 'advertiserAdCost', 'type' => 'TYPE_CURRENCY', 'blockedReasons' => ['NO_COST_METRICS']],
            ['apiName' => 'totalRevenue', 'type' => 'TYPE_CURRENCY'],
        ]));

        expect($name)->toBe('totalRevenue');
    });

    it('returns null when the property reports no money at all', function () use ($metadata): void {
        /*
         * The honest answer, and the one that makes the adapter read counts only. A fabricated
         * metric name would fail the request; a fabricated zero would be worse.
         */
        $name = ga4()->valueMetric($metadata([
            ['apiName' => 'eventCount', 'type' => 'TYPE_INTEGER'],
            ['apiName' => 'averageSessionDuration', 'type' => 'TYPE_SECONDS'],
        ]));

        expect($name)->toBeNull();
    });

    it('prefers the asked-for metric but takes what the property has', function () use ($metadata): void {
        $name = ga4()->valueMetric($metadata([
            ['apiName' => 'totalRevenue', 'type' => 'TYPE_CURRENCY'],
        ]), preferred: 'purchaseRevenue');

        // Deterministic either way: a sync that chose differently each run would make a revenue
        // series that moves for no reason anybody could find.
        expect($name)->toBe('totalRevenue');
    });
});

describe('properties and streams', function (): void {
    /** @return array<string, mixed> */
    $summary = static fn (): array => [
        'name' => 'accountSummaries/8120045',
        'account' => 'accounts/8120045',
        'displayName' => 'Smart Code Agency',
        'propertySummaries' => [
            [
                'property' => 'properties/412006881',
                'displayName' => 'Noon Retail — Web',
                'propertyType' => 'PROPERTY_TYPE_ORDINARY',
                'parent' => 'accounts/8120045',
            ],
            [
                'property' => 'properties/412006890',
                'displayName' => 'Group roll-up',
                'propertyType' => 'PROPERTY_TYPE_ROLLUP',
                'parent' => 'accounts/8120045',
            ],
        ],
    ];

    it('keeps the provider resource name verbatim', function () use ($summary): void {
        $properties = ga4()->properties($summary());

        // `properties/412006881`, prefix included: it is what every later request is addressed to.
        expect($properties[0]->propertyId)->toBe('properties/412006881')
            ->and($properties[0]->accountDisplayName)->toBe('Smart Code Agency');
    });

    it('marks a roll-up property as an aggregate', function () use ($summary): void {
        /*
         * Worth knowing before anybody reports from it: a roll-up counts the same event its
         * source properties do, so measuring one client through both double-counts.
         */
        expect(ga4()->properties($summary())[1]->isAggregate())->toBeTrue()
            ->and(ga4()->properties($summary())[0]->isAggregate())->toBeFalse();
    });

    it('skips a summary with no resource name', function (): void {
        $properties = ga4()->properties([
            'account' => 'accounts/1',
            'propertySummaries' => [['displayName' => 'Nameless']],
        ]);

        // Nothing could be read from it or stored against it.
        expect($properties)->toBe([]);
    });

    it('falls back to the resource name rather than inventing a label', function (): void {
        $properties = ga4()->properties([
            'account' => 'accounts/1',
            'propertySummaries' => [['property' => 'properties/99']],
        ]);

        // "properties/99" is at least true; "Untitled property" would be made up.
        expect($properties[0]->displayName)->toBe('properties/99');
    });

    it('attaches the streams belonging to each property', function () use ($summary): void {
        $stream = ga4()->dataStream([
            'name' => 'properties/412006881/dataStreams/7740012',
            'type' => 'WEB_DATA_STREAM',
            'displayName' => 'noonretail.sa',
            'webStreamData' => ['measurementId' => 'G-7QK2M4XZPL', 'defaultUri' => 'https://noonretail.sa'],
        ]);

        $properties = ga4()->properties($summary(), ['properties/412006881' => [$stream]]);

        /*
         * The site and the `G-` id are how a person recognises the property as their client's.
         * A list of numeric ids is unusable for the one decision that screen exists to support.
         */
        expect($properties[0]->streams)->toHaveCount(1)
            ->and($properties[0]->streams[0]->measurementId)->toBe('G-7QK2M4XZPL')
            ->and($properties[0]->streams[0]->defaultUri)->toBe('https://noonretail.sa')
            ->and($properties[1]->streams)->toBe([]);
    });

    it('flattens whichever of the three stream shapes arrived', function (): void {
        $android = ga4()->dataStream([
            'name' => 'properties/1/dataStreams/2',
            'type' => 'ANDROID_APP_DATA_STREAM',
            'displayName' => 'Android',
            'androidAppStreamData' => ['packageName' => 'sa.noonretail.shop'],
        ]);

        $ios = ga4()->dataStream([
            'name' => 'properties/1/dataStreams/3',
            'type' => 'IOS_APP_DATA_STREAM',
            'displayName' => 'iOS',
            'iosAppStreamData' => ['bundleId' => 'sa.noonretail.shop'],
        ]);

        // The sub-objects are mutually exclusive, so a caller should not have to know which to
        // look in to answer "what is this stream for?".
        expect($android->appIdentifier)->toBe('sa.noonretail.shop')
            ->and($android->isWeb())->toBeFalse()
            ->and($ios->appIdentifier)->toBe('sa.noonretail.shop')
            ->and($android->measurementId)->toBeNull();
    });
});

describe('key events', function (): void {
    it('reads a key event as a candidate, carrying how GA4 counts it', function (): void {
        $event = ga4()->keyEvent([
            'name' => 'properties/1/keyEvents/2',
            'eventName' => 'generate_lead',
            'custom' => true,
            'countingMethod' => 'ONCE_PER_SESSION',
        ]);

        /*
         * Counted per session, two leads in one visit are one key event. Surfaced so nobody
         * reconciles it against a CRM count and concludes the tracking is broken.
         */
        expect($event->eventName)->toBe('generate_lead')
            ->and($event->isCustom)->toBeTrue()
            ->and($event->isCountedOncePerSession())->toBeTrue();
    });

    it('carries a default value as a decimal string, not a float', function (): void {
        $event = ga4()->keyEvent([
            'eventName' => 'whatsapp_click',
            'countingMethod' => 'ONCE_PER_EVENT',
            'defaultValue' => ['numericValue' => 35.5, 'currencyCode' => 'SAR'],
        ]);

        // Money that has been through a float is money that no longer reconciles.
        expect($event->defaultValue)->toBe('35.5000')
            ->and($event->defaultCurrency)->toBe('SAR')
            ->and($event->isCountedOncePerSession())->toBeFalse();
    });

    it('reports no default value when GA4 sent none', function (): void {
        $event = ga4()->keyEvent(['eventName' => 'purchase']);

        expect($event->defaultValue)->toBeNull()
            ->and($event->defaultCurrency)->toBeNull()
            ->and($event->isCustom)->toBeFalse();
    });
});
