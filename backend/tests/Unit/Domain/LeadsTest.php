<?php

declare(strict_types=1);

use App\Domain\Leads\ContactPoint;
use App\Domain\Leads\LeadFunnel;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;

/*
|--------------------------------------------------------------------------
| Leads
|--------------------------------------------------------------------------
|
| Two things are tested here above all: that a click can never become a lead, and that every rate has a
| denominator somebody could defend. Both have an obvious wrong version, and the wrong versions are what
| agencies report.
|
*/

describe('a lead is a completed action', function (): void {
    it('has no source kind for a click or a form view', function (): void {
        $values = LeadSourceKind::values();

        /*
         * The rule enforced by absence rather than by a check somebody could forget. An agency reporting
         * WhatsApp clicks as leads reports a cost per lead a fraction of the real one, wins the next month's
         * budget on it, and cannot explain afterwards why the client's sales did not move.
         */
        expect($values)->not->toContain('whatsapp_click')
            ->and($values)->not->toContain('phone_click')
            ->and($values)->not->toContain('form_start')
            ->and($values)->not->toContain('form_view')
            // What it does have is the completed actions.
            ->and($values)->toContain('whatsapp_message')
            ->and($values)->toContain('form_submission')
            ->and($values)->toContain('phone_call');
    });

    it('marks a hand-entered lead as not measured', function (): void {
        /*
         * A manual entry and an import are real leads and must not be presented as campaign results: nobody
         * knows where they came from, and attributing them would be attributing a guess.
         */
        expect(LeadSourceKind::Manual->isMeasured())->toBeFalse()
            ->and(LeadSourceKind::Import->isMeasured())->toBeFalse()
            ->and(LeadSourceKind::FormSubmission->isMeasured())->toBeTrue()
            ->and(LeadSourceKind::WhatsAppMessage->isMeasured())->toBeTrue();
    });
});

describe('stages', function (): void {
    it('treats only won, lost and disqualified as closed', function (): void {
        /*
         * The denominator of a win rate. A qualified lead is still in play, and counting it as closed would
         * make a healthy pipeline look like a pile of losses.
         */
        expect(LeadStage::Won->isClosed())->toBeTrue()
            ->and(LeadStage::Lost->isClosed())->toBeTrue()
            ->and(LeadStage::Disqualified->isClosed())->toBeTrue()
            ->and(LeadStage::New->isClosed())->toBeFalse()
            ->and(LeadStage::Contacted->isClosed())->toBeFalse()
            ->and(LeadStage::Qualified->isClosed())->toBeFalse();
    });

    it('distinguishes having tried from having got through', function (): void {
        /*
         * `contacted` is an attempt. Counting an attempt as contact is how a client comes to believe their
         * leads were worked when somebody left three voicemails.
         */
        expect(LeadStage::Contacted->isReached())->toBeFalse()
            ->and(LeadStage::Qualified->isReached())->toBeTrue()
            ->and(LeadStage::Disqualified->isReached())->toBeTrue()
            ->and(LeadStage::Won->isReached())->toBeTrue()
            ->and(LeadStage::New->isReached())->toBeFalse();
    });
});

