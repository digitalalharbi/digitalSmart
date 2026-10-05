<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\MetricSource;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of performance for one entity at one level.
 *
 * Every count is nullable on purpose. A null is "the provider did not report
 * this"; a zero is "it reported none". The casts below must never coerce one
 * into the other — `integer` on a null column leaves null alone, which is why
 * these are plain casts and not defaults.
 */
final class DailyMetric extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'external_account_id', 'provider', 'entity_type', 'entity_id',
        'external_entity_id', 'campaign_id', 'ad_group_id', 'ad_id', 'date',
        'account_timezone', 'currency', 'spend_minor', 'revenue_minor', 'impressions',
        'reach', 'clicks', 'link_clicks', 'video_views', 'video_completions',
        'engagements', 'conversions', 'source', 'metadata', 'synced_at',
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

    /** @return BelongsTo<Ad, $this> */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entity_type' => AdEntityType::class,
            'source' => MetricSource::class,
            'date' => 'immutable_date',
            'spend_minor' => 'integer',
            'revenue_minor' => 'integer',
            'impressions' => 'integer',
            'reach' => 'integer',
            'clicks' => 'integer',
            'link_clicks' => 'integer',
            'video_views' => 'integer',
            'video_completions' => 'integer',
            'engagements' => 'integer',
            'conversions' => 'float',
            'metadata' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
