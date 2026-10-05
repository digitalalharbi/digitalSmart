<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Dashboard\Visibility;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Dashboard extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'created_by', 'project_id', 'name', 'description',
        'visibility', 'filters', 'is_default', 'last_viewed_at',
    ];

    /** @return HasMany<DashboardWidget, $this> */
    public function widgets(): HasMany
    {
        // Ordered as the canvas reads: top to bottom, then along each row. The
        // client never has to sort them, so two clients cannot sort them differently.
        return $this->hasMany(DashboardWidget::class)
            ->orderBy('grid_row')
            ->orderBy('grid_column');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'filters' => 'array',
            'is_default' => 'boolean',
            'last_viewed_at' => 'immutable_datetime',
        ];
    }
}
