<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\EntityStatus;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Meta's ad set, Google's ad group, X's line item, LinkedIn's campaign. */
final class AdGroup extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ad_groups';

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'campaign_id', 'external_account_id', 'provider', 'external_id',
        'name', 'status', 'raw_status', 'raw_effective_status', 'optimization_goal',
        'billing_event', 'bid_amount_minor', 'budget_mode', 'budget_minor', 'currency',
        'starts_at', 'ends_at', 'provider_created_at', 'provider_updated_at',
        'targeting', 'metadata', 'synced_at',
    ];

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<Ad, $this> */
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
            'budget_mode' => BudgetMode::class,
            'bid_amount_minor' => 'integer',
            'budget_minor' => 'integer',
            'targeting' => 'array',
            'metadata' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
