<?php

declare(strict_types=1);

use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\PaymentState;
use App\Infrastructure\Commerce\Mapper\SallaMapper;

/*
|--------------------------------------------------------------------------
| Salla mapping
|--------------------------------------------------------------------------
|
| Salla has one status axis and this product has two, so the mapping between them is the most
| consequential code in the integration. Each case below is tied to Salla's own documented
| description of the slug, and the cases it does not document are handled by a documented boolean
| rather than by a guess at what the name implies.
|
*/

function salla(): SallaMapper
{
    return new SallaMapper;
}

/**
 * A Salla order payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sallaOrder(array $overrides = []): array
{
    return [
        'id' => 760011001,
        'reference_id' => 41201,
        'date' => ['date' => '2026-06-05 14:22:11.000000', 'timezone_type' => 3, 'timezone' => 'Asia/Riyadh'],
        'status' => ['id' => 1, 'name' => 'Completed', 'slug' => 'completed'],
        'payment_method' => 'mada',
        'is_pending_payment' => false,
        'total' => ['amount' => 925.5, 'currency' => 'SAR'],
        'customer' => ['id' => 1842003311, 'full_name' => 'Layla Al-Harbi'],
        'items' => [],
        ...$overrides,
    ];
}

describe('one status axis onto two', function (): void {
    it('reads the statuses that state the payment outright', function (): void {
        /*
         * Each of these five slugs is documented as being about money, and the mapping quotes the
         * documentation. The other seven describe a parcel.
         */
        $cases = [
            'payment_pending' => PaymentState::Pending,
            'waiting_for_payment_confirmation' => PaymentState::Pending,
            'payment_failed' => PaymentState::Unpaid,
            'completed' => PaymentState::Paid,
            'canceled' => PaymentState::Voided,
        ];

        foreach ($cases as $slug => $expected) {
            $order = salla()->order(sallaOrder(['status' => ['slug' => $slug]]));

            expect($order->paymentState)->toBe($expected, "slug {$slug}");
        }
    });

    it('uses the documented pending-payment flag where the status says nothing about money', function (): void {
        /*
         * The case that matters in this market. `shipped` describes a parcel; whether the money has
         * arrived depends on whether it is cash on delivery, and Salla answers that with
         * `is_pending_payment` rather than through the status. Reading the flag is the difference
         * between a fact and a guess from a label.
         */
        $cod = salla()->order(sallaOrder([
            'status' => ['slug' => 'shipped'],
            'payment_method' => 'cod',
            'is_pending_payment' => true,
        ]));

        $prepaid = salla()->order(sallaOrder([
            'status' => ['slug' => 'shipped'],
            'payment_method' => 'mada',
            'is_pending_payment' => false,
        ]));

        expect($cod->paymentState)->toBe(PaymentState::Pending)
            ->and($cod->paymentState->recognisesRevenue())->toBeFalse()
            ->and($prepaid->paymentState)->toBe(PaymentState::Paid)
            ->and($prepaid->paymentState->recognisesRevenue())->toBeTrue()
            // Both are the same parcel state, which is the point of the two axes.
            ->and($cod->fulfilmentState)->toBe(FulfilmentState::Shipped)
            ->and($prepaid->fulfilmentState)->toBe(FulfilmentState::Shipped);
    });

    it('understates rather than overstates when the flag is absent', function (): void {
        $order = salla()->order(sallaOrder([
            'status' => ['slug' => 'in_progress'],
            'is_pending_payment' => null,
        ]));

        /*
         * The order list does not always carry the flag. `Pending` keeps the order out of revenue
         * until something says otherwise: an agency that finds its revenue figure low goes looking,
         * while one that finds it high has already sent the report.
         */
        expect($order->paymentState)->toBe(PaymentState::Pending);
    });

    it('lets a refund outrank the workflow status', function (): void {
        $order = salla()->order(sallaOrder([
            'status' => ['slug' => 'delivered'],
            'is_pending_payment' => false,
            'refunds' => [['id' => 1, 'amount' => ['amount' => 925.5, 'currency' => 'SAR'], 'date' => '2026-06-08 09:00:00']],
        ]));

        /*
         * Salla will happily show an order as delivered while the money has gone back. A financial
         * fact outranks a workflow label, and the fulfilment axis still says the goods arrived.
         */
        expect($order->paymentState)->toBe(PaymentState::Refunded)
            ->and($order->fulfilmentState)->toBe(FulfilmentState::Delivered)
            ->and($order->refundedMinor)->toBe(92_550);
    });

    it('calls a part refund a part refund', function (): void {
        $order = salla()->order(sallaOrder([
            'status' => ['slug' => 'delivered'],
            'refunds' => [['id' => 1, 'amount' => ['amount' => 100, 'currency' => 'SAR'], 'date' => '2026-06-08 09:00:00']],
        ]));

        // The kept part is real revenue, so the order still counts — net of what came back.
        expect($order->paymentState)->toBe(PaymentState::PartiallyRefunded)
            ->and($order->paymentState->recognisesRevenue())->toBeTrue();
    });

    it('maps every documented slug onto a fulfilment state', function (): void {
        $cases = [
            'payment_pending' => FulfilmentState::Unfulfilled,
            'waiting_for_payment_confirmation' => FulfilmentState::Unfulfilled,
            'payment_failed' => FulfilmentState::Unfulfilled,
            'under_review' => FulfilmentState::Processing,
            'in_progress' => FulfilmentState::Processing,
            'shipped' => FulfilmentState::Shipped,
            'delivering' => FulfilmentState::Shipped,
            'delivered' => FulfilmentState::Delivered,
            'completed' => FulfilmentState::Delivered,
            'canceled' => FulfilmentState::Cancelled,
            // "Restored back to the store after cancellation or issue" — the goods came back.
            'restored' => FulfilmentState::Returned,
            'restoring' => FulfilmentState::Returned,
        ];

        foreach ($cases as $slug => $expected) {
            expect(salla()->order(sallaOrder(['status' => ['slug' => $slug]]))->fulfilmentState)
                ->toBe($expected, "slug {$slug}");
        }
    });

    it('keeps Salla\'s own slug on the record', function (): void {
        $order = salla()->order(sallaOrder(['status' => ['slug' => 'delivering', 'name' => 'Delivering']]));

        /*
         * The first time a client's figures differ from their Salla dashboard, somebody will ask which
         * status this product read. The answer has to be on the record rather than reconstructed from
         * the mapper.
         */
        expect($order->rawPaymentStatus)->toBe('delivering')
            ->and($order->rawFulfilmentStatus)->toBe('delivering');
    });

    it('accepts a slug sent as a bare string, as webhooks do', function (): void {
        expect(salla()->order(sallaOrder(['status' => 'completed']))->paymentState)->toBe(PaymentState::Paid);
    });
});

