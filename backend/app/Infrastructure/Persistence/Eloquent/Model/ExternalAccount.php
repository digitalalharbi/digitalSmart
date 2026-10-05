<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ExternalAccount extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'provider_connection_id', 'provider', 'external_id',
        'name', 'currency', 'timezone', 'status', 'metadata', 'discovered_at',
    ];

    /** @return BelongsTo<ProviderConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    /** @return HasMany<ProjectIntegrationBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(ProjectIntegrationBinding::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'discovered_at' => 'immutable_datetime',
        ];
    }
}
