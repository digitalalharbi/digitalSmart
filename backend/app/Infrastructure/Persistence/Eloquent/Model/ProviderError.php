<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\ProviderErrorCategory;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A normalised provider failure, so rate limits read as a pattern. */
final class ProviderError extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'sync_run_id', 'provider', 'category', 'status_code',
        'provider_code', 'message', 'retryable', 'retry_after_seconds', 'context', 'created_at',
    ];

    /** @return BelongsTo<SyncRun, $this> */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ProviderErrorCategory::class,
            'status_code' => 'integer',
            'retryable' => 'boolean',
            'retry_after_seconds' => 'integer',
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
