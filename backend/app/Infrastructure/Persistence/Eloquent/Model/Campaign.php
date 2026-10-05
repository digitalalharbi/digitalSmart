<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\EntityStatus;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Campaign extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'external_account_id', 'provider', 'external_id', 'name',
        'objective', 'raw_objective', 'status', 'raw_status', 'raw_effective_status',
        'buying_type', 'bid_strategy', 'budget_mode', 'budget_minor', 'spend_cap_minor',
        'currency', 'starts_at', 'ends_at', 'provider_created_at', 'provider_updated_at',
        'metadata', 'synced_at',
    ];

    /** @return BelongsTo<ExternalAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ExternalAccount::class, 'external_account_id');
    }

    /** @return HasMany<AdGroup, $this> */
    public function adGroups(): HasMany
    {
        return $this->hasMany(AdGroup::class);
    }

    /** @return HasMany<Ad, $this> */
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    /** @return HasMany<DailyMetric, $this> */
    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(DailyMetric::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'objective' => CampaignObjective::class,
            'status' => EntityStatus::class,
            'budget_mode' => BudgetMode::class,
            'budget_minor' => 'integer',
            'spend_cap_minor' => 'integer',
            'metadata' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
