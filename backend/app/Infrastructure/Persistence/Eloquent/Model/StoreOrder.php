<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One order, normalised.
 *
 * Two state columns rather than one, money in integer minor units, and an attribution record that
 * says what the order itself carried. Nothing here is inferred from anything else.
 */
final class StoreOrder extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'store_id', 'store_customer_id', 'external_id', 'reference',
        'payment_state', 'fulfilment_state', 'raw_payment_status', 'raw_fulfilment_status',
        'currency', 'subtotal_minor', 'discount_minor', 'shipping_minor', 'tax_minor',
        'total_minor', 'refunded_minor', 'payment_method', 'gateway',
        'attribution_evidence', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'utm_term', 'click_identifier', 'coupon_code', 'referrer',
        'revenue_source', 'source', 'placed_at', 'paid_at', 'cancelled_at', 'provider_updated_at',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<StoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }

    /** @return HasMany<StoreOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class, 'store_order_id');
    }

    /** @return HasMany<StoreRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(StoreRefund::class, 'store_order_id');
    }

    /** @return HasMany<StoreShipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(StoreShipment::class, 'store_order_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_state' => PaymentState::class,
            'fulfilment_state' => FulfilmentState::class,
            'attribution_evidence' => AttributionEvidence::class,
            'revenue_source' => RevenueSource::class,
            'source' => MetricSource::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'refunded_minor' => 'integer',
            'placed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
        ];
    }
}
