<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer of the agency's client.
 *
 * Holds personal data that belongs to the client, not to the agency. It is never serialised into a
 * client-portal response, and `docs/SECURITY.md` records the retention question it raises.
 */
final class StoreCustomer extends Model
{
    use HasUuidPrimaryKey;

    /**
     * Contact details and their matching keys are never serialised by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['email', 'phone', 'email_key', 'phone_key'];

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'store_id', 'external_id', 'name', 'email', 'phone', 'email_key', 'phone_key',
        'city', 'country',
        'orders_count', 'total_spent_minor', 'first_order_at', 'last_order_at',
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
            'orders_count' => 'integer',
            'total_spent_minor' => 'integer',
            'first_order_at' => 'immutable_datetime',
            'last_order_at' => 'immutable_datetime',
        ];
    }
}
