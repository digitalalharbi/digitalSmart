<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'default_locale', 'default_currency', 'timezone', 'type', 'settings'];

    /** @return HasMany<WorkspaceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
