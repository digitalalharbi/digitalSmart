<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventMapping extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'event_definition_id', 'measurement_source_id', 'raw_event_name',
    ];

    /** @return BelongsTo<EventDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(EventDefinition::class, 'event_definition_id');
    }

    /** @return BelongsTo<MeasurementSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MeasurementSource::class, 'measurement_source_id');
    }
}