describe('money', function (): void {
    it('reads the total and the breakdown into minor units', function (): void {
        $order = salla()->order(sallaOrder([
            'amounts' => [
                'sub_total' => ['amount' => 870, 'currency' => 'SAR'],
                'shipping_cost' => ['amount' => 25, 'currency' => 'SAR'],
                'tax' => ['percent' => '15%', 'amount' => ['amount' => 120.5, 'currency' => 'SAR']],
                'discounts' => [['code' => 'RAMADAN20', 'amount' => ['amount' => 90, 'currency' => 'SAR']]],
                'total' => ['amount' => 925.5, 'currency' => 'SAR'],
            ],
        ]));

        expect($order->totalMinor)->toBe(92_550)
            ->and($order->subtotalMinor)->toBe(87_000)
            ->and($order->shippingMinor)->toBe(2_500)
            // Salla nests the tax amount inside a percent/amount object.
            ->and($order->taxMinor)->toBe(12_050)
            // Summed into the single positive figure the schema holds.
            ->and($order->discountMinor)->toBe(9_000)
            ->and($order->currency)->toBe('SAR');
    });

    it('reports an absent breakdown as null, not zero', function (): void {
        /*
         * Salla's order *list* returns a total and nothing else. Zero would read as "nothing was
         * charged for shipping", and a subtotal report built on it would be confidently wrong rather
         * than visibly absent.
         */
        $order = salla()->order(sallaOrder());

        expect($order->totalMinor)->toBe(92_550)
            ->and($order->subtotalMinor)->toBeNull()
            ->and($order->shippingMinor)->toBeNull()
            ->and($order->taxMinor)->toBeNull();
    });

    it('stores a discount as a positive amount taken off', function (): void {
        $order = salla()->order(sallaOrder([
            'amounts' => [
                'discounts' => [
                    ['amount' => ['amount' => -50, 'currency' => 'SAR']],
                    ['amount' => ['amount' => 20, 'currency' => 'SAR']],
                ],
            ],
        ]));

        /*
         * Salla has been known to express a discount as a negative. Mixing the two conventions across
         * providers is how a discount comes to increase revenue, so both are normalised to a positive
         * amount that was taken off.
         */
        expect($order->discountMinor)->toBe(7_000);
    });
});

