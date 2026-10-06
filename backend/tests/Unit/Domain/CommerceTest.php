<?php

declare(strict_types=1);

use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;

/*
|--------------------------------------------------------------------------
| Commerce
|--------------------------------------------------------------------------
|
| The normalised shape every store platform is read into. Most of what matters here is about
| refusing to conclude things: which states count as revenue, what an order has to carry before it
| can be credited to a campaign, and which sources are allowed to speak for money.
|
*/

describe('payment and fulfilment are separate axes', function (): void {
    it('counts paid and partially refunded orders as revenue, and nothing else', function (): void {
        /*
         * `PartiallyRefunded` counts because the part that was kept is real money — the refund comes
         * off the amount, not off the order's existence. `Pending` does not: cash on delivery that
         * never arrives is not a sale, and counting it overstates every report in the week before
         * the courier comes back.
         */
        expect(PaymentState::Paid->recognisesRevenue())->toBeTrue()
            ->and(PaymentState::PartiallyRefunded->recognisesRevenue())->toBeTrue()
            ->and(PaymentState::Pending->recognisesRevenue())->toBeFalse()
            ->and(PaymentState::Authorised->recognisesRevenue())->toBeFalse()
            ->and(PaymentState::Unpaid->recognisesRevenue())->toBeFalse()
            ->and(PaymentState::Refunded->recognisesRevenue())->toBeFalse()
            ->and(PaymentState::Voided->recognisesRevenue())->toBeFalse();
    });

    it('knows which orders are still waiting for money', function (): void {
        // The gap between an order and a sale, which an operations view needs and a revenue view
        // must exclude.
        expect(PaymentState::Pending->isAwaitingPayment())->toBeTrue()
            ->and(PaymentState::Authorised->isAwaitingPayment())->toBeTrue()
            ->and(PaymentState::Paid->isAwaitingPayment())->toBeFalse();
    });

    it('lets an order be delivered and refunded at once', function (): void {
        /*
         * The reason the two axes are separate columns. A single `status` makes this combination
         * unrepresentable, and it is an entirely ordinary thing for a shop: the customer received
         * the goods, complained, and got their money back.
         */
        $payment = PaymentState::Refunded;
        $fulfilment = FulfilmentState::Delivered;

        expect($payment->recognisesRevenue())->toBeFalse()
            ->and($fulfilment->isSettled())->toBeTrue();
    });

    it('treats delivered, cancelled and returned orders as settled', function (): void {
        // What a sync no longer needs to re-read; everything else can still change.
        expect(FulfilmentState::Delivered->isSettled())->toBeTrue()
            ->and(FulfilmentState::Cancelled->isSettled())->toBeTrue()
            ->and(FulfilmentState::Returned->isSettled())->toBeTrue()
            ->and(FulfilmentState::Shipped->isSettled())->toBeFalse()
            ->and(FulfilmentState::Processing->isSettled())->toBeFalse();
    });
});

describe('who may speak for revenue', function (): void {
    it('treats only a store and a CRM as having invoiced the money', function (): void {
        /*
         * An ad platform reports what it believes it caused; an analytics property reports what a
         * browser said. Neither is an invoice, and adding either to a store's figure produces a
         * number nobody can defend and everybody will quote.
         */
        expect(RevenueSource::Salla->isInvoiced())->toBeTrue()
            ->and(RevenueSource::Zid->isInvoiced())->toBeTrue()
            ->and(RevenueSource::Crm->isInvoiced())->toBeTrue()
            ->and(RevenueSource::PlatformReported->isInvoiced())->toBeFalse()
            ->and(RevenueSource::GoogleAnalytics->isInvoiced())->toBeFalse()
            ->and(RevenueSource::Manual->isInvoiced())->toBeFalse();
    });

    it('marks attributed revenue as a model\'s output', function (): void {
        // So it can never be presented as actual, and a reader can see which it is without reading
        // the documentation.
        expect(RevenueSource::Attributed->isModelled())->toBeTrue()
            ->and(RevenueSource::Salla->isModelled())->toBeFalse()
            ->and(RevenueSource::Manual->isModelled())->toBeFalse();
    });
});