describe('rates with denominators that exist', function (): void {
    /** @param array<string, int> $overrides */
    function funnel(array $overrides = []): LeadFunnel
    {
        return new LeadFunnel(
            total: $overrides['total'] ?? 100,
            contacted: $overrides['contacted'] ?? 20,
            reached: $overrides['reached'] ?? 50,
            qualified: $overrides['qualified'] ?? 30,
            disqualified: $overrides['disqualified'] ?? 5,
            won: $overrides['won'] ?? 10,
            lost: $overrides['lost'] ?? 5,
            open: $overrides['open'] ?? 80,
            wonValueMinor: $overrides['wonValueMinor'] ?? 500_000,
            openValueMinor: $overrides['openValueMinor'] ?? 0,
        );
    }

    it('divides wins by closed leads, not by every lead', function (): void {
        $f = funnel(['won' => 10, 'lost' => 5, 'disqualified' => 5, 'total' => 100]);

        /*
         * 10 of 20 closed, not 10 of 100. The second figure would call a pipeline a fortnight old a disaster
         * — it is mostly open — and that arithmetic has sunk more agency reports than any other mistake in
         * this area.
         */
        expect($f->closed())->toBe(20)
            ->and($f->winRate())->toBe(50.0);
    });

    it('divides qualified by leads somebody actually reached', function (): void {
        $f = funnel(['qualified' => 30, 'reached' => 50, 'total' => 100]);

        // A lead nobody has phoned yet has not failed to qualify.
        expect($f->qualificationRate())->toBe(60.0);
    });

    it('reports how many arriving leads somebody tried to contact', function (): void {
        $f = funnel(['contacted' => 20, 'reached' => 50, 'total' => 100]);

        /*
         * The operational figure, and the one that says a client is paying for leads nobody rings before the
         * win rate does.
         */
        expect($f->contactRate())->toBe(70.0);
    });

    it('gives null, not zero, for every rate when there is nothing to divide by', function (): void {
        $empty = new LeadFunnel(
            total: 0, contacted: 0, reached: 0, qualified: 0,
            disqualified: 0, won: 0, lost: 0, open: 0,
        );

        /*
         * `0%` and "nothing to measure" look identical on a card and mean opposite things. The first invites a
         * comparison with last month; the second does not.
         */
        expect($empty->winRate())->toBeNull()
            ->and($empty->qualificationRate())->toBeNull()
            ->and($empty->contactRate())->toBeNull()
            ->and($empty->averageWonValueMinor())->toBeNull();
    });

    it('gives no win rate for a pipeline where nothing has closed', function (): void {
        $young = funnel(['won' => 0, 'lost' => 0, 'disqualified' => 0, 'total' => 40, 'open' => 40]);

        /*
         * Forty leads, none finished. A zero here would read as "we win nothing", which is the opposite of
         * what a young pipeline means.
         */
        expect($young->closed())->toBe(0)
            ->and($young->winRate())->toBeNull();
    });

    it('averages won value over won leads only', function (): void {
        $f = funnel(['won' => 4, 'wonValueMinor' => 400_000]);

        expect($f->averageWonValueMinor())->toBe(100_000);
    });

    it('gives no average won value when none of the wins carried one', function (): void {
        $f = funnel(['won' => 4, 'wonValueMinor' => 0]);

        // Dividing zero by four is zero, which would read as "our wins are worth nothing".
        expect($f->averageWonValueMinor())->toBeNull();
    });
});

describe('recognising the same person twice', function (): void {
    it('treats the four ways a Saudi number is written as one number', function (): void {
        $forms = ['+966555001122', '00966555001122', '0555001122', '555001122', '+966 55 500 1122'];
        $keys = array_map(
            static fn (string $phone): ?string => ContactPoint::of(null, $phone)->phone,
            $forms,
        );

        /*
         * An agency that counts these as five leads reports five times the volume at a fifth of the cost. The
         * leading zero is a national trunk prefix rather than part of the number, which is why dropping it is
         * right here and would be wrong for a country that keeps one.
         */
        expect(array_unique($keys))->toHaveCount(1)
            ->and($keys[0])->toBe('966555001122');
    });

    it('lower-cases an e-mail and nothing more', function (): void {
        /*
         * Deliberately no Gmail dot-stripping: treating `a.b@gmail.com` and `ab@gmail.com` as one person is
         * right for Gmail and wrong for a server that distinguishes them, and merging two real people is worse
         * than counting one twice.
         */
        expect(ContactPoint::of('Layla@Example.test', null)->email)->toBe('layla@example.test')
            ->and(ContactPoint::of('a.b@gmail.com', null)->email)->toBe('a.b@gmail.com');
    });

    it('refuses something too short to be a phone number', function (): void {
        // A three-digit "phone" would match every other three-digit mistake and merge unrelated people.
        expect(ContactPoint::of(null, '123')->phone)->toBeNull()
            ->and(ContactPoint::of(null, 'n/a')->phone)->toBeNull();
    });

    it('refuses something that is not an e-mail address', function (): void {
        expect(ContactPoint::of('not-an-address', null)->email)->toBeNull()
            ->and(ContactPoint::of('   ', null)->email)->toBeNull();
    });

    it('prefers the phone as the matching key', function (): void {
        $contact = ContactPoint::of('layla@example.test', '0555001122');

        /*
         * In this market the phone is the identifier people actually give, and give consistently. An e-mail is
         * often typed differently or skipped entirely.
         */
        expect($contact->matchKey())->toBe('966555001122')
            ->and($contact->isReachable())->toBeTrue();
    });

    it('says plainly when there is no way to reach somebody', function (): void {
        $contact = ContactPoint::of(null, null);

        expect($contact->isReachable())->toBeFalse()
            ->and($contact->matchKey())->toBeNull();
    });
});
