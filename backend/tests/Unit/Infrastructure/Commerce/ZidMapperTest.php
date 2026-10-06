<?php

declare(strict_types=1);

use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\PaymentState;
use App\Infrastructure\Commerce\Mapper\ZidMapper;

/*
|--------------------------------------------------------------------------
| Zid mapping
|--------------------------------------------------------------------------
|
| The second store platform, and the one that tests whether the normalised domain was secretly
| Salla-shaped. It is not: Zid publishes payment and fulfilment as two separate fields, which is
| exactly the shape Phase 11 chose before either provider was written.
|
| What is new here is a merchant's ability to define custom order statuses, so a code this product has
| never seen is an ordinary occurrence rather than a defect.
|
*/

function zid(): ZidMapper
{
    return new ZidMapper;
}

/**
 * A Zid order payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function zidOrder(array $overrides = []): array
{
    return [
        'id' => 8810001,
        'code' => 'RW-5501',
        'created_at' => '2026-06-05 13:10:00',
        'updated_at' => '2026-06-05 18:00:00',
        'order_status' => ['name' => 'Delivered', 'code' => 'delivered'],
        'payment_status' => 'paid',
        'currency_code' => 'SAR',
        'order_total' => '391.00',
        'order_total_string' => '391.00 ر.س',
        'customer' => ['id' => 9910001, 'name' => 'نورة السبيعي'],
        'products' => [],
        ...$overrides,
    ];
}

describe('Zid states both axes, so neither is inferred', function (): void {
    it('reads the payment status Zid publishes', function (): void {
        /*
         * The happy case worth naming: where Salla needed a documented boolean to recover the payment
         * axis from a workflow status, Zid simply states it. No inference at all.
         */
        $cases = [
            'paid' => PaymentState::Paid,
            'pending' => PaymentState::Pending,
            'refunded' => PaymentState::Refunded,
            'voided' => PaymentState::Voided,
        ];

        foreach ($cases as $status => $expected) {
            expect(zid()->order(zidOrder(['payment_status' => $status]))->paymentState)
                ->toBe($expected, "payment_status {$status}");
        }
    });

    it('maps every documented order status onto a fulfilment state', function (): void {
        $cases = [
            'new' => FulfilmentState::Unfulfilled,
            'preparing' => FulfilmentState::Processing,
            // Ready to go out is not gone.
            'ready' => FulfilmentState::Processing,
            'indelivery' => FulfilmentState::Shipped,
            'delivered' => FulfilmentState::Delivered,
            'cancelled' => FulfilmentState::Cancelled,
            'reversed' => FulfilmentState::Returned,
            'partially_reversed' => FulfilmentState::Returned,
        ];

        foreach ($cases as $code => $expected) {
            expect(zid()->order(zidOrder(['order_status' => ['code' => $code]]))->fulfilmentState)
                ->toBe($expected, "order_status {$code}");
        }
    });

    it('treats a reversal in progress as still moving, not as settled', function (): void {
        $order = zid()->order(zidOrder(['order_status' => ['code' => 'reverse_in_progress']]));

        /*
         * `Returned` is a settled state, and a settled state tells the sync to stop re-reading an order
         * that is still changing. The exact code survives in `raw_fulfilment_status`, so nothing is lost
         * by refusing to round it up.
         */
        expect($order->fulfilmentState)->toBe(FulfilmentState::Processing)
            ->and($order->fulfilmentState->isSettled())->toBeFalse()
            ->and($order->rawFulfilmentStatus)->toBe('reverse_in_progress');
    });

    it('keeps both of Zid\'s own strings on the record', function (): void {
        $order = zid()->order(zidOrder(['payment_status' => 'pending', 'order_status' => ['code' => 'indelivery']]));

        expect($order->rawPaymentStatus)->toBe('pending')
            ->and($order->rawFulfilmentStatus)->toBe('indelivery');
    });

    it('lets a credit note outrank a paid status', function (): void {
        $order = zid()->order(zidOrder([
            'payment_status' => 'paid',
            'order_status' => ['code' => 'reversed'],
            'credit_notes' => [['id' => 66001, 'amount' => '391.00', 'created_at' => '2026-06-09 10:00:00']],
        ]));

        /*
         * Zid records a reversal as a credit note and can leave the payment status alone. A financial
         * fact outranks a label, and a part refund has to stay distinguishable from a whole one.
         */
        expect($order->paymentState)->toBe(PaymentState::Refunded)
            ->and($order->refundedMinor)->toBe(39_100)
            ->and($order->refunds)->toHaveCount(1)
            ->and($order->refunds[0]->reason)->toBeNull();
    });

    it('calls a part credit note a part refund', function (): void {
        $order = zid()->order(zidOrder([
            'credit_notes' => [['id' => 1, 'amount' => '100.00', 'created_at' => '2026-06-09 10:00:00']],
        ]));

        expect($order->paymentState)->toBe(PaymentState::PartiallyRefunded)
            ->and($order->paymentState->recognisesRevenue())->toBeTrue();
    });
});

