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
        /*
         * A saved analytics query.
         *
         * The query is stored as JSONB rather than as columns, and that is a
         * deliberate trade: it is never filtered or aggregated in SQL, its shape
         * follows the analytics request object which will keep growing, and a
         * column per filter would mean a migration every time a new one is added.
         * What it costs is that an invalid query is only caught on read — which is
         * why the request object validates it again on the way out.
         */
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            // Who made it. Kept even when they leave, so a shared view does not
            // vanish from under the people using it.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Set only for project-scoped visibility.
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('visibility')->default('private');
            $table->jsonb('query')->default('{}');
            // The view a reader lands on. At most one per person per workspace.
            $table->boolean('is_default')->default(false);

            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            // Two views with the same name in one workspace would be indistinguishable
            // in a picker, which is the only place they appear.
            $table->unique(['workspace_id', 'created_by', 'name']);
            $table->index(['workspace_id', 'visibility']);
            $table->index(['project_id', 'visibility']);
        });

        DB::statement("ALTER TABLE saved_views ADD CONSTRAINT saved_views_visibility_check CHECK (visibility IN ('private','workspace','project'))");
        /*
         * Project visibility without a project has no defined audience, so the
         * database refuses it rather than leaving the application to remember.
         */
        DB::statement("ALTER TABLE saved_views ADD CONSTRAINT saved_views_project_scope_check CHECK (visibility <> 'project' OR project_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
