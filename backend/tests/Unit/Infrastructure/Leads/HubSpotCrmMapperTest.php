<?php

declare(strict_types=1);

use App\Domain\Leads\CrmStageMeaning;
use App\Infrastructure\Leads\Mapper\HubSpotCrmMapper;

/*
|--------------------------------------------------------------------------
| HubSpot's JSON, read as HubSpot sends it
|--------------------------------------------------------------------------
|
| Three of HubSpot's conventions are each a defect waiting to be written, and the tests below are the three.
|
| Everything in `properties` is a **string** — `amount` as "48000.00", `probability` as "0.2", `isClosed` as
| "true". Code comparing `$metadata['isClosed'] === true` would find every stage open.
|
| An empty string is **not a zero**. A deal with no amount publishes `"amount": ""`, and reading that as 0.00
| would put a deal worth nothing into a revenue figure.
|
| And a stage's meaning is **never** read from its label.
|
*/

function mapper(): HubSpotCrmMapper
{
    return new HubSpotCrmMapper;
}

describe('pipelines', function (): void {
    it('reads string metadata as the booleans and decimals it stands for', function (): void {
        $pipelines = mapper()->pipelines([
            'results' => [[
                'id' => 'default',
                'label' => 'Sales pipeline',
                'displayOrder' => 0,
                'archived' => false,
                'stages' => [
                    [
                        'id' => 'closedwon',
                        'label' => 'Closed won',
                        'displayOrder' => 2,
                        'metadata' => ['isClosed' => 'true', 'probability' => '1.0'],
                    ],
                ],
            ]],
        ]);

        $stage = $pipelines[0]->stages[0];

        /* "true" and "1.0" are strings on the wire. A strict comparison against a boolean finds every stage open. */
        expect($stage->isClosed)->toBeTrue();
        expect($stage->probability)->toBe(1.0);
        expect($stage->meaning)->toBe(CrmStageMeaning::Won);
    });

    it('reports a stage called "Won" as open when its metadata says it is', function (): void {
        $pipelines = mapper()->pipelines([
            'results' => [[
                'id' => 'default',
                'label' => 'Sales pipeline',
                'displayOrder' => 0,
                'stages' => [
                    [
                        'id' => '1284920',
                        'label' => 'Won',
                        'displayOrder' => 4,
                        'metadata' => ['isClosed' => 'false', 'probability' => '0.9'],
                    ],
                ],
            ]],
        ]);

        $stage = $pipelines[0]->stages[0];

        /*
         * The case the whole bridge exists for. A real pipeline somebody built by hand has a stage called "Won"
         * that is not marked closed, and a product trusting the word would report a sale that has not happened.
         */
        expect($stage->label)->toBe('Won');
        expect($stage->meaning)->toBe(CrmStageMeaning::Open);
    });
});

describe('deals', function (): void {
    it('converts an amount to minor units and keeps its currency', function (): void {
        $deals = mapper()->deals([
            'results' => [[
                'id' => '27451900001',
                'properties' => [
                    'dealname' => 'Eid retainer',
                    'dealstage' => 'closedwon',
                    'pipeline' => 'default',
                    'amount' => '48000.00',
                    'deal_currency_code' => 'SAR',
                ],
                'createdAt' => '2026-09-20T07:12:00.000Z',
                'updatedAt' => '2026-10-07T10:04:00.000Z',
            ]],
        ]);

        expect($deals[0]->amountMinor)->toBe(4_800_000);
        expect($deals[0]->currency)->toBe('SAR');
        /* The internal id, which is what `dealstage` holds. The label lives on the stage. */
        expect($deals[0]->stageId)->toBe('closedwon');
    });

    it('reads a missing amount as nothing rather than as zero', function (): void {
        $deals = mapper()->deals([
            'results' => [[
                'id' => '27451900002',
                'properties' => [
                    'dealname' => 'Brand sprint',
                    'dealstage' => 'qualifiedtobuy',
                    'pipeline' => 'default',
                    'amount' => '',
                    'deal_currency_code' => 'SAR',
                ],
                'createdAt' => '2026-10-01T09:30:00.000Z',
                'updatedAt' => '2026-10-07T08:50:00.000Z',
            ]],
        ]);

        /*
         * A deal with no amount is ordinary — most are, until somebody quotes — and 0.00 would be a figure this
         * product invented. The currency goes with it: a currency on no amount is a label on nothing.
         */
        expect($deals[0]->amountMinor)->toBeNull();
        expect($deals[0]->currency)->toBeNull();
    });

    it('rounds a decimal amount rather than truncating it', function (): void {
        $deals = mapper()->deals([
            'results' => [[
                'id' => '3',
                'properties' => ['dealname' => 'x', 'dealstage' => 's', 'pipeline' => 'p', 'amount' => '12500.50'],
                'createdAt' => '2026-08-14T11:00:00.000Z',
                'updatedAt' => '2026-10-02T15:20:00.000Z',
            ]],
        ]);

        // 12500.50 × 100 is 1250050 and not 1250049, which float truncation would give.
        expect($deals[0]->amountMinor)->toBe(1_250_050);
    });

    it('refuses to build half a name out of half a contact', function (): void {
        $contacts = mapper()->contacts([
            ['id' => '1', 'properties' => ['firstname' => 'Omar', 'lastname' => null]],
            ['id' => '2', 'properties' => ['firstname' => null, 'lastname' => null, 'phone' => '+966500000002']],
        ]);

        expect($contacts[0]->name)->toBe('Omar');
        /* Null rather than a space. A record showing " " reads as a defect; one showing nothing reads as itself. */
        expect($contacts[1]->name)->toBeNull();
        expect($contacts[1]->phone)->toBe('+966500000002');
    });

    it('treats an unreadable date as absent rather than as now', function (): void {
        $deals = mapper()->deals([
            'results' => [[
                'id' => '4',
                'properties' => ['dealname' => 'x', 'dealstage' => 's', 'pipeline' => 'p', 'closedate' => 'soon'],
                'createdAt' => '2026-10-05T06:45:00.000Z',
                'updatedAt' => '2026-10-07T07:05:00.000Z',
            ]],
        ]);

        // Never "now", which would be a date this product invented and then measured things from.
        expect($deals[0]->closeDate)->toBeNull();
    });
});
