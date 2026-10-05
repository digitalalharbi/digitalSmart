<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Advertising\CreativeFormat;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Creative extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'external_account_id', 'provider', 'external_id', 'name',
        'format', 'title', 'body', 'call_to_action', 'destination_url',
        'thumbnail_url', 'preview_url', 'metadata', 'synced_at',
    ];

    /** @return HasMany<CreativeAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(CreativeAsset::class)->orderBy('position');
    }

    /** @return HasMany<Ad, $this> */
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'format' => CreativeFormat::class,
            'metadata' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
