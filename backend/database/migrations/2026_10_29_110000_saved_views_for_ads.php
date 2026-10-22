<?php

declare(strict_types=1);

use App\Domain\SurfaceView\SurfaceKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ads is a surface of its own, so its views can be saved. The CHECK is re-derived from the enum rather than
 * typed out again: a list written twice drifts, and the stale copy is the one in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE surface_views DROP CONSTRAINT surface_views_surface_check');
        DB::statement(sprintf(
            "ALTER TABLE surface_views ADD CONSTRAINT surface_views_surface_check CHECK (surface IN ('%s'))",
            implode("','", SurfaceKind::values()),
        ));
    }

    public function down(): void
    {
        DB::table('surface_views')->where('surface', SurfaceKind::Ads->value)->delete();

        DB::statement('ALTER TABLE surface_views DROP CONSTRAINT surface_views_surface_check');
        DB::statement(sprintf(
            "ALTER TABLE surface_views ADD CONSTRAINT surface_views_surface_check CHECK (surface IN ('%s'))",
            implode("','", array_values(array_filter(
                SurfaceKind::values(),
                static fn (string $surface): bool => $surface !== SurfaceKind::Ads->value,
            ))),
        ));
    }
};
