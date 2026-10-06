<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StoreProductVariant extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'store_product_id', 'external_id', 'name', 'sku',
        'price_minor', 'currency', 'stock',
    ];

    /** @return BelongsTo<StoreProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'store_product_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'stock' => 'integer'];
    }
}
