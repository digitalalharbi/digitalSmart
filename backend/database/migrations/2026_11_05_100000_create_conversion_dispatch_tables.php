<?php

declare(strict_types=1);

use App\Domain\Tracking\Dispatch\ConsentPolicy;
use App\Domain\Tracking\Dispatch\DispatchStatus;
use App\Domain\Tracking\Dispatch\SkipReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mapping a tracking event to a provider's conversion, the record of every attempt to send one, the project's consent and
 * retention settings, and the daily roll-ups that outlive raw events.
 *
 * A dispatch row exists for every conversion that matched a mapping — sent, failed, unknown *or skipped with a reason* — so
 * "why did Meta not get this lead" is answerable from the row and not from an absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_settings', function (Blueprint $table): void {
            $table->string('consent_policy', 12)->default('required');
            /* Raw events and sessions older than this are rolled up and deleted. */
            $table->unsignedSmallInteger('retention_days')->default(400);
        });

        Schema::create('conversion_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('provider', 32);
            /* The provider's event name, or Google's conversion action resource name. */
            $table->string('provider_event', 190);
            $table->boolean('enabled')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['project_id', 'event', 'provider']);
        });

        Schema::create('provider_event_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mapping_id')->constrained('conversion_mappings')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('provider', 32);
            $table->string('provider_event', 190);
            /* The id shared with the browser's copy, so a provider that can de-duplicate does. */
            $table->string('event_id', 64);
            $table->string('channel', 8)->default('server');
            $table->string('status', 10)->default('pending');
            $table->string('skip_reason', 24)->nullable();
            $table->string('source_state', 10)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_category', 24)->nullable();
            $table->jsonb('click_ids')->default('{}');
            $table->string('provider_ref', 190)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('server_sent_at')->nullable();
            /* Null unless a provider's own response says so. Never inferred. */
            $table->boolean('provider_received')->nullable();
            $table->boolean('deduplicated')->nullable();
            $table->timestampsTz();

            $table->unique(['mapping_id', 'event_id']);
            $table->index(['project_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('tracking_daily_rollups', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('tracking_link_id')->nullable();
            $table->date('day');
            $table->string('event', 40);
            $table->string('traffic_class', 16);
            $table->unsignedInteger('events');
            $table->unsignedInteger('visitors');

            $table->unique(['project_id', 'day', 'event', 'traffic_class', 'tracking_link_id'], 'tracking_rollups_key');
        });

        $in = static fn (array $values): string => "'".implode("','", $values)."'";

        DB::statement('ALTER TABLE tracking_settings ADD CONSTRAINT tracking_settings_consent_check CHECK (consent_policy IN ('.$in(ConsentPolicy::values()).'))');
        DB::statement('ALTER TABLE tracking_settings ADD CONSTRAINT tracking_settings_retention_check CHECK (retention_days BETWEEN 30 AND 1095)');
        DB::statement('ALTER TABLE provider_event_dispatches ADD CONSTRAINT dispatch_status_check CHECK (status IN ('.$in(DispatchStatus::values()).'))');
        DB::statement('ALTER TABLE provider_event_dispatches ADD CONSTRAINT dispatch_skip_check CHECK (skip_reason IS NULL OR skip_reason IN ('.$in(array_map(static fn (SkipReason $r): string => $r->value, SkipReason::cases())).'))');
        DB::statement("ALTER TABLE provider_event_dispatches ADD CONSTRAINT dispatch_skip_has_reason CHECK ((status = 'skipped') = (skip_reason IS NOT NULL))");
        DB::statement("ALTER TABLE provider_event_dispatches ADD CONSTRAINT dispatch_channel_check CHECK (channel IN ('server','offline'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_daily_rollups');
        Schema::dropIfExists('provider_event_dispatches');
        Schema::dropIfExists('conversion_mappings');
        Schema::table('tracking_settings', function (Blueprint $table): void {
            $table->dropColumn(['consent_policy', 'retention_days']);
        });
    }
};
