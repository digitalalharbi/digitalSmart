<?php

declare(strict_types=1);

use App\Domain\Advertising\Launch\ExecutionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * An approval names a fingerprint; a launch records what was attempted on each ad account, how, and what the platform said back.
 * No credential or token is stored anywhere here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_blueprints', function (Blueprint $table): void {
            $table->char('approved_fingerprint', 64)->nullable();
            $table->unsignedInteger('approved_version')->nullable();
        });
        DB::statement('ALTER TABLE campaign_blueprints ADD CONSTRAINT campaign_blueprints_approval_fingerprint_check CHECK ((approved_at IS NULL) = (approved_fingerprint IS NULL))');

        Schema::create('campaign_launches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('blueprint_id')->constrained('campaign_blueprints')->cascadeOnDelete();
            $table->unsignedInteger('blueprint_version');
            $table->char('fingerprint', 64);
            /* The launch plan as approved, kept as it was. */
            $table->jsonb('snapshot');
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['workspace_id', 'blueprint_id']);
        });

        Schema::create('campaign_launch_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('launch_id')->constrained('campaign_launches')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->foreignUuid('external_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt')->default(0);
            /* Stable per launch and account: the same request, however many times it is tried. */
            $table->char('idempotency_reference', 64)->unique();
            $table->string('status', 24)->default(ExecutionStatus::Queued->value);
            $table->string('source', 16)->nullable();
            $table->string('provider_campaign_id', 190)->nullable();
            $table->string('provider_ad_group_id', 190)->nullable();
            $table->jsonb('provider_ad_ids')->default('[]');
            $table->jsonb('readback')->nullable();
            $table->string('failure_classification', 40)->nullable();
            $table->text('message')->nullable();
            $table->string('api_reference', 255)->nullable();
            $table->timestampTz('request_started_at')->nullable();
            $table->timestampTz('request_finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['launch_id', 'external_account_id']);
            $table->index(['workspace_id', 'status']);
        });
        DB::statement(sprintf("ALTER TABLE campaign_launch_executions ADD CONSTRAINT campaign_launch_executions_status_check CHECK (status IN ('%s'))", implode("','", ExecutionStatus::values())));
        DB::statement("ALTER TABLE campaign_launch_executions ADD CONSTRAINT campaign_launch_executions_source_check CHECK (source IS NULL OR source IN ('live','sandbox'))");
        /* Verified means read back: the ids and the read-back travel together. */
        DB::statement("ALTER TABLE campaign_launch_executions ADD CONSTRAINT campaign_launch_executions_verified_check CHECK (status <> 'verified' OR (provider_campaign_id IS NOT NULL AND readback IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_launch_executions');
        Schema::dropIfExists('campaign_launches');
        DB::statement('ALTER TABLE campaign_blueprints DROP CONSTRAINT campaign_blueprints_approval_fingerprint_check');
        Schema::table('campaign_blueprints', function (Blueprint $table): void {
            $table->dropColumn(['approved_fingerprint', 'approved_version']);
        });
    }
};
