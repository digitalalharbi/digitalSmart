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
         * The fact table. Expected to be the largest in the system.
         *
         * One row per entity per day per level. Levels are stored separately
         * rather than rolled up, because providers do not guarantee a parent's
         * figures equal the sum of its children: reach is deduplicated, and
         * attribution windows move conversions between levels. Summing ad-level
         * reach to get campaign reach yields a number no platform would agree
         * with.
         *
         * Every count is nullable. A null means the provider did not report the
         * metric; zero means it reported none. Conflating them turns a
         * reporting gap into a performance story.
         */
        Schema::create('daily_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')->constrained('external_accounts')->cascadeOnDelete();
            $table->string('provider');

            $table->string('entity_type');
            // Our own id for the entity at that level. Not a foreign key:
            // it points at one of four tables depending on entity_type.
            $table->uuid('entity_id');
            $table->string('external_entity_id');

            // Denormalised so the common filters need no joins at all.
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();
            $table->foreignUuid('ad_group_id')->nullable()->constrained('ad_groups')->cascadeOnDelete();
            $table->foreignUuid('ad_id')->nullable()->constrained('ads')->cascadeOnDelete();

            // The account's own date, in the account's own timezone. Storing a
            // date rather than a timestamp is deliberate: a platform's "day" is
            // defined in its account timezone, and converting would shift spend
            // between days.
            $table->date('date');
            $table->string('account_timezone')->nullable();
            $table->string('currency', 3);

            // Spend and revenue in minor units. Never floats.
            $table->bigInteger('spend_minor')->nullable();
            $table->bigInteger('revenue_minor')->nullable();

            $table->bigInteger('impressions')->nullable();
            $table->bigInteger('reach')->nullable();
            $table->bigInteger('clicks')->nullable();
            $table->bigInteger('link_clicks')->nullable();
            $table->bigInteger('video_views')->nullable();
            $table->bigInteger('video_completions')->nullable();
            $table->bigInteger('engagements')->nullable();
            // Google reports conversions as a fractional value, so this is not
            // an integer.
            $table->decimal('conversions', 14, 4)->nullable();

            // How much this number can be trusted. Never absent.
            $table->string('source')->default('synced');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            /*
             * Idempotency, enforced by the database rather than by the sync
             * remembering to check: re-running a sync for a date updates the
             * row instead of adding a second one.
             */
            $table->unique(['entity_type', 'entity_id', 'date'], 'daily_metrics_entity_date_unique');

            // The shape of almost every analytics query.
            $table->index(['workspace_id', 'date']);
            $table->index(['external_account_id', 'date']);
            $table->index(['campaign_id', 'date']);
            $table->index(['workspace_id', 'provider', 'date']);
            $table->index('source');
        });

        DB::statement("ALTER TABLE daily_metrics ADD CONSTRAINT daily_metrics_entity_type_check CHECK (entity_type IN ('account','campaign','ad_group','ad'))");
        DB::statement("ALTER TABLE daily_metrics ADD CONSTRAINT daily_metrics_source_check CHECK (source IN ('live','synced','stale','partial','sandbox','manual','estimated'))");
        // Spend cannot be negative; refunds and credits are separate adjustments.
        DB::statement('ALTER TABLE daily_metrics ADD CONSTRAINT daily_metrics_spend_non_negative CHECK (spend_minor IS NULL OR spend_minor >= 0)');
        DB::statement('ALTER TABLE daily_metrics ADD CONSTRAINT daily_metrics_impressions_non_negative CHECK (impressions IS NULL OR impressions >= 0)');
        // Reach is people, impressions are views: reach can never exceed them.
        DB::statement('ALTER TABLE daily_metrics ADD CONSTRAINT daily_metrics_reach_le_impressions CHECK (reach IS NULL OR impressions IS NULL OR reach <= impressions)');
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
