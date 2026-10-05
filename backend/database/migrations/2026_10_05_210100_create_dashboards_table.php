<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('visibility')->default('private');

            /*
             * The dashboard's own filters and date range, which its widgets inherit
             * unless they override them. Stored with the dashboard rather than per
             * widget so changing the period changes the whole canvas at once, which
             * is what a reader expects of a dashboard and not of a report.
             */
            $table->jsonb('filters')->default('{}');
            $table->boolean('is_default')->default(false);

            $table->timestampTz('last_viewed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'created_by', 'name']);
            $table->index(['workspace_id', 'visibility']);
            $table->index(['project_id', 'visibility']);
        });

        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_visibility_check CHECK (visibility IN ('private','workspace','project'))");
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_project_scope_check CHECK (visibility <> 'project' OR project_id IS NOT NULL)");

        Schema::create('dashboard_widgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('dashboard_id')->constrained('dashboards')->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->string('type');

            // What it asks for. A list, because a scatter needs two and a trend may
            // plot a second line.
            $table->jsonb('metrics')->default('[]');
            $table->string('dimension')->nullable();
            /*
             * Filters this widget adds to the dashboard's own, or null to inherit
             * them unchanged. Null and `{}` are different: the first means "inherit",
             * the second means "override with nothing", and a dashboard needs both.
             */
            $table->jsonb('filter_overrides')->nullable();

            // Placement on the 12-column canvas.
            $table->unsignedSmallInteger('grid_column')->default(0);
            $table->unsignedSmallInteger('grid_row')->default(0);
            $table->unsignedSmallInteger('grid_width')->default(6);
            $table->unsignedSmallInteger('grid_height')->default(2);

            $table->jsonb('options')->default('{}');
            $table->timestampsTz();

            $table->index(['dashboard_id', 'grid_row', 'grid_column']);
        });

        DB::statement("ALTER TABLE dashboard_widgets ADD CONSTRAINT dashboard_widgets_type_check CHECK (type IN ('scorecard','line','bar','share','scatter','table'))");
        // The grid's own rules, so a widget cannot be stored outside the canvas and
        // disappear with nothing to say it was there.
        DB::statement('ALTER TABLE dashboard_widgets ADD CONSTRAINT dashboard_widgets_grid_bounds CHECK (grid_column + grid_width <= 12 AND grid_width >= 3 AND grid_height BETWEEN 1 AND 6)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};
