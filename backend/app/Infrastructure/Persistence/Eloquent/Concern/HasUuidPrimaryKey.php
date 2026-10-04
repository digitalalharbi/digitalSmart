<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Concern;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * UUID primary keys for every API-facing entity.
 *
 * Laravel's HasUuids issues version 7 (time-ordered) identifiers, which is what
 * we want: B-tree inserts stay sequential — important for the high-volume
 * metrics tables — while the key is still safe to expose in URLs. The v4
 * variant (HasVersion4Uuids) is deliberately not used.
 *
 * This wrapper exists so the project has one place to change key strategy and
 * so models declare intent (`HasUuidPrimaryKey`) rather than a framework detail.
 */
trait HasUuidPrimaryKey
{
    use HasUuids;

    public function initializeHasUuidPrimaryKey(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }
}
