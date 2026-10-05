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
        // Every sync attempt, successful or not. This is what answers "why is
        // yesterday missing?" without reading application logs.
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('provider_connection_id')->nullable()
                ->constrained('provider_connections')->nullOnDelete();
            $table->foreignUuid('external_account_id')->nullable()
                ->constrained('external_accounts')->nullOnDelete();
            $table->string('provider');
            $table->string('kind');
            $table->string('status')->default('pending');

            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();

            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_written')->default(0);
            $table->unsignedInteger('api_calls')->default(0);

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->text('error_message')->nullable();
            $table->string('error_code')->nullable();
            $table->boolean('retryable')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['external_account_id', 'kind', 'created_at']);
        });

        DB::statement("ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_status_check CHECK (status IN ('pending','running','succeeded','partial','failed','skipped'))");
        DB::statement("ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_kind_check CHECK (kind IN ('accounts','campaigns','ad_groups','ads','creatives','insights','conversions'))");

        /*
         * Where the last sync got to.
         *
         * Two different things, because providers offer two different
         * mechanisms: an opaque pagination cursor for structure, and a
         * watermark date for time-series. Insights also need re-syncing
         * backwards, since conversions keep landing against past dates for days
         * — which is what `resync_from` is for.
         */
        Schema::create('sync_cursors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')->constrained('external_accounts')->cascadeOnDelete();
            $table->string('kind');

            $table->text('cursor')->nullable();
            $table->date('synced_through')->nullable();
            $table->date('resync_from')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['external_account_id', 'kind']);
            $table->index('workspace_id');
        });

        DB::statement("ALTER TABLE sync_cursors ADD CONSTRAINT sync_cursors_kind_check CHECK (kind IN ('accounts','campaigns','ad_groups','ads','creatives','insights','conversions'))");

        // Normalised provider failures, so rate limits and auth expiry are
        // visible as patterns rather than buried in a log file.
        Schema::create('provider_errors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('sync_run_id')->nullable()->constrained('sync_runs')->cascadeOnDelete();
            $table->string('provider');
            $table->string('category')->default('unknown');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('provider_code')->nullable();
            $table->text('message');
            $table->boolean('retryable')->default(false);
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->jsonb('context')->default('{}');
            $table->timestampTz('created_at')->nullable();

            $table->index(['workspace_id', 'provider', 'created_at']);
            $table->index(['category', 'created_at']);
        });

        DB::statement("ALTER TABLE provider_errors ADD CONSTRAINT provider_errors_category_check CHECK (category IN ('auth','rate_limit','permission','not_found','validation','transport','server','unknown'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_errors');
        Schema::dropIfExists('sync_cursors');
        Schema::dropIfExists('sync_runs');
    }
};
