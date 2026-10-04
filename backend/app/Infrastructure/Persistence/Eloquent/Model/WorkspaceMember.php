<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkspaceMember extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = ['workspace_id', 'user_id', 'role_id', 'status', 'invited_by', 'invited_at', 'joined_at'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'invited_at' => 'immutable_datetime',
            'joined_at' => 'immutable_datetime',
        ];
    }
}
