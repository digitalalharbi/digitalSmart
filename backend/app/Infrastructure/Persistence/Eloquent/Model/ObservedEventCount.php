<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\MetricSource;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One source's count of one raw event name on one day.
 *
 * An aggregate, deliberately. The alternative — a row per event — would be a firehose with
 * no question behind it, and the questions this product actually asks are all per day.
 */
final class ObservedEventCount extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'measurement_source_id', 'raw_event_name', 'date',
        'event_count', 'value_minor', 'currency', 'source',
    ];

    /** @return BelongsTo<MeasurementSource, $this> */
    public function measurementSource(): BelongsTo
    {
        return $this->belongsTo(MeasurementSource::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'event_count' => 'integer',
            'value_minor' => 'integer',
            'source' => MetricSource::class,
        ];
    }
}