describe('a status the merchant invented', function (): void {
    it('does not guess what a custom status means', function (): void {
        $order = zid()->order(zidOrder([
            // A real possibility: Zid lets a merchant define its own codes, in Arabic or otherwise.
            'order_status' => ['name' => 'تم التحقق يدويًا', 'code' => 'manual_verified'],
            'payment_status' => 'paid',
        ]));

        /*
         * `Processing` claims only that the order is in progress — and, more usefully, it is **not
         * settled**, so the sync keeps re-reading the order until it reaches a state this product
         * understands. A state we cannot interpret should keep us looking, not stop us.
         *
         * The payment axis is unaffected, because Zid stated it outright.
         */
        expect($order->fulfilmentState)->toBe(FulfilmentState::Processing)
            ->and($order->fulfilmentState->isSettled())->toBeFalse()
            ->and($order->paymentState)->toBe(PaymentState::Paid)
            // And the merchant's own code is on the record for a person to read.
            ->and($order->rawFulfilmentStatus)->toBe('manual_verified');
    });

    it('falls back to pending when Zid sends no payment status at all', function (): void {
        $order = zid()->order(zidOrder(['payment_status' => null, 'order_status' => ['code' => 'preparing']]));

        // Understating gets investigated; overstating gets sent to the client.
        expect($order->paymentState)->toBe(PaymentState::Pending);
    });

    it('voids an order cancelled with no payment status', function (): void {
        $order = zid()->order(zidOrder(['payment_status' => null, 'order_status' => ['code' => 'cancelled']]));

        expect($order->paymentState)->toBe(PaymentState::Voided)
            ->and($order->fulfilmentState)->toBe(FulfilmentState::Cancelled);
    });
});

describe('money', function (): void {
    it('reads the numeric total, not the formatted one', function (): void {
        $order = zid()->order(zidOrder([
            'order_total' => '391.00',
            'order_total_string' => '٣٩١٫٠٠ ر.س',
            'products_total' => '340.00',
            'shipping_cost' => '25.00',
            'tax_amount' => '26.00',
        ]));

        /*
         * `order_total_string` is formatted for display — currency symbol, separators, Arabic digits
         * where the locale asks for them. Parsing it would work until a store switched language.
         */
        expect($order->totalMinor)->toBe(39_100)
            ->and($order->subtotalMinor)->toBe(34_000)
            ->and($order->shippingMinor)->toBe(2_500)
            ->and($order->taxMinor)->toBe(2_600)
            ->and($order->currency)->toBe('SAR');
    });

    it('reports an absent breakdown as null', function (): void {
        $order = zid()->order(zidOrder());

        expect($order->totalMinor)->toBe(39_100)
            ->and($order->subtotalMinor)->toBeNull()
            ->and($order->shippingMinor)->toBeNull();
    });

    it('accepts an amount as a number as well as a string', function (): void {
        expect(zid()->order(zidOrder(['order_total' => 391]))->totalMinor)->toBe(39_100);
    });
});

describe('lines', function (): void {
    it('reads the products Zid includes only in the detailed payload', function (): void {
        $order = zid()->order(zidOrder([
            'products' => [[
                'id' => 77001,
                'product_id' => 5520001,
                'variant_id' => 5520011,
                'name' => 'مفرش قطن',
                'sku' => 'BED-001-K',
                'quantity' => 2,
                'price' => '340.00',
                'total' => '680.00',
            ]],
        ]));

        expect($order->items)->toHaveCount(1)
            ->and($order->items[0]->unitPriceMinor)->toBe(34_000)
            ->and($order->items[0]->totalMinor)->toBe(68_000)
            ->and($order->items[0]->productExternalId)->toBe('5520001')
            ->and($order->items[0]->variantExternalId)->toBe('5520011');
    });

    it('treats an order with no lines as ordinary, because the simple payload omits them', function (): void {
        /*
         * `payload_type` defaults to `simple`, which carries no products at all. The adapter asks for
         * `detailed` precisely so this is not the normal case — but a payload without them is not
         * malformed.
         */
        expect(zid()->order(zidOrder(['products' => []]))->items)->toBe([]);
    });
});

