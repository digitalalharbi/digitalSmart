<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\ConversionActionType;
use App\Domain\Advertising\MetricSource;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConversionMetric extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'external_account_id', 'provider', 'entity_type', 'entity_id',
        'campaign_id', 'date', 'action_type', 'raw_action_type', 'action_name',
        'count', 'value_minor', 'currency', 'attribution_window', 'source', 'metadata',
    ];

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entity_type' => AdEntityType::class,
            'action_type' => ConversionActionType::class,
            'source' => MetricSource::class,
            'date' => 'immutable_date',
            'count' => 'float',
            'value_minor' => 'integer',
            'metadata' => 'array',
        ];
    }
}
