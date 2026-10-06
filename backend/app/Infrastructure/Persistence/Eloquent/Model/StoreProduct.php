<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StoreProduct extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'store_id', 'external_id', 'name', 'sku', 'status',
        'price_minor', 'currency', 'image_url',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return HasMany<StoreProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(StoreProductVariant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price_minor' => 'integer'];
    }
}
