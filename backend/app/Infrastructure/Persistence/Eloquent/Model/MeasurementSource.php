<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Integration\Provider;
use App\Domain\Measurement\MeasurementSourceKind;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MeasurementSource extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'client_id', 'project_id', 'provider', 'kind', 'external_id',
        'name', 'provider_connection_id', 'last_synced_at', 'last_event_at',
        'is_active', 'metadata',
    ];

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

    /** @return HasMany<ObservedEventCount, $this> */
    public function observedCounts(): HasMany
    {
        return $this->hasMany(ObservedEventCount::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'kind' => MeasurementSourceKind::class,
            'last_synced_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
