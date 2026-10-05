<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Dashboard\WidgetType;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DashboardWidget extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'dashboard_id', 'title', 'type', 'metrics', 'dimension', 'filter_overrides',
        'grid_column', 'grid_row', 'grid_width', 'grid_height', 'options',
    ];

    /** @return BelongsTo<Dashboard, $this> */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WidgetType::class,
            'metrics' => 'array',
            /*
             * Not defaulted to an array. Null means "inherit the dashboard's
             * filters" and `{}` means "override them with nothing" — two different
             * instructions, and a cast that collapsed them would silently change
             * what a widget shows.
             */
            'filter_overrides' => 'array',
            'options' => 'array',
            'grid_column' => 'integer',
            'grid_row' => 'integer',
            'grid_width' => 'integer',
            'grid_height' => 'integer',
        ];
    }
}
