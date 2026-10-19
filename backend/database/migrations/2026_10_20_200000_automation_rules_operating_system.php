<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules become an operating system: an explicit mode, AND/OR logic, a scope down to platform, account and campaign,
 * a schedule in a time zone, a cooldown — and a history of every action a rule took or was refused.
 *
 * Existing rules keep their meaning: mode follows their trust level, logic is AND, scope as before, hourly, 24 h.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->string('mode', 16)->default('recommend');
            $table->string('logic', 8)->default('all');
            $table->jsonb('providers')->default('[]');
            $table->jsonb('external_account_ids')->default('[]');
            $table->jsonb('campaign_ids')->default('[]');
            $table->string('object_level', 16)->default('campaign');
            $table->string('schedule', 16)->default('hourly');
            $table->string('schedule_time', 5)->nullable();
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->unsignedSmallInteger('cooldown_hours')->default(24);
            $table->timestampTz('last_run_at')->nullable();
            $table->jsonb('last_result')->nullable();
            $table->timestampTz('archived_at')->nullable();
        });

        DB::statement("UPDATE automation_rules SET mode = CASE WHEN trust_level <= 1 OR action = 'alert' THEN 'monitor' ELSE 'recommend' END");

        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT automation_rules_metric_check');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_metric_check CHECK (metric IN ('cost_per_result','roas','budget_used','no_results','rejected','ends_within','spend','conversions','ctr','cpc','cpm','campaign_age','not_delivering','cost_change'))");
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT automation_rules_threshold_check');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_threshold_check CHECK ((metric IN ('no_results','rejected','not_delivering')) = (threshold IS NULL))");
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT automation_rules_action_check');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_action_check CHECK (action IN ('alert','propose_pause','propose_resume','propose_budget_cut','propose_budget_increase','propose_bid_change','task'))");
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT automation_rules_pct_check');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_pct_check CHECK ((action IN ('propose_budget_cut','propose_budget_increase','propose_bid_change')) = (action_pct IS NOT NULL) AND (action_pct IS NULL OR action_pct BETWEEN 1 AND 50))");
        /* Auto cannot be stored until the owner authorises it: a schema that admitted it would make the refusal advisory. */
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_mode_check CHECK (mode IN ('monitor','recommend'))");
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_logic_check CHECK (logic IN ('all','any'))");
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_level_check CHECK (object_level IN ('campaign','ad_group','ad'))");
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_schedule_check CHECK (schedule IN ('continuous','hourly','daily') AND (schedule = 'daily') = (schedule_time IS NOT NULL))");
        DB::statement('ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_cooldown_check CHECK (cooldown_hours BETWEEN 1 AND 720)');

        /* Every action a rule took, was refused, or found unnecessary — with the figures before and after. */
        Schema::create('automation_rule_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('provider', 32)->nullable();
            $table->string('object_level', 16);
            $table->string('object_label');
            $table->string('action', 32);
            $table->jsonb('before')->default('{}');
            $table->jsonb('after')->default('{}');
            $table->string('result', 24);
            $table->string('reason_code', 64)->nullable();
            $table->foreignUuid('approval_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->timestampTz('created_at');

            $table->index(['workspace_id', 'created_at']);
            $table->index(['rule_id', 'campaign_id', 'created_at']);
        });

        DB::statement("ALTER TABLE automation_rule_executions ADD CONSTRAINT automation_rule_executions_result_check CHECK (result IN ('completed','no_change','waiting_approval','failed','unsupported'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_executions');
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT IF EXISTS automation_rules_mode_check');
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT IF EXISTS automation_rules_logic_check');
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT IF EXISTS automation_rules_level_check');
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT IF EXISTS automation_rules_schedule_check');
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT IF EXISTS automation_rules_cooldown_check');
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'logic', 'providers', 'external_account_ids', 'campaign_ids', 'object_level', 'schedule', 'schedule_time', 'timezone', 'cooldown_hours', 'last_run_at', 'last_result', 'archived_at']);
        });
    }
};
