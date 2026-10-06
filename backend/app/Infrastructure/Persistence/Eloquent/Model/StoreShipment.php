<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StoreShipment extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'store_order_id', 'external_id', 'carrier', 'tracking_number',
        'status', 'shipped_at', 'delivered_at',
    ];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }
}
