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
use Exception;

/**
 * Zid API v1 responses to normalised records.
 *
 * Built against the published contracts at `https://api.zid.sa/v1/managers/store/`. Zid differs from
 * Salla in four ways that matter, and writing this mapper is what proved the Phase 11 shape is not
 * secretly Salla's:
 *
 *  1. **Zid already separates payment from fulfilment.** It publishes `payment_status` —
 *     `pending`, `paid`, `refunded`, `voided` — alongside an `order_status` object. Where Salla needed
 *     a documented boolean to recover the payment axis, Zid simply states it. The two-axis design was
 *     a guess until this; it is now a demonstrated fit.
 *  2. **A merchant can define custom order statuses.** So the fulfilment mapping has to handle a code
 *     this product has never seen, and `unknownFulfilment()` explains why it answers as it does.
 *  3. **Marketplace orders arrive with the customer's details masked.** `is_marketplace_order` is Zid
 *     telling us the name, e-mail and phone on the order are placeholders rather than the customer's.
 *     This mapper never writes a customer from an order payload — customers come only from the
 *     customers endpoint — so a masked placeholder has no route into the customers table. The order's
 *     customer id is still read, and simply links to nothing, which is the truth.
 *  4. **Items are absent unless asked for.** `payload_type` defaults to `simple`, which omits products
 *     entirely; `detailed` includes them.
 */