describe('time', function (): void {
    it('honours the store\'s time zone', function (): void {
        $order = salla()->order(sallaOrder([
            'date' => ['date' => '2026-06-05 01:30:00.000000', 'timezone_type' => 3, 'timezone' => 'Asia/Riyadh'],
        ]));

        /*
         * A store in Riyadh read as UTC would have three hours of every evening's sales land on the
         * previous day — which quietly moves revenue between months at a month boundary.
         */
        expect($order->placedAt->format('Y-m-d H:i'))->toBe('2026-06-05 01:30')
            ->and($order->placedAt->getTimezone()->getName())->toBe('Asia/Riyadh');
    });

    it('fills a payment time only when Salla reports one', function (): void {
        $without = salla()->order(sallaOrder());
        $with = salla()->order(sallaOrder(['payment_date' => '2026-06-05 14:30:00']));

        /*
         * Guessing the order date would put a payment before the money moved, and a cash-flow report
         * built on that is wrong by days.
         */
        expect($without->paidAt)->toBeNull()
            ->and($with->paidAt?->format('Y-m-d H:i'))->toBe('2026-06-05 14:30');
    });

    it('does not break on an unparseable date', function (): void {
        $order = salla()->order(sallaOrder(['date' => ['date' => 'not a date', 'timezone' => 'Asia/Riyadh']]));

        // A sync that threw on one bad row would lose the whole page. The epoch is obviously wrong,
        // which is better than plausibly wrong.
        expect($order->placedAt->format('Y'))->toBe('1970');
    });
});

describe('attribution', function (): void {
    it('reads a click identifier from the marketing block', function (): void {
        $order = salla()->order(sallaOrder([
            'marketing' => [
                'utm_source' => 'meta',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'eid-launch',
                'fbclid' => 'IwAR2sandboxclick',
            ],
        ]));

        expect($order->attribution->evidence)->toBe(AttributionEvidence::ClickIdentifier)
            ->and($order->attribution->campaign)->toBe('eid-launch')
            ->and($order->attribution->clickIdentifier)->toBe('IwAR2sandboxclick');
    });

    it('treats a coupon as the weak evidence it is', function (): void {
        $order = salla()->order(sallaOrder(['coupon' => 'RAMADAN20']));

        expect($order->attribution->evidence)->toBe(AttributionEvidence::CouponCode)
            ->and($order->attribution->couponCode)->toBe('RAMADAN20')
            ->and($order->attribution->confidence())->toBeLessThan(50);
    });

    it('finds a coupon inside the discounts block', function (): void {
        $order = salla()->order(sallaOrder([
            'amounts' => ['discounts' => [['code' => 'EID10', 'amount' => ['amount' => 10, 'currency' => 'SAR']]]],
        ]));

        expect($order->attribution->couponCode)->toBe('EID10');
    });

    it('says unattributed when the order carried nothing', function (): void {
        /*
         * The common case, and the one a dishonest implementation would fill in. A Salla order carries
         * UTM parameters only when the merchant's theme passes them through.
         */
        expect(salla()->order(sallaOrder())->attribution->evidence)->toBe(AttributionEvidence::None);
    });
});

