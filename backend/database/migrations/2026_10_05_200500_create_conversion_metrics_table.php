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
         * Conversions, one row per action type per entity per day.
         *
         * Kept separate from daily_metrics rather than widened into it, because
         * the set of action types is open-ended and provider-defined: a client
         * with twelve custom conversions would otherwise need twelve columns
         * nobody else uses.
         */
        Schema::create('conversion_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')->constrained('external_accounts')->cascadeOnDelete();
            $table->string('provider');

            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();

            $table->date('date');

            // Normalised for cross-platform reporting; raw because the client's
            // own conversion name is what they will ask about.
            $table->string('action_type')->default('other');
            $table->string('raw_action_type');
            $table->string('action_name')->nullable();

            $table->decimal('count', 14, 4)->default(0);
            $table->bigInteger('value_minor')->nullable();
            $table->string('currency', 3)->nullable();

            /*
             * Which attribution window this figure was measured under.
             *
             * Not nullable, and that is load-bearing. PostgreSQL treats NULLs as
             * distinct in a unique index, so a nullable column in the key below
             * would make every re-sync insert a second row instead of updating
             * the first — conversions would double every time a day was re-read,
             * which is exactly what attribution settling requires us to do.
             *
             * 'default' means the account's own configured window, which is a
             * real answer rather than a missing one: it is what the provider
             * reported against when it did not name a window.
             */
            $table->string('attribution_window')->default('default');

            $table->string('source')->default('synced');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(
                ['entity_type', 'entity_id', 'date', 'raw_action_type', 'attribution_window'],
                'conversion_metrics_unique',
            );
            $table->index(['workspace_id', 'date', 'action_type']);
            $table->index(['campaign_id', 'date']);
        });

        DB::statement("ALTER TABLE conversion_metrics ADD CONSTRAINT conversion_metrics_entity_type_check CHECK (entity_type IN ('account','campaign','ad_group','ad'))");
        DB::statement("ALTER TABLE conversion_metrics ADD CONSTRAINT conversion_metrics_source_check CHECK (source IN ('live','synced','stale','partial','sandbox','manual','estimated'))");
        DB::statement('ALTER TABLE conversion_metrics ADD CONSTRAINT conversion_metrics_count_non_negative CHECK (count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_metrics');
    }
};
