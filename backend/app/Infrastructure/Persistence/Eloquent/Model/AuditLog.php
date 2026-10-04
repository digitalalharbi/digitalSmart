<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. There is no update path and no soft delete: an audit entry that
 * can be edited is not evidence of anything.
 */
final class AuditLog extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'actor_id', 'actor_label', 'action', 'auditable_type', 'auditable_id',
        'summary', 'changes', 'context', 'ip_address', 'user_agent', 'request_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
