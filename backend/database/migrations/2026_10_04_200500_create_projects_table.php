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
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // workspace_id is denormalised from the client so every scoped query
            // can filter on one indexed column without a join.
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status')->default('planning');
            $table->string('currency', 3);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('planning', 'active', 'paused', 'completed', 'archived'))");
        // An end date before the start date is never valid.
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_dates_check CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
