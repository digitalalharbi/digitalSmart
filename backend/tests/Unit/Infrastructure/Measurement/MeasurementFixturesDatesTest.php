<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Infrastructure\Measurement\Sandbox\MeasurementFixtures;

/*
| A CRM page carries ISO timestamps, not a GA4 date column. `_newest_days_ago` must still move them, or the sandbox
| pipeline ages out of every reporting period a month after the fixture was written — which is how the Lead Center's
| CRM case disappeared from the demo and failed end-to-end on every branch.
*/

it('moves a CRM page so its newest timestamp lands the stated number of days before today', function (): void {
    $fixtures = new MeasurementFixtures(dirname(__DIR__, 4).'/database/fixtures/providers', new DateTimeImmutable('2027-03-15 10:00:00'));

    $page = $fixtures->page(Provider::HubSpot, 'deals')['response'];
    /** @var list<array<string, mixed>> $deals */
    $deals = $page['results'];

    $modified = array_map(static fn (array $deal): string => (string) $deal['properties']['hs_lastmodifieddate'], $deals);
    rsort($modified);

    expect(substr($modified[0], 0, 10))->toBe('2027-03-14');
    // The time of day survives the shift.
    expect(substr($modified[0], 10))->toBe('T10:04:00.000Z');
    expect($page)->not->toHaveKey('_newest_days_ago');
});

it('keeps the gaps between records and leaves a missing date missing', function (): void {
    $then = new MeasurementFixtures(dirname(__DIR__, 4).'/database/fixtures/providers', new DateTimeImmutable('2026-10-08'));
    $later = new MeasurementFixtures(dirname(__DIR__, 4).'/database/fixtures/providers', new DateTimeImmutable('2027-01-08'));

    $a = $then->page(Provider::HubSpot, 'deals')['response']['results'];
    $b = $later->page(Provider::HubSpot, 'deals')['response']['results'];

    $span = static fn (array $deals): int => (int) (new DateTimeImmutable($deals[0]['properties']['createdate']))
        ->diff(new DateTimeImmutable($deals[1]['properties']['createdate']))->format('%r%a');

    expect($span($b))->toBe($span($a));
    expect($b[1]['properties']['closedate'])->toBeNull();
});
