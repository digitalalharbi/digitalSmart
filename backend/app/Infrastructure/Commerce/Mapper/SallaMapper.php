<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce\Mapper;

use App\Application\Commerce\Contract\StoreOrderMapper;
use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderItemRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\ProductRecord;
use App\Application\Commerce\DTO\RefundRecord;
use App\Application\Commerce\DTO\ShipmentRecord;
use App\Application\Commerce\DTO\StoreRecord;
use App\Application\Commerce\DTO\VariantRecord;
use App\Domain\Advertising\ValueObject\Money;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Salla Merchant API v2 responses to normalised records.
 *
 * Built against the published contracts at `https://api.salla.dev/admin/v2/`. Two things about Salla
 * shape this class.
 *
 * **Salla has one status axis, and this product has two.** Its documented slugs mix the questions:
 * `payment_pending` is about money, `shipped` is about a parcel, `completed` is about both, and
 * `in_progress` says nothing about payment at all. Mapping that single field onto both axes is the
 * most consequential thing here, and it is done from documented facts only — see `paymentState()`.
 *
 * **The order list carries no amount breakdown.** `GET /orders` returns `total: {amount, currency}`
 * and nothing else; the subtotal, shipping, tax and discounts live on the details endpoint. So a
 * mapped order from the list has a total and nulls, and null means "not reported" rather than zero.
 */
