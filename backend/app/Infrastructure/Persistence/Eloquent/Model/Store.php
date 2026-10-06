<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Store extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'client_id', 'provider_connection_id', 'provider', 'external_id',
        'name', 'domain', 'currency', 'timezone', 'is_active', 'last_synced_at',
        'orders_synced_through', 'metadata',
    ];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<StoreOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'is_active' => 'boolean',
            'last_synced_at' => 'immutable_datetime',
            'orders_synced_through' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
