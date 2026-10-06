<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One webhook as it arrived.
 *
 * Written before anything is done with it, and the unique index on the provider's own event id is
 * what makes a redelivery harmless. Providers redeliver on any non-2xx response, and a redelivered
 * order-paid event processed twice doubles a day's revenue.
 */
final class StoreWebhookEvent extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'store_id', 'provider', 'topic', 'external_event_id',
        'signature_verified', 'status', 'payload', 'error', 'received_at', 'processed_at',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'signature_verified' => 'boolean',
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
