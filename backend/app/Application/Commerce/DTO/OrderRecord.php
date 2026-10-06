<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use DateTimeImmutable;

/**
 * One order, normalised, with everything a report needs and nothing inferred.
 *
 * The two state fields are separate because every platform has both axes and a single status makes
 * "delivered but refunded" unrepresentable. The raw strings travel alongside so that when somebody
 * disputes a mapping — and they will — the provider's own word for it is still on the record.
 */
final readonly class OrderRecord
{
    /**
     * @param  list<OrderItemRecord>  $items
     * @param  list<RefundRecord>  $refunds
     * @param  list<ShipmentRecord>  $shipments
     */
    public function __construct(
        public string $externalId,
        public PaymentState $paymentState,
        public FulfilmentState $fulfilmentState,
        public string $currency,
        public int $totalMinor,
        public DateTimeImmutable $placedAt,
        /** What the order itself carried about where it came from. Never a guess. */
        public OrderAttribution $attribution,
        public ?string $reference = null,
        public ?string $rawPaymentStatus = null,
        public ?string $rawFulfilmentStatus = null,
        public int $subtotalMinor = 0,
        public int $discountMinor = 0,
        public int $shippingMinor = 0,
        public int $taxMinor = 0,
        public int $refundedMinor = 0,
        public ?string $paymentMethod = null,
        public ?string $gateway = null,
        public ?string $customerExternalId = null,
        public ?DateTimeImmutable $paidAt = null,
        public ?DateTimeImmutable $cancelledAt = null,
        public ?DateTimeImmutable $providerUpdatedAt = null,
        public array $items = [],
        public array $refunds = [],
        public array $shipments = [],
    ) {}
}
