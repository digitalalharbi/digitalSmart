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
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')->constrained('external_accounts')->cascadeOnDelete();
            $table->string('provider');

            // The platform's own id. Never regenerated: it is how we recognise
            // this campaign again on the next sync, and how an operator finds it
            // in the platform's own interface.
            $table->string('external_id');
            $table->string('name');

            // Normalised for cross-platform queries, raw for the truth.
            $table->string('objective')->default('other');
            $table->string('raw_objective')->nullable();
            $table->string('status')->default('unknown');
            $table->string('raw_status')->nullable();
            // Meta's effective_status folds in parent state and review outcome;
            // it explains "active but not delivering" where status cannot.
            $table->string('raw_effective_status')->nullable();

            $table->string('buying_type')->nullable();
            $table->string('bid_strategy')->nullable();
            $table->string('budget_mode')->default('unknown');
            $table->bigInteger('budget_minor')->nullable();
            $table->bigInteger('spend_cap_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // The platform's own timestamps, kept distinct from ours.
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('provider_updated_at')->nullable();

            // Provider-specific extras that are never filtered or aggregated.
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['external_account_id', 'external_id']);
            $table->index(['workspace_id', 'provider', 'status']);
            $table->index(['workspace_id', 'objective']);
            $table->index('synced_at');
        });

        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check CHECK (status IN ('active','paused','not_delivering','in_review','rejected','completed','archived','removed','unknown'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_budget_mode_check CHECK (budget_mode IN ('daily','lifetime','inherited','unlimited','unknown'))");
        // Budgets are minor units and cannot be negative.
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_budget_non_negative CHECK (budget_minor IS NULL OR budget_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
