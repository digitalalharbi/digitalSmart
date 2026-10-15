<?php

declare(strict_types=1);

use App\Domain\SurfaceView\SurfaceKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saved filter sets for the agency surfaces — control center, budget control, leads.
 *
 * Separate from `saved_views`, which stores a validated analytics query: these store *choices a person made
 * on a list*, and putting them in the analytics table would either loosen that validation or bend this to it.
 * `filters` is JSONB because it is never filtered or aggregated in SQL; the surface's own allowlist decides
 * what may be in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surface_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('surface');
            $table->string('name');
            $table->string('visibility')->default('private');
            $table->jsonb('filters')->default('{}');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'created_by', 'surface', 'name']);
            $table->index(['workspace_id', 'surface', 'visibility']);
        });

        DB::statement(sprintf("ALTER TABLE surface_views ADD CONSTRAINT surface_views_surface_check CHECK (surface IN ('%s'))", implode("','", SurfaceKind::values())));
        DB::statement("ALTER TABLE surface_views ADD CONSTRAINT surface_views_visibility_check CHECK (visibility IN ('private','workspace','project'))");
        DB::statement("ALTER TABLE surface_views ADD CONSTRAINT surface_views_project_scope_check CHECK ((visibility = 'project') = (project_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('surface_views');
    }
};
