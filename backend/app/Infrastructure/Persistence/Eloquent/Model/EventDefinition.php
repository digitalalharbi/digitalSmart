<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Measurement\NormalizedEvent;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventDefinition extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'client_id', 'project_id', 'name', 'normalized_event',
        'description', 'is_conversion', 'is_primary', 'expects_value', 'created_by',
    ];

    /** @return HasMany<EventMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(EventMapping::class, 'event_definition_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'normalized_event' => NormalizedEvent::class,
            'is_conversion' => 'boolean',
            'is_primary' => 'boolean',
            'expects_value' => 'boolean',
        ];
    }
}
