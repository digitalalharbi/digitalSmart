<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order.
 *
 * The name and price are as they were at the time of sale. A line that read the catalogue instead
 * would rewrite last quarter's revenue every time somebody changed a price.
 */
final class StoreOrderItem extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'store_order_id', 'store_product_id', 'store_product_variant_id',
        'external_id', 'name', 'sku', 'quantity', 'unit_price_minor', 'discount_minor',
        'total_minor', 'currency',
    ];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }
}
