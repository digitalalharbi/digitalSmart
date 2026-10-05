<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\EntityStatus;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Ad extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'campaign_id', 'ad_group_id', 'external_account_id', 'creative_id',
        'provider', 'external_id', 'name', 'status', 'raw_status', 'raw_effective_status',
        'review_feedback', 'provider_created_at', 'provider_updated_at', 'metadata', 'synced_at',
    ];

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<AdGroup, $this> */
    public function adGroup(): BelongsTo
    {
        return $this->belongsTo(AdGroup::class);
    }

    /** @return BelongsTo<Creative, $this> */
    public function creative(): BelongsTo
    {
        return $this->belongsTo(Creative::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
            'metadata' => 'array',
            'provider_created_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
