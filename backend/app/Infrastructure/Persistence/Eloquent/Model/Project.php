<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'client_id', 'name', 'slug', 'description', 'status',
        'currency', 'starts_on', 'ends_on', 'is_demo', 'created_by',
    ];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<ProjectMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_demo' => 'boolean',
        ];
    }
}