final readonly class ZidMapper implements StoreOrderMapper
{
    /** Zid's documented payment statuses. */
    private const string PAYMENT_PENDING = 'pending';

    private const string PAYMENT_PAID = 'paid';

    private const string PAYMENT_REFUNDED = 'refunded';

    private const string PAYMENT_VOIDED = 'voided';

    /** Zid's documented order status codes. A merchant may also define its own. */
    private const string STATUS_NEW = 'new';

    private const string STATUS_PREPARING = 'preparing';

    private const string STATUS_READY = 'ready';

    private const string STATUS_IN_DELIVERY = 'indelivery';

    private const string STATUS_DELIVERED = 'delivered';

    private const string STATUS_CANCELLED = 'cancelled';

    private const string STATUS_REVERSED = 'reversed';

    private const string STATUS_REVERSE_IN_PROGRESS = 'reverse_in_progress';

    private const string STATUS_PARTIALLY_REVERSED = 'partially_reversed';

    /** @param array<string, mixed> $payload a store or profile object */
    public function store(array $payload): StoreRecord
    {
        return new StoreRecord(
            externalId: (string) ($payload['id'] ?? ''),
            name: $this->string($payload, 'name')
                ?? $this->string($this->nested($payload, 'store'), 'name')
                ?? 'Zid store',
            currency: strtoupper(
                $this->string($payload, 'currency_code')
                ?? $this->string($this->nested($payload, 'store'), 'currency_code')
                ?? 'SAR'
            ),
            domain: $this->string($payload, 'url') ?? $this->string($payload, 'domain'),
            timezone: $this->string($payload, 'timezone'),
        );
    }

    /** @param array<string, mixed> $payload */
    public function order(array $payload): OrderRecord
    {
        $currency = strtoupper($this->string($payload, 'currency_code') ?? 'SAR');
        $statusCode = $this->statusCode($payload);
        $paymentStatus = strtolower($this->string($payload, 'payment_status') ?? '');
        $refunds = $this->refunds($payload, $currency);
        $refunded = array_sum(array_map(static fn (RefundRecord $r): int => $r->amountMinor, $refunds));
        $total = $this->amount($payload['order_total'] ?? null, $currency) ?? 0;

        return new OrderRecord(
            externalId: (string) ($payload['id'] ?? ''),
            paymentState: $this->paymentState($paymentStatus, $statusCode, $refunded, $total),
            fulfilmentState: $this->fulfilmentState($statusCode),
            currency: $currency,
            /*
             * `order_total`, not `order_total_string`. The second is formatted for display — currency
             * symbol, thousands separators, Arabic digits where the locale asks — and parsing it would
             * work until a store switched language.
             */
            totalMinor: $total,
            placedAt: $this->date($payload['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            attribution: $this->attribution($payload),
            // Zid's `code` is the number the customer and the merchant quote at each other.
            reference: $this->string($payload, 'code'),
            // Both of Zid's own strings, kept for when a mapping is disputed.
            rawPaymentStatus: $paymentStatus !== '' ? $paymentStatus : null,
            rawFulfilmentStatus: $statusCode !== '' ? $statusCode : null,
            subtotalMinor: $this->amount($payload['products_total'] ?? $payload['sub_total'] ?? null, $currency),
            discountMinor: $this->amount($payload['discount_amount'] ?? $payload['discount'] ?? null, $currency),
            shippingMinor: $this->amount($payload['shipping_cost'] ?? null, $currency),
            taxMinor: $this->amount($payload['tax_amount'] ?? $payload['vat'] ?? null, $currency),
            refundedMinor: $refunded,
            paymentMethod: $this->string($payload, 'payment_method')
                ?? $this->string($this->nested($payload, 'payment'), 'method'),
            gateway: $this->string($this->nested($payload, 'payment'), 'gateway'),
            customerExternalId: $this->scalar($this->nested($payload, 'customer'), 'id'),
            paidAt: $this->date($payload['paid_at'] ?? null),
            cancelledAt: $statusCode === self::STATUS_CANCELLED
                ? $this->date($payload['updated_at'] ?? null)
                : null,
            providerUpdatedAt: $this->date($payload['updated_at'] ?? null),
            items: $this->items($payload, $currency),
            refunds: $refunds,
            shipments: $this->shipments($payload),
        );
    }

    /**
     * One customer, from the customers endpoint.
     *
     * Only ever called with a customer Zid returned from `/customers`, which are real records. The
     * masking Zid applies to a **marketplace order** — where `is_marketplace_order` is true and the
     * name, e-mail and phone on the order are placeholders rather than the customer's — is handled
     * structurally instead of here: nothing in this product writes a customer from an order payload, so
     * a masked placeholder has no route into the customers table at all.
     *
     * That matters more than it looks. A fabricated e-mail stored as a customer would appear in the
     * client's customer list and would poison every later attempt to match an order to a lead, and it
     * would be indistinguishable from a real one afterwards.
     *
     * @param  array<string, mixed>  $payload
     */
    public function customer(array $payload): CustomerRecord
    {
        return new CustomerRecord(
            externalId: (string) ($payload['id'] ?? ''),
            name: $this->string($payload, 'name')
                ?? trim(($this->string($payload, 'first_name') ?? '').' '.($this->string($payload, 'last_name') ?? '')) ?: null,
            email: $this->string($payload, 'email'),
            phone: $this->phone($payload),
            city: $this->string($this->nested($payload, 'city'), 'name') ?? $this->string($payload, 'city'),
            country: $this->countryCode($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    public function product(array $payload): ProductRecord
    {
        $currency = strtoupper($this->string($payload, 'currency_code') ?? 'SAR');

        return new ProductRecord(
            externalId: (string) ($payload['id'] ?? ''),
            name: $this->productName($payload),
            sku: $this->string($payload, 'sku'),
            status: $this->string($payload, 'status') ?? ($this->bool($payload, 'is_published') ? 'published' : null),
            priceMinor: $this->amount($payload['price'] ?? null, $currency),
            currency: $currency,
            imageUrl: $this->string($this->nested($payload, 'image'), 'full_size')
                ?? $this->string($payload, 'thumbnail'),
            variants: $this->variants($payload, $currency),
        );
    }

    /**
     * Where the order stands financially.
     *
     * Zid states this directly, which is the happy case and worth noting: no inference is needed at
     * all. A refund amount still outranks a `paid` status, because Zid can report a part refund with
     * the status unchanged, and a partial has to be distinguishable from a whole one.
     */
    private function paymentState(string $paymentStatus, string $statusCode, int $refunded, int $total): PaymentState
    {
        if ($refunded > 0) {
            return $refunded >= $total && $total > 0
                ? PaymentState::Refunded
                : PaymentState::PartiallyRefunded;
        }

        return match ($paymentStatus) {
            self::PAYMENT_PAID => PaymentState::Paid,
            self::PAYMENT_REFUNDED => PaymentState::Refunded,
            self::PAYMENT_VOIDED => PaymentState::Voided,
            self::PAYMENT_PENDING => PaymentState::Pending,
            /*
             * No payment status at all. A cancelled order is voided; anything else is pending, which
             * keeps it out of revenue until something says otherwise. Understating gets investigated;
             * overstating gets sent to the client.
             */
            default => $statusCode === self::STATUS_CANCELLED ? PaymentState::Voided : PaymentState::Pending,
        };
    }

    /** Where the order stands physically. */
    private function fulfilmentState(string $statusCode): FulfilmentState
    {
        return match ($statusCode) {
            self::STATUS_NEW => FulfilmentState::Unfulfilled,
            // "Ready" is ready to go out, which is not the same as gone.
            self::STATUS_PREPARING, self::STATUS_READY => FulfilmentState::Processing,
            self::STATUS_IN_DELIVERY => FulfilmentState::Shipped,
            self::STATUS_DELIVERED => FulfilmentState::Delivered,
            self::STATUS_CANCELLED => FulfilmentState::Cancelled,
            self::STATUS_REVERSED, self::STATUS_PARTIALLY_REVERSED => FulfilmentState::Returned,
            /*
             * A return under way, not a return completed. `Processing` rather than `Returned` because
             * `Returned` is settled, and a settled state tells the sync to stop re-reading an order
             * that is still moving. The exact code survives in `raw_fulfilment_status`.
             */
            self::STATUS_REVERSE_IN_PROGRESS => FulfilmentState::Processing,
            default => $this->unknownFulfilment(),
        };
    }

    /**
     * What to say about a status code this product has never seen.
     *
     * Zid lets a merchant define custom order statuses, so an unrecognised code is an ordinary
     * occurrence rather than a defect — and guessing from its name is exactly what this product refuses
     * to do everywhere else.
     *
     * `Processing` is the least-wrong answer and, more importantly, the most useful one: it claims only
     * that the order is in progress, and it is **not settled**, so the sync keeps re-reading the order
     * until it reaches a state that is understood. A state we do not understand should keep us looking,
     * not stop us.
     */
    private function unknownFulfilment(): FulfilmentState
    {
        return FulfilmentState::Processing;
    }

    /**
     * What the order carried about where it came from.
     *
     * @param  array<string, mixed>  $payload
     */
    private function attribution(array $payload): OrderAttribution
    {
        $source = $this->nested($payload, 'source') ?: $this->nested($payload, 'utm');
        $coupon = $this->nested($payload, 'coupon');

        return OrderAttribution::from(
            source: $this->string($source, 'utm_source') ?? $this->string($source, 'source'),
            medium: $this->string($source, 'utm_medium') ?? $this->string($source, 'medium'),
            campaign: $this->string($source, 'utm_campaign') ?? $this->string($source, 'campaign'),
            content: $this->string($source, 'utm_content'),
            term: $this->string($source, 'utm_term'),
            clickIdentifier: $this->string($source, 'gclid') ?? $this->string($source, 'fbclid'),
            couponCode: $this->string($coupon, 'code') ?? $this->string($payload, 'coupon_code'),
            referrer: $this->string($source, 'referrer') ?? $this->string($payload, 'referrer'),
        );
    }

    /**
     * The order's lines.
     *
     * Absent unless the request asked for `payload_type=detailed`; the default omits products
     * altogether. An order with no lines is therefore normal rather than suspicious here, and the
     * adapter asks for the detailed payload precisely so it is not.
     *
     * @param  array<string, mixed>  $payload
     * @return list<OrderItemRecord>
     */
    private function items(array $payload, string $currency): array
    {
        $items = [];
        $rows = $this->listOf($payload, 'products') ?: $this->listOf($payload, 'items');

        foreach ($rows as $row) {
            $quantity = (int) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $unit = $this->amount($row['price'] ?? $row['unit_price'] ?? null, $currency);
            $total = $this->amount($row['total'] ?? $row['net_price'] ?? null, $currency);

            $items[] = new OrderItemRecord(
                name: $this->string($row, 'name') ?? 'Item',
                quantity: $quantity,
                unitPriceMinor: $unit ?? 0,
                totalMinor: $total ?? (($unit ?? 0) * $quantity),
                currency: $currency,
                externalId: $this->scalar($row, 'id'),
                sku: $this->string($row, 'sku'),
                productExternalId: $this->scalar($row, 'product_id')
                    ?? $this->scalar($this->nested($row, 'product'), 'id'),
                variantExternalId: $this->scalar($row, 'variant_id'),
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

        /*
         * Zid records a reversal as a credit note. The name differs from Salla's and the meaning is the
         * same: money went back, on a date, for a reason.
         */
        $rows = $this->listOf($payload, 'credit_notes') ?: $this->listOf($payload, 'refunds');

        foreach ($rows as $row) {
            $amount = $this->amount($row['amount'] ?? $row['total'] ?? null, $currency);

            if ($amount === null || $amount <= 0) {
                continue;
            }

            $refunds[] = new RefundRecord(
                amountMinor: $amount,
                currency: $currency,
                refundedAt: $this->date($row['created_at'] ?? $row['date'] ?? null) ?? new DateTimeImmutable('@0'),
                externalId: $this->scalar($row, 'id'),
                reason: $this->string($row, 'reason') ?? $this->string($row, 'note'),
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
        $rows = $this->listOf($payload, 'shipments');

        if ($rows === []) {
            // Zid's simple payload carries one shipping block rather than a list.
            $shipping = $this->nested($payload, 'shipping');
            $rows = $shipping === [] ? [] : [$shipping];
        }

        foreach ($rows as $row) {
            $method = $this->nested($row, 'method');

            $shipments[] = new ShipmentRecord(
                externalId: $this->scalar($row, 'id'),
                carrier: $this->string($method, 'name')
                    ?? $this->string($row, 'courier')
                    ?? $this->string($row, 'company_name'),
                trackingNumber: $this->string($row, 'tracking_number') ?? $this->string($row, 'waybill'),
                status: $this->string($row, 'status'),
                shippedAt: $this->date($row['shipped_at'] ?? null),
                deliveredAt: $this->date($row['delivered_at'] ?? null),
            );
        }

        return $shipments;
    }

    /**
     * A Zid product name.
     *
     * Zid returns localised fields as `{ar: "…", en: "…"}` when `Accept-Language` is not pinned. Arabic
     * is preferred where both exist, because these are Saudi stores and the Arabic name is the one the
     * merchant's team will recognise in a report.
     *
     * @param  array<string, mixed>  $payload
     */
    private function productName(array $payload): string
    {
        $name = $payload['name'] ?? null;

        if (is_array($name)) {
            /** @var array<string, mixed> $name */
            return $this->string($name, 'ar') ?? $this->string($name, 'en') ?? 'Product';
        }

        return is_string($name) && trim($name) !== '' ? trim($name) : 'Product';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<VariantRecord>
     */
    private function variants(array $payload, string $currency): array
    {
        $variants = [];

        foreach ($this->listOf($payload, 'variants') as $variant) {
            $variants[] = new VariantRecord(
                externalId: (string) ($variant['id'] ?? ''),
                name: $this->variantName($variant),
                sku: $this->string($variant, 'sku'),
                priceMinor: $this->amount($variant['price'] ?? null, $currency),
                currency: $currency,
                /*
                 * Zid reports `is_infinite` for a variant it does not count. Null rather than a large
                 * number: untracked stock is not a quantity, and only a real zero is a reason to stop
                 * advertising a product.
                 */
                stock: $this->bool($variant, 'is_infinite')
                    ? null
                    : (isset($variant['quantity']) && is_numeric($variant['quantity'])
                        ? (int) $variant['quantity']
                        : null),
            );
        }

        return $variants;
    }

    /** @param array<string, mixed> $variant */
    private function variantName(array $variant): ?string
    {
        $name = $variant['name'] ?? null;

        if (is_array($name)) {
            /** @var array<string, mixed> $name */
            return $this->string($name, 'ar') ?? $this->string($name, 'en');
        }

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        // Zid also describes a variant by its attributes, e.g. "Size: M".
        $parts = [];

        foreach ($this->listOf($variant, 'attributes') as $attribute) {
            $value = $attribute['value'] ?? null;

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $parts[] = $this->string($value, 'ar') ?? $this->string($value, 'en') ?? '';
            } elseif (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        }

        $joined = trim(implode(' / ', array_filter($parts)));

        return $joined === '' ? null : $joined;
    }

    /** @param array<string, mixed> $payload */
    private function statusCode(array $payload): string
    {
        $status = $payload['order_status'] ?? null;

        if (is_array($status)) {
            /** @var array<string, mixed> $status */
            return strtolower($this->string($status, 'code') ?? '');
        }

        return is_string($status) ? strtolower($status) : '';
    }

    /**
     * A Zid money value to minor units.
     *
     * Zid sends amounts as numeric **strings** on orders and as numbers elsewhere. Both are accepted;
     * anything else is null, which means "not reported" rather than zero.
     */
    private function amount(mixed $value, string $currency): ?int
    {
        if (is_array($value)) {
            $inner = $value['amount'] ?? $value['value'] ?? null;

            return $inner === null ? null : $this->amount($inner, $currency);
        }

        if (is_int($value) || is_float($value)) {
            return Money::fromDecimalString(number_format((float) $value, 4, '.', ''), $currency)->minorUnits;
        }

        return is_string($value) && is_numeric($value)
            ? Money::fromDecimalString($value, $currency)->minorUnits
            : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
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
        $mobile = $payload['mobile'] ?? $payload['phone'] ?? null;

        if ($mobile === null || $mobile === '') {
            return null;
        }

        return is_int($mobile) || is_float($mobile) ? (string) (int) $mobile : (string) $mobile;
    }

    /** @param array<string, mixed> $payload */
    private function countryCode(array $payload): ?string
    {
        $code = $this->string($this->nested($payload, 'country'), 'code')
            ?? $this->string($payload, 'country_code');

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

    /** @param array<string, mixed> $row */
    private function bool(array $row, string $key): bool
    {
        return ($row[$key] ?? false) === true || ($row[$key] ?? null) === 1 || ($row[$key] ?? null) === '1';
    }

    /**
     * An id that may arrive as an integer or a string.
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
