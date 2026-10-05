<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The isolation rule, as a table.
         *
         * A project reads an external ad account only if a row exists here.
         * There is no inheritance from workspace or client level and no
         * implicit binding, because an agency runs competing clients in one
         * installation and a leak between them is an existential failure.
         */
        Schema::create('project_integration_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')
                ->constrained('external_accounts')->cascadeOnDelete();
            // Pausing is not unbinding: it keeps the relationship and history.
            $table->boolean('sync_enabled')->default(true);
            $table->foreignUuid('bound_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('bound_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'external_account_id']);
            $table->index(['workspace_id', 'project_id']);
            $table->index('external_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_integration_bindings');
    }
};