final readonly class SallaMapper implements StoreOrderMapper
{
    /**
     * Salla's documented order status slugs.
     *
     * Listed as constants because the mapping below turns on them exactly, and a typo in a string
     * literal would silently route an order to the default branch.
     */
    private const string PAYMENT_PENDING = 'payment_pending';

    private const string AWAITING_CONFIRMATION = 'waiting_for_payment_confirmation';

    private const string PAYMENT_FAILED = 'payment_failed';

    private const string IN_PROGRESS = 'in_progress';

    private const string UNDER_REVIEW = 'under_review';

    private const string COMPLETED = 'completed';

    private const string DELIVERING = 'delivering';

    private const string DELIVERED = 'delivered';

    private const string SHIPPED = 'shipped';

    private const string CANCELED = 'canceled';

    private const string RESTORED = 'restored';

    private const string RESTORING = 'restoring';

    /** @param array<string, mixed> $payload a store or merchant object */
    public function store(array $payload): StoreRecord
    {
        return new StoreRecord(
            externalId: (string) ($payload['id'] ?? ''),
            name: $this->string($payload, 'name') ?? 'Salla store',
            currency: strtoupper($this->string($payload, 'currency') ?? 'SAR'),
            domain: $this->string($payload, 'domain'),
            timezone: $this->string($payload, 'timezone'),
        );
    }

    /**
     * One order from `GET /orders` or `GET /orders/{id}`.
     *
     * Both shapes are accepted because the webhook payloads carry the detailed one and the list
     * carries the shallow one, and a second mapper for the same entity is how two code paths come to
     * disagree about what a status means.
     *
     * @param  array<string, mixed>  $payload
     */
    public function order(array $payload): OrderRecord
    {
        $slug = $this->statusSlug($payload);
        $currency = $this->orderCurrency($payload);
        $amounts = $this->nested($payload, 'amounts');

        return new OrderRecord(
            externalId: (string) ($payload['id'] ?? ''),
            paymentState: $this->paymentState($slug, $payload),
            fulfilmentState: $this->fulfilmentState($slug),
            currency: $currency,
            totalMinor: $this->amount($payload['total'] ?? $amounts['total'] ?? null, $currency) ?? 0,
            placedAt: $this->date($payload['date'] ?? null) ?? new DateTimeImmutable('@0'),
            attribution: $this->attribution($payload),
            // The number the customer and the merchant's staff both quote at each other.
            reference: $this->scalar($payload, 'reference_id'),
            /*
             * Salla's own slug, kept verbatim. The first time a client's figures differ from their
             * Salla dashboard, somebody will ask which status this product read — and the answer has
             * to be on the record rather than reconstructed from this file.
             */
            rawPaymentStatus: $slug,
            rawFulfilmentStatus: $slug,
            subtotalMinor: $this->amount($amounts['sub_total'] ?? null, $currency),
            discountMinor: $this->discount($amounts, $currency),
            shippingMinor: $this->amount(
                $this->nested($amounts, 'shipping_cost')['amount'] ?? $amounts['shipping_cost'] ?? null,
                $currency,
            ),
            taxMinor: $this->amount(
                $this->nested($this->nested($amounts, 'tax'), 'amount') ?: ($amounts['tax'] ?? null),
                $currency,
            ),
            refundedMinor: $this->refundedTotal($payload, $currency),
            paymentMethod: $this->string($payload, 'payment_method'),
            gateway: $this->string($payload, 'payment_method'),
            customerExternalId: $this->scalar($this->nested($payload, 'customer'), 'id'),
            paidAt: $this->paidAt($slug, $payload),
            cancelledAt: $slug === self::CANCELED ? $this->date($payload['date'] ?? null) : null,
            providerUpdatedAt: $this->date($payload['updated_at'] ?? null),
            items: $this->items($payload, $currency),
            refunds: $this->refunds($payload, $currency),
            shipments: $this->shipments($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    public function customer(array $payload): CustomerRecord
    {
        return new CustomerRecord(
            externalId: (string) ($payload['id'] ?? ''),
            name: $this->string($payload, 'full_name')
                ?? trim(($this->string($payload, 'first_name') ?? '').' '.($this->string($payload, 'last_name') ?? '')) ?: null,
            email: $this->string($payload, 'email'),
            /*
             * Salla sends the mobile as an integer, which drops any leading zero. Read as a string
             * and paired with the country code it sends alongside, because a phone number that has
             * been through an integer is a phone number that may no longer dial.
             */
            phone: $this->phone($payload),
            city: $this->string($payload, 'city'),
            country: $this->countryCode($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    public function product(array $payload): ProductRecord
    {
        $currency = strtoupper($this->string($this->nested($payload, 'price'), 'currency') ?? 'SAR');

        return new ProductRecord(
            externalId: (string) ($payload['id'] ?? ''),
            name: $this->string($payload, 'name') ?? 'Product',
            sku: $this->string($payload, 'sku'),
            status: $this->string($payload, 'status'),
            priceMinor: $this->amount($payload['price'] ?? null, $currency),
            currency: $currency,
            imageUrl: $this->string($this->nested($payload, 'main_image'), 'url')
                ?? $this->string($payload, 'thumbnail'),
            variants: $this->variants($payload, $currency),
        );
    }

    /**
     * Where the order stands financially.
     *
     * Salla's status says this outright for five of its twelve slugs, and the documentation is quoted
     * in each branch below. For the other seven — `in_progress`, `under_review`, `shipped`,
     * `delivering`, `delivered`, `restored`, `restoring` — the status describes a parcel and says
     * nothing about money, which matters enormously in a market where cash on delivery is normal: a
     * shipped COD order is not revenue, and a shipped prepaid order is.
     *
     * So those fall back to Salla's **documented `is_pending_payment` boolean** rather than to an
     * inference from the status name or the payment method. That field exists precisely to answer
     * this question, and using it is the difference between reading a fact and guessing from a label.
     *
     * When it is absent — the order list does not always carry it — the answer is `Pending`, which
     * understates rather than overstates. An agency that discovers its revenue figure is low goes
     * looking; one that discovers it is high has already sent the report.
     *
     * @param  array<string, mixed>  $payload
     */
    private function paymentState(string $slug, array $payload): PaymentState
    {
        $refunded = $this->refundedTotal($payload, $this->orderCurrency($payload));
        $total = $this->amount($payload['total'] ?? null, $this->orderCurrency($payload)) ?? 0;

        if ($refunded > 0) {
            // A refund is a financial fact that outranks the workflow status: Salla can show an order
            // as `delivered` while the money has gone back.
            return $refunded >= $total && $total > 0
                ? PaymentState::Refunded
                : PaymentState::PartiallyRefunded;
        }

        return match ($slug) {
            // "Payment is still pending from the customer and has not been received yet."
            self::PAYMENT_PENDING => PaymentState::Pending,
            // "Payment has been made by the customer and is awaiting confirmation from the merchant."
            self::AWAITING_CONFIRMATION => PaymentState::Pending,
            // "Payment attempt was unsuccessful and did not reach the merchant."
            self::PAYMENT_FAILED => PaymentState::Unpaid,
            // "Order has been successfully paid for and delivered to the customer."
            self::COMPLETED => PaymentState::Paid,
            // "Order has been canceled and will not be processed further."
            self::CANCELED => PaymentState::Voided,
            default => $this->paymentFromPendingFlag($payload),
        };
    }

    /** @param array<string, mixed> $payload */
    private function paymentFromPendingFlag(array $payload): PaymentState
    {
        $pending = $payload['is_pending_payment'] ?? null;

        if (is_bool($pending)) {
            return $pending ? PaymentState::Pending : PaymentState::Paid;
        }

        /*
         * Absent. `Pending` keeps the order out of revenue until something says otherwise, which is
         * the conservative direction: a figure that is too low gets investigated, a figure that is
         * too high gets sent to the client.
         */
        return PaymentState::Pending;
    }

    /** Where the order stands physically. Salla's status is clear about this for every slug. */
    private function fulfilmentState(string $slug): FulfilmentState
    {
        return match ($slug) {
            self::IN_PROGRESS, self::UNDER_REVIEW => FulfilmentState::Processing,
            self::SHIPPED, self::DELIVERING => FulfilmentState::Shipped,
            self::DELIVERED, self::COMPLETED => FulfilmentState::Delivered,
            self::CANCELED => FulfilmentState::Cancelled,
            // "Restored back to the store after cancellation or issue" — the goods came back.
            self::RESTORED, self::RESTORING => FulfilmentState::Returned,
            default => FulfilmentState::Unfulfilled,
        };
    }

    /**
     * What the order carried about where it came from.
     *
     * Salla exposes the coupon on the order. UTM parameters and click identifiers reach an order only
     * when the merchant's theme passes them through, so they are read from wherever they appear and
     * their absence is reported honestly as `none` rather than filled in.
     *
     * @param  array<string, mixed>  $payload
     */
    private function attribution(array $payload): OrderAttribution
    {
        $source = $this->nested($payload, 'source') ?: [];
        $marketing = $this->nested($payload, 'marketing') ?: [];

        return OrderAttribution::from(
            source: $this->string($marketing, 'utm_source') ?? $this->string($source, 'utm_source'),
            medium: $this->string($marketing, 'utm_medium') ?? $this->string($source, 'utm_medium'),
            campaign: $this->string($marketing, 'utm_campaign') ?? $this->string($source, 'utm_campaign'),
            content: $this->string($marketing, 'utm_content'),
            term: $this->string($marketing, 'utm_term'),
            clickIdentifier: $this->string($marketing, 'gclid') ?? $this->string($marketing, 'fbclid'),
            couponCode: $this->couponCode($payload),
            referrer: $this->string($marketing, 'referrer') ?? $this->string($source, 'referer'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function couponCode(array $payload): ?string
    {
        $direct = $this->string($payload, 'coupon');

        if ($direct !== null) {
            return $direct;
        }

        foreach ($this->listOf($this->nested($payload, 'amounts'), 'discounts') as $discount) {
            $code = $this->string($discount, 'code') ?? $this->string($discount, 'coupon');

            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The order's lines.
     *
     * Salla's list endpoint gives each item a name and a quantity and no price; the details endpoint
     * gives an `amounts` block. A line with no price is stored with a zero unit price and its real
     * quantity, because the quantity is a fact and inventing a price from the order total would
     * distribute money across lines that may not have shared it that way.
     *
     * @param  array<string, mixed>  $payload
     * @return list<OrderItemRecord>
     */
    private function items(array $payload, string $currency): array
    {
        $items = [];

        foreach ($this->listOf($payload, 'items') as $item) {
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                // The schema refuses a non-positive quantity, and a line claiming zero sold is not a
                // line worth keeping.
                continue;
            }

            $amounts = $this->nested($item, 'amounts');
            $unit = $this->amount(
                $this->nested($amounts, 'price_without_tax')['amount'] ?? $amounts['price_without_tax'] ?? $item['price'] ?? null,
                $currency,
            );
            $total = $this->amount(
                $this->nested($amounts, 'total')['amount'] ?? $amounts['total'] ?? null,
                $currency,
            );

            $items[] = new OrderItemRecord(
                name: $this->string($item, 'name') ?? 'Item',
                quantity: $quantity,
                unitPriceMinor: $unit ?? 0,
                totalMinor: $total ?? (($unit ?? 0) * $quantity),
                currency: $currency,
                externalId: $this->scalar($item, 'id'),
                sku: $this->string($item, 'sku'),
                productExternalId: $this->scalar($this->nested($item, 'product'), 'id'),
                variantExternalId: $this->scalar($item, 'variant_id'),
            );
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<RefundRecord>
     */
    private function refunds(array $payload, string $currency): array
    {
        $refunds = [];

        foreach ($this->listOf($payload, 'refunds') as $refund) {
            $amount = $this->amount($refund['amount'] ?? null, $currency);

            if ($amount === null || $amount <= 0) {
                continue;
            }

            $refunds[] = new RefundRecord(
                amountMinor: $amount,
                currency: $currency,
                refundedAt: $this->date($refund['date'] ?? $refund['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
                externalId: $this->scalar($refund, 'id'),
                reason: $this->string($refund, 'reason'),
            );
        }

        return $refunds;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ShipmentRecord>
     */
    private function shipments(array $payload): array
    {
        $shipments = [];

        foreach ($this->listOf($payload, 'shipments') as $shipment) {
            $shipments[] = new ShipmentRecord(
                externalId: $this->scalar($shipment, 'id'),
                carrier: $this->string($shipment, 'courier_name') ?? $this->string($shipment, 'company'),
                trackingNumber: $this->string($shipment, 'tracking_number'),
                status: $this->string($shipment, 'status'),
                shippedAt: $this->date($shipment['shipped_at'] ?? $shipment['created_at'] ?? null),
                deliveredAt: $this->date($shipment['delivered_at'] ?? null),
            );
        }

        return $shipments;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<VariantRecord>
     */
    private function variants(array $payload, string $currency): array
    {
        $variants = [];

        foreach ($this->listOf($payload, 'skus') as $sku) {
            $variants[] = new VariantRecord(
                externalId: (string) ($sku['id'] ?? ''),
                name: $this->string($sku, 'name'),
                sku: $this->string($sku, 'sku'),
                priceMinor: $this->amount($sku['price'] ?? null, $currency),
                currency: $currency,
                /*
                 * Null when Salla does not track stock for this variant, which is not the same as
                 * zero — and only one of the two is a reason to stop advertising a product.
                 */
                stock: isset($sku['stock_quantity']) && is_numeric($sku['stock_quantity'])
                    ? (int) $sku['stock_quantity']
                    : null,
            );
        }

        return $variants;
    }

    /** @param array<string, mixed> $payload */
    private function statusSlug(array $payload): string
    {
        $status = $payload['status'] ?? null;

        if (is_array($status)) {
            /** @var array<string, mixed> $status */
            return strtolower($this->string($status, 'slug') ?? $this->string($status, 'name') ?? '');
        }

        // Some webhook payloads send the slug directly as a string.
        return is_string($status) ? strtolower($status) : '';
    }

    /** @param array<string, mixed> $payload */
    private function orderCurrency(array $payload): string
    {
        $total = $payload['total'] ?? null;

        if (is_array($total)) {
            /** @var array<string, mixed> $total */
            $currency = $this->string($total, 'currency');

            if ($currency !== null) {
                return strtoupper($currency);
            }
        }

        return strtoupper($this->string($payload, 'currency') ?? 'SAR');
    }

    /**
     * The sum of a Salla order's discounts, as a positive amount taken off.
     *
     * Salla reports `amounts.discounts` as a list. Summed here into the single positive figure the
     * schema holds, because a discount that arrives as a negative addition in one provider and a
     * positive subtraction in another is how a discount comes to increase revenue.
     *
     * @param  array<string, mixed>  $amounts
     */
    private function discount(array $amounts, string $currency): ?int
    {
        $discounts = $this->listOf($amounts, 'discounts');

        if ($discounts === []) {
            return $this->amount($amounts['discount'] ?? null, $currency);
        }

        $total = 0;

        foreach ($discounts as $discount) {
            $total += abs($this->amount($discount['amount'] ?? null, $currency) ?? 0);
        }

        return $total;
    }

    /** @param array<string, mixed> $payload */
    private function refundedTotal(array $payload, string $currency): int
    {
        $total = 0;

        foreach ($this->listOf($payload, 'refunds') as $refund) {
            $total += abs($this->amount($refund['amount'] ?? null, $currency) ?? 0);
        }

        return $total;
    }

    /** @param array<string, mixed> $payload */
    private function paidAt(string $slug, array $payload): ?DateTimeImmutable
    {
        if ($slug === self::PAYMENT_PENDING || $slug === self::PAYMENT_FAILED) {
            return null;
        }

        /*
         * Salla does not publish a payment timestamp on the order, so this is only filled where it is
         * actually known. Guessing the order date would put a payment before the money moved, and a
         * cash-flow report built on it would be wrong by days.
         */
        return $this->date($payload['payment_date'] ?? null);
    }

    /**
     * A Salla money value to minor units.
     *
     * Accepts `{amount, currency}`, a bare number and a numeric string, because Salla uses all three
     * depending on the endpoint. Returns null for anything else — including an absent value, which is
     * the point: null means "not reported" and zero means "nothing".
     */
    private function amount(mixed $value, string $currency): ?int
    {
        if (is_array($value)) {
            $inner = $value['amount'] ?? null;

            return $inner === null ? null : $this->amount($inner, $currency);
        }

        if (is_int($value) || is_float($value)) {
            return Money::fromDecimalString(number_format((float) $value, 4, '.', ''), $currency)->minorUnits;
        }

        if (is_string($value) && is_numeric($value)) {
            return Money::fromDecimalString($value, $currency)->minorUnits;
        }

        return null;
    }

    /**
     * A Salla timestamp.
     *
     * Salla sends `{date, timezone_type, timezone}` on orders and plain strings elsewhere. The time
     * zone is honoured rather than dropped: a store in Riyadh whose orders were read as UTC would
     * have three hours of every evening's sales land on the previous day.
     */
    private function date(mixed $value): ?DateTimeImmutable
    {
        if (is_array($value)) {
            $inner = $value['date'] ?? null;
            $zone = $value['timezone'] ?? null;

            if (! is_string($inner) || $inner === '') {
                return null;
            }

            try {
                return new DateTimeImmutable(
                    $inner,
                    is_string($zone) && $zone !== '' ? new DateTimeZone($zone) : null,
                );
            } catch (Exception) {
                return null;
            }
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function phone(array $payload): ?string
    {
        $mobile = $payload['mobile'] ?? null;

        if ($mobile === null || $mobile === '') {
            return null;
        }

        $code = $payload['mobile_code'] ?? null;
        $number = is_int($mobile) || is_float($mobile) ? (string) (int) $mobile : (string) $mobile;

        return is_string($code) && $code !== '' ? $code.$number : $number;
    }

    /** @param array<string, mixed> $payload */
    private function countryCode(array $payload): ?string
    {
        $code = $this->string($payload, 'country_code');

        // The schema holds two characters; anything else is a code this product cannot store honestly.
        return $code !== null && strlen($code) === 2 ? strtoupper($code) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function listOf(array $row, string $key): array
    {
        $values = $row[$key] ?? null;
        $list = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function nested(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * An id that may arrive as an integer or a string.
     *
     * Kept as the string the API addresses it by.
     *
     * @param  array<string, mixed>  $row
     */
    private function scalar(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (string) (int) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
