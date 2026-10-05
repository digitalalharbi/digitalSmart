<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Budget\BudgetStatus;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Budget extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'client_id', 'project_id', 'name', 'period_start',
        'period_end', 'amount_minor', 'currency', 'status', 'notes',
        'created_by', 'approved_by', 'approved_at',
    ];

    /**
     * Every allocation, at every level, ordered so a tree can be rebuilt in one pass.
     *
     * @return HasMany<BudgetAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetAllocation::class)
            ->orderBy('position')
            ->orderBy('label');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'amount_minor' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