describe('attribution', function (): void {
    it('reads a full UTM set from the source block', function (): void {
        $order = zid()->order(zidOrder([
            'source' => ['utm_source' => 'tiktok', 'utm_medium' => 'paid_social', 'utm_campaign' => 'ramadan-home'],
        ]));

        expect($order->attribution->evidence)->toBe(AttributionEvidence::UtmParameters)
            ->and($order->attribution->campaign)->toBe('ramadan-home')
            ->and($order->attribution->confidence())->toBe(80);
    });

    it('reads a coupon from its own block', function (): void {
        $order = zid()->order(zidOrder(['coupon' => ['code' => 'HOME15']]));

        expect($order->attribution->evidence)->toBe(AttributionEvidence::CouponCode)
            ->and($order->attribution->couponCode)->toBe('HOME15');
    });

    it('says unattributed when the order carried nothing', function (): void {
        expect(zid()->order(zidOrder())->attribution->evidence)->toBe(AttributionEvidence::None);
    });

    it('reaches the same verdict as the other platform from the same facts', function (): void {
        /*
         * The point of deriving attribution in the domain rather than per provider. A Zid order and a
         * Salla order carrying the same parameters must be attributed identically, or a cross-platform
         * report is comparing two different rules.
         */
        $zidOrder = zid()->order(zidOrder([
            'source' => ['utm_source' => 'meta', 'utm_campaign' => 'eid-launch', 'fbclid' => 'click-1'],
        ]));

        expect($zidOrder->attribution->evidence)->toBe(AttributionEvidence::ClickIdentifier)
            ->and($zidOrder->attribution->confidence())->toBe(95);
    });
});

describe('localised names', function (): void {
    it('prefers the Arabic name a merchant\'s team will recognise', function (): void {
        $product = zid()->product([
            'id' => 5520001,
            'name' => ['ar' => 'مفرش قطن', 'en' => 'Cotton bedspread'],
            'sku' => 'BED-001',
            'price' => 340,
            'currency_code' => 'SAR',
            'is_published' => true,
            'variants' => [],
        ]);

        /*
         * Zid returns localised fields as a map when `Accept-Language` is not pinned. These are Saudi
         * stores: the Arabic name is the one on the merchant's screen and in their conversations.
         */
        expect($product->name)->toBe('مفرش قطن')
            ->and($product->priceMinor)->toBe(34_000)
            ->and($product->status)->toBe('published');
    });

    it('accepts a plain string name too', function (): void {
        expect(zid()->product(['id' => 1, 'name' => 'Service fee', 'price' => 0])->name)->toBe('Service fee');
    });

    it('names a variant from its attributes when it has no name of its own', function (): void {
        $product = zid()->product([
            'id' => 5520001,
            'name' => 'Bedspread',
            'price' => 340,
            'variants' => [[
                'id' => 5520011,
                'sku' => 'BED-001-K',
                'price' => 340,
                'quantity' => 12,
                'attributes' => [['name' => ['en' => 'Size'], 'value' => ['ar' => 'كينج', 'en' => 'King']]],
            ]],
        ]);

        expect($product->variants[0]->name)->toBe('كينج')
            ->and($product->variants[0]->stock)->toBe(12);
    });

    it('distinguishes unlimited stock from none', function (): void {
        $product = zid()->product([
            'id' => 1,
            'name' => 'Candle',
            'price' => 95,
            'variants' => [
                ['id' => 1, 'quantity' => 0],
                // Zid's own flag for a variant it does not count.
                ['id' => 2, 'is_infinite' => true, 'quantity' => 0],
            ],
        ]);

        /*
         * Untracked stock is not a quantity, and only a real zero is a reason to stop advertising a
         * product. Reporting the unlimited one as zero would pause a campaign on a product that is
         * always available.
         */
        expect($product->variants[0]->stock)->toBe(0)
            ->and($product->variants[1]->stock)->toBeNull();
    });
});

describe('customers', function (): void {
    it('reads a customer from the customers endpoint', function (): void {
        $customer = zid()->customer([
            'id' => 9910001,
            'name' => 'نورة السبيعي',
            'email' => 'noura@example.test',
            'mobile' => '+966555111222',
            'city' => ['name' => 'Riyadh'],
            'country' => ['code' => 'SA'],
        ]);

        expect($customer->externalId)->toBe('9910001')
            ->and($customer->name)->toBe('نورة السبيعي')
            ->and($customer->phone)->toBe('+966555111222')
            ->and($customer->city)->toBe('Riyadh')
            ->and($customer->country)->toBe('SA');
    });

    it('builds a name from the parts when there is no full name', function (): void {
        expect(zid()->customer(['id' => 1, 'first_name' => 'Khalid', 'last_name' => 'Zahrani'])->name)
            ->toBe('Khalid Zahrani');
    });

    it('still reads the customer id from a marketplace order, which links to nothing', function (): void {
        $order = zid()->order(zidOrder([
            'is_marketplace_order' => true,
            'customer' => ['id' => 9910004, 'name' => '****', 'email' => 'masked@zid.sa', 'mobile' => '****'],
        ]));

        /*
         * Zid masks the name, e-mail and phone on a marketplace order. Nothing in this product writes a
         * customer from an order payload, so the placeholder has no route into the customers table — and
         * a marketplace buyer is not a store customer, so the id links to nothing. That is the truth,
         * and it is better than a fabricated e-mail sitting in a client's customer list, which nobody
         * could tell from a real one afterwards.
         */
        expect($order->customerExternalId)->toBe('9910004')
            ->and($order->totalMinor)->toBe(39_100);
    });
});
