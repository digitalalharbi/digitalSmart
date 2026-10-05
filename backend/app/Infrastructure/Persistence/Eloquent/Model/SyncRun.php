<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\SyncKind;
use App\Domain\Advertising\SyncStatus;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One sync attempt, successful or not. The audit trail for data freshness. */
final class SyncRun extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'provider_connection_id', 'external_account_id', 'provider',
        'kind', 'status', 'range_start', 'range_end', 'records_read', 'records_written',
        'api_calls', 'started_at', 'finished_at', 'duration_ms', 'error_message',
        'error_code', 'retryable', 'metadata',
    ];

    /** @return HasMany<ProviderError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(ProviderError::class, 'sync_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SyncKind::class,
            'status' => SyncStatus::class,
            'range_start' => 'immutable_date',
            'range_end' => 'immutable_date',
            'records_read' => 'integer',
            'records_written' => 'integer',
            'api_calls' => 'integer',
            'duration_ms' => 'integer',
            'retryable' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