describe('lines', function (): void {
    it('reads an item\'s price and total from the amounts block', function (): void {
        $order = salla()->order(sallaOrder([
            'items' => [[
                'id' => 990011,
                'name' => 'عباية كلاسيك',
                'sku' => 'ABA-CL-001-S',
                'quantity' => 2,
                'product' => ['id' => 2290114501],
                'amounts' => [
                    'price_without_tax' => ['amount' => 435, 'currency' => 'SAR'],
                    'total' => ['amount' => 870, 'currency' => 'SAR'],
                ],
            ]],
        ]));

        expect($order->items)->toHaveCount(1)
            ->and($order->items[0]->quantity)->toBe(2)
            ->and($order->items[0]->unitPriceMinor)->toBe(43_500)
            ->and($order->items[0]->totalMinor)->toBe(87_000)
            ->and($order->items[0]->productExternalId)->toBe('2290114501')
            ->and($order->items[0]->sku)->toBe('ABA-CL-001-S');
    });

    it('keeps a line the list endpoint gave no price for', function (): void {
        $order = salla()->order(sallaOrder([
            'items' => [['id' => 1, 'name' => 'شماغ قطن', 'quantity' => 3]],
        ]));

        /*
         * The list endpoint gives a name and a quantity and no price. The quantity is a fact worth
         * keeping; inventing a price from the order total would distribute money across lines that may
         * not have shared it that way.
         */
        expect($order->items)->toHaveCount(1)
            ->and($order->items[0]->quantity)->toBe(3)
            ->and($order->items[0]->unitPriceMinor)->toBe(0);
    });

    it('drops a line claiming nothing was sold', function (): void {
        $order = salla()->order(sallaOrder(['items' => [['name' => 'Ghost', 'quantity' => 0]]]));

        // The schema refuses a non-positive quantity, and a line selling nothing is not a line.
        expect($order->items)->toBe([]);
    });
});

describe('customers and products', function (): void {
    it('reads a mobile number as a string with its country code', function (): void {
        $customer = salla()->customer([
            'id' => 1842003311,
            'full_name' => 'Layla Al-Harbi',
            'email' => 'layla@example.test',
            'mobile' => 555000111,
            'mobile_code' => '+966',
            'country_code' => 'SA',
            'city' => 'Riyadh',
        ]);

        /*
         * Salla sends the mobile as an integer, which drops any leading zero. A phone number that has
         * been through an integer is a phone number that may no longer dial.
         */
        expect($customer->phone)->toBe('+966555000111')
            ->and($customer->country)->toBe('SA')
            ->and($customer->externalId)->toBe('1842003311');
    });

    it('builds a name from the parts when there is no full name', function (): void {
        $customer = salla()->customer(['id' => 1, 'first_name' => 'Omar', 'last_name' => 'Nasser']);

        expect($customer->name)->toBe('Omar Nasser');
    });

    it('reports a missing email as missing', function (): void {
        expect(salla()->customer(['id' => 1, 'email' => null])->email)->toBeNull();
    });

    it('reads a product with its variants, keeping untracked stock as null', function (): void {
        $product = salla()->product([
            'id' => 2290114501,
            'name' => 'عباية كلاسيك',
            'sku' => 'ABA-CL-001',
            'status' => 'sale',
            'price' => ['amount' => 450, 'currency' => 'SAR'],
            'skus' => [
                ['id' => 1, 'name' => 'S', 'price' => ['amount' => 450, 'currency' => 'SAR'], 'stock_quantity' => 6],
                ['id' => 2, 'name' => 'M', 'price' => ['amount' => 450, 'currency' => 'SAR'], 'stock_quantity' => 0],
                ['id' => 3, 'name' => 'L', 'price' => ['amount' => 475, 'currency' => 'SAR']],
            ],
        ]);

        expect($product->priceMinor)->toBe(45_000)
            ->and($product->variants)->toHaveCount(3)
            ->and($product->variants[0]->stock)->toBe(6)
            // Out of stock and not tracked are different facts, and only one is a reason to pause ads.
            ->and($product->variants[1]->stock)->toBe(0)
            ->and($product->variants[2]->stock)->toBeNull()
            ->and($product->variants[2]->priceMinor)->toBe(47_500);
    });
});
