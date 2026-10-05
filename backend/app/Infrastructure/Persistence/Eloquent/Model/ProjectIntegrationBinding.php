<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The row that grants one project access to one external ad account. */
final class ProjectIntegrationBinding extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'project_id', 'external_account_id',
        'sync_enabled', 'bound_by', 'bound_at', 'last_synced_at',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ExternalAccount, $this> */
    public function externalAccount(): BelongsTo
    {
        return $this->belongsTo(ExternalAccount::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'bound_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
