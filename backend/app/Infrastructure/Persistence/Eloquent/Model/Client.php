<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'name', 'slug', 'legal_name', 'status', 'industry', 'website',
        'primary_contact_name', 'primary_contact_email', 'primary_contact_phone',
        'default_currency', 'notes', 'is_demo', 'created_by',
    ];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_demo' => 'boolean'];
    }
}
