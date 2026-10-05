<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CreativeAsset extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'creative_id', 'kind', 'url', 'external_id', 'width', 'height',
        'duration_ms', 'file_size_bytes', 'mime_type', 'position', 'metadata',
    ];

    /** @return BelongsTo<Creative, $this> */
    public function creative(): BelongsTo
    {
        return $this->belongsTo(Creative::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'file_size_bytes' => 'integer',
            'position' => 'integer',
            'metadata' => 'array',
        ];
    }
}
