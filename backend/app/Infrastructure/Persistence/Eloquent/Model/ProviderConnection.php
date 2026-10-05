<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProviderConnection extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'provider', 'name', 'status', 'credentials',
        'external_owner_id', 'expires_at', 'last_checked_at', 'status_message', 'created_by',
    ];

    /**
     * The encrypted credential blob must never be serialised anywhere.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<ExternalAccount, $this> */
    public function externalAccounts(): HasMany
    {
        return $this->hasMany(ExternalAccount::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
        ];
    }
}
