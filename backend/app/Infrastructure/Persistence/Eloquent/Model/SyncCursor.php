<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\SyncKind;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the last sync got to.
 *
 * `synced_through` is the high-water mark; `resync_from` is how far back the
 * next insights run must re-read, because conversions keep landing against past
 * dates long after those days are "done".
 */
final class SyncCursor extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'external_account_id', 'kind', 'cursor', 'synced_through',
        'resync_from', 'last_success_at', 'consecutive_failures', 'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SyncKind::class,
            'synced_through' => 'immutable_date',
            'resync_from' => 'immutable_date',
            'last_success_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer',
            'metadata' => 'array',
        ];
    }
}
