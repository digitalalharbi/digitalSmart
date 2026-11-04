<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Acquisition traffic read from an analytics property, one row per day and acquisition combination.
 *
 * Aggregate counts only. Every metric is nullable: null means the property did not report it, which is not zero. The
 * natural key makes a re-read of the restatement window replace rather than accumulate. `source` carries the honesty
 * label (sampled, thresholded, sandbox) on the row so a query across days can see it without a sync journal.
 *
 * `users` and `new_users` are per-row only: a user seen on two days is one person, so these columns must never be
 * summed across rows. The read model refuses to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga4_traffic_daily', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('measurement_source_id')->constrained('measurement_sources')->cascadeOnDelete();

            $table->date('date');
            $table->string('channel_group');
            $table->string('source_name');
            $table->string('medium');

            $table->bigInteger('sessions')->nullable();
            $table->bigInteger('users')->nullable();
            $table->bigInteger('new_users')->nullable();
            $table->bigInteger('engaged_sessions')->nullable();
            $table->bigInteger('engagement_seconds')->nullable();
            $table->bigInteger('page_views')->nullable();

            /** The property's own time zone, so a day is read in the zone it was cut in. */
            $table->string('time_zone')->nullable();
            $table->string('source')->default('synced');
            $table->timestamps();

            $table->unique(
                ['measurement_source_id', 'date', 'channel_group', 'source_name', 'medium'],
                'ga4_traffic_daily_unique',
            );
            $table->index(['workspace_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga4_traffic_daily');
    }
};
