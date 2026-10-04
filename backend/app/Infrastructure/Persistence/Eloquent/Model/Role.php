<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Role extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = ['workspace_id', 'name', 'slug', 'description', 'is_system'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
