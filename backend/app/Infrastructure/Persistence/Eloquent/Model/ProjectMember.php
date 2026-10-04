<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectMember extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = ['project_id', 'user_id', 'role_id', 'added_by'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