describe('what an order has to carry to name a campaign', function (): void {
    it('reads a click identifier as the strongest evidence', function (): void {
        $attribution = OrderAttribution::from(
            source: 'google',
            campaign: 'ramadan-retail',
            clickIdentifier: 'Cj0KCQiA_abc123',
        );

        /*
         * The platform generated it at the moment of the click, so its presence on the order means
         * that click reached the checkout. Everything else is weaker, and all the fields are kept
         * so a report can show the channel from the UTM while the link rests on the click id.
         */
        expect($attribution->evidence)->toBe(AttributionEvidence::ClickIdentifier)
            ->and($attribution->confidence())->toBe(95)
            ->and($attribution->campaignName())->toBe('ramadan-retail')
            ->and($attribution->canNameCampaign())->toBeTrue();
    });

    it('needs a campaign and a source before it will call it campaign parameters', function (): void {
        $full = OrderAttribution::from(source: 'meta', medium: 'cpc', campaign: 'eid-launch');
        $partial = OrderAttribution::from(source: 'meta', medium: 'cpc');

        /*
         * A source alone names a *channel*. A report that split revenue across a client's Meta
         * campaigns on the strength of `utm_source=meta` would be inventing the split, so the
         * weaker case is a different evidence level rather than the same one.
         */
        expect($full->evidence)->toBe(AttributionEvidence::UtmParameters)
            ->and($full->canNameCampaign())->toBeTrue()
            ->and($partial->evidence)->toBe(AttributionEvidence::PartialUtm)
            ->and($partial->canNameCampaign())->toBeFalse()
            ->and($partial->evidence->identifiesCampaign())->toBeFalse();
    });

    it('treats a coupon as weak evidence, below half', function (): void {
        $attribution = OrderAttribution::from(couponCode: 'RAMADAN20');

        /*
         * A code is typed by a person who may have had it from a friend, an influencer, a screenshot
         * or a coupon aggregator. Crediting full revenue to the campaign that minted it routinely
         * credits the wrong one, so the confidence says so out loud.
         */
        expect($attribution->evidence)->toBe(AttributionEvidence::CouponCode)
            ->and($attribution->confidence())->toBeLessThan(50)
            // It can still name something, which is why it is kept at all.
            ->and($attribution->campaignName())->toBe('RAMADAN20');
    });

    it('calls an order with nothing on it unattributed, and means it', function (): void {
        $attribution = OrderAttribution::from();

        /*
         * The most important case. Direct traffic, organic, a returning customer and a UTM lost to
         * an app's in-app browser all land here, and the honest report says "unattributed" rather
         * than dividing them among whichever campaigns happened to be running.
         */
        expect($attribution->evidence)->toBe(AttributionEvidence::None)
            ->and($attribution->confidence())->toBe(0)
            ->and($attribution->canNameCampaign())->toBeFalse()
            ->and($attribution->campaignName())->toBeNull();
    });

    it('does not accept an analytics tool\'s placeholder as a campaign name', function (): void {
        $attribution = OrderAttribution::from(source: '(not set)', campaign: '(none)');

        /*
         * `(not set)` is what a tool writes when it has nothing. Stored verbatim it becomes a
         * campaign literally called "(not set)" in a client's report — which is worse than a blank,
         * because it reads as a campaign somebody named badly.
         */
        expect($attribution->evidence)->toBe(AttributionEvidence::None)
            ->and($attribution->source)->toBeNull()
            ->and($attribution->campaign)->toBeNull();
    });

    it('ignores whitespace-only parameters', function (): void {
        expect(OrderAttribution::from(source: '   ', couponCode: ' ')->evidence)
            ->toBe(AttributionEvidence::None);
    });

    it('prefers a referrer to nothing, and ranks it last', function (): void {
        $attribution = OrderAttribution::from(referrer: 'https://blog.example.com/post');

        // Names a channel at best, and is lost entirely inside most mobile apps.
        expect($attribution->evidence)->toBe(AttributionEvidence::Referrer)
            ->and($attribution->confidence())->toBe(25)
            ->and($attribution->canNameCampaign())->toBeFalse();
    });

    it('ranks every evidence level in the order a person would defend', function (): void {
        $ranked = [
            AttributionEvidence::ClickIdentifier,
            AttributionEvidence::UtmParameters,
            AttributionEvidence::PartialUtm,
            AttributionEvidence::CouponCode,
            AttributionEvidence::Referrer,
            AttributionEvidence::None,
        ];

        $confidences = array_map(
            static fn (AttributionEvidence $evidence): int => $evidence->confidence(),
            $ranked,
        );

        $sorted = $confidences;
        rsort($sorted);

        // Stated as a property rather than six separate numbers, so adding a level cannot silently
        // land it out of order.
        expect($confidences)->toBe($sorted);
    });

    it('keeps every parameter it was given, whichever one wins', function (): void {
        $attribution = OrderAttribution::from(
            source: 'tiktok',
            medium: 'paid_social',
            campaign: 'eid-video',
            content: 'hook-b',
            term: 'abaya',
            clickIdentifier: 'ttclid-999',
            couponCode: 'EID10',
            referrer: 'https://tiktok.com',
        );

        expect($attribution->evidence)->toBe(AttributionEvidence::ClickIdentifier)
            ->and($attribution->medium)->toBe('paid_social')
            ->and($attribution->content)->toBe('hook-b')
            ->and($attribution->term)->toBe('abaya')
            ->and($attribution->couponCode)->toBe('EID10')
            ->and($attribution->referrer)->toBe('https://tiktok.com');
    });
});
