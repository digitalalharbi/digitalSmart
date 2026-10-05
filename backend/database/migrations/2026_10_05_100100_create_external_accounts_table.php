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
        Schema::create('external_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Denormalised from the connection so every scoped query filters
            // one indexed column without a join.
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('provider_connection_id')
                ->constrained('provider_connections')->cascadeOnDelete();
            $table->string('provider');
            // The provider's own id for this account.
            $table->string('external_id');
            $table->string('name');
            $table->string('currency', 3)->nullable();
            $table->string('timezone')->nullable();
            $table->string('status')->default('unknown');
            // Provider-specific extras that are never filtered or aggregated.
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('discovered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider_connection_id', 'external_id']);
            $table->index(['workspace_id', 'provider']);
            $table->index(['workspace_id', 'status']);
        });

        DB::statement("ALTER TABLE external_accounts ADD CONSTRAINT external_accounts_status_check CHECK (status IN ('active', 'disabled', 'closed', 'unsettled', 'unknown'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('external_accounts');
    }
};
