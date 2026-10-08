<?php

declare(strict_types=1);

use App\Domain\Portal\AlwaysAvailable;
use App\Domain\Portal\PortalModule;
use App\Domain\Portal\PortalState;
use App\Domain\Portal\WithheldModule;

/*
|--------------------------------------------------------------------------
| The client portal's vocabulary
|--------------------------------------------------------------------------
|
| Two enums with one job between them: a closed list of what an agency may expose to a client, and a closed list of
| what this product never will, each with its reason. The tests here hold them apart and hold them complete.
|
*/

describe('PortalModule', function (): void {
    it('is a closed list of four surfaces', function (): void {
        /*
         * Pinned deliberately. A portal built the other way round — every surface available, each behind a toggle
         * — makes exposing the agency's own reasoning a configuration mistake rather than an impossibility, and a
         * new case appearing here without a reader noticing is exactly that.
         */
        expect(PortalModule::values())
            ->toBe(['performance', 'reports', 'store_revenue', 'tracking']);
    });

    it('names what has to exist before a module can show anything', function (): void {
        expect(PortalModule::Performance->prerequisite())->toBe('a project bound to an advertising account');
        expect(PortalModule::StoreRevenue->prerequisite())->toBe('a connected store');
        expect(PortalModule::Tracking->prerequisite())->toBe('a measurement source');

        /* Nothing: a report is a document the agency already wrote for this client. */
        expect(PortalModule::Reports->prerequisite())->toBeNull();
    });

    it('describes every module in words a client would read', function (): void {
        foreach (PortalModule::cases() as $module) {
            expect($module->label())->not->toBe('');
            expect($module->description())->not->toBe('');
        }
    });
});

describe('WithheldModule', function (): void {
    it('gives a reason for every surface it refuses', function (): void {
        foreach (WithheldModule::cases() as $module) {
            expect($module->label())->not->toBe('');
            /*
             * The reason is the whole point of the enum: "not implemented yet" and "deliberately never" are
             * different answers, and only one of them should be given twice.
             */
            expect($module->because())->not->toBe('');
        }
    });

    it('does not overlap with what can be exposed', function (): void {
        /*
         * The guard that matters. A surface appearing in both lists would be a product that exposes something it
         * also publishes a refusal for, and whichever list the caller happened to read would decide the answer.
         */
        expect(array_intersect(WithheldModule::values(), PortalModule::values()))->toBe([]);
    });
});

describe('AlwaysAvailable', function (): void {
    it('is not a setting, and says why', function (): void {
        expect(AlwaysAvailable::values())->toBe(['requests']);

        /*
         * The client's own intake thread. A switch here would either control nothing, or let an agency withhold
         * the answer to a question the client themselves asked — so there is no switch, and the sentence saying
         * so travels with the surface.
         */
        expect(AlwaysAvailable::Requests->because())->toContain('entitled to read a conversation');
    });

    it('overlaps with neither of the other two lists', function (): void {
        expect(array_intersect(AlwaysAvailable::values(), PortalModule::values()))->toBe([]);
        expect(array_intersect(AlwaysAvailable::values(), WithheldModule::values()))->toBe([]);
    });
});

describe('PortalState', function (): void {
    it('only lets an active portal be read', function (): void {
        expect(PortalState::Active->allowsAccess())->toBeTrue();
        expect(PortalState::Suspended->allowsAccess())->toBeFalse();
        /* A decision nobody has taken is not a refusal, and it is not access either. */
        expect(PortalState::NotConfigured->allowsAccess())->toBeFalse();
    });

    it('tells a client why their portal will not open, without saying why it was switched off', function (): void {
        $suspended = PortalState::Suspended->visitorMessage();

        expect($suspended)->toContain('contact your account manager');

        /*
         * The agency's own note — "payment overdue", "contract under review" — is not in it. A client reading a
         * commercial position on a sign-in screen is that conversation happening in the worst possible place.
         */
        expect($suspended)->not->toContain('payment');
        expect($suspended)->not->toContain('overdue');

        /* And never "not found", which reads as a broken site rather than as a decision. */
        foreach (PortalState::cases() as $state) {
            expect($state->visitorMessage() ?? '')->not->toContain('not found');
        }

        expect(PortalState::Active->visitorMessage())->toBeNull();
    });

    it('distinguishes a portal nobody set up from one somebody switched off', function (): void {
        /*
         * The same distinction the reporting module makes between "no comparison requested" and "a comparison
         * that cannot be made": one is a decision nobody has taken, the other is a decision somebody took.
         */
        expect(PortalState::NotConfigured->visitorMessage())
            ->not->toBe(PortalState::Suspended->visitorMessage());
        expect(PortalState::NotConfigured->visitorMessage())->toContain('has not been set up');
    });
});
