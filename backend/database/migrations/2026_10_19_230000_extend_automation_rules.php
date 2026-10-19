<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules become WHEN … AND … FOR n days THEN …, with a trust level a person sets.
 *
 * Existing rules keep their meaning exactly: no extra conditions, one day, alert only, trust 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->jsonb('conditions')->default('[]');
            $table->unsignedSmallInteger('for_days')->default(1);
            $table->string('action', 32)->default('alert');
            $table->unsignedSmallInteger('action_pct')->nullable();
            $table->unsignedSmallInteger('trust_level')->default(2);
        });

        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT automation_rules_metric_check');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_metric_check CHECK (metric IN ('cost_per_result','roas','budget_used','no_results','rejected','ends_within'))");
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT automation_rules_threshold_check');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_threshold_check CHECK ((metric IN ('no_results','rejected')) = (threshold IS NULL))");
        DB::statement('ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_for_days_check CHECK (for_days BETWEEN 1 AND 30)');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_action_check CHECK (action IN ('alert','propose_pause','propose_budget_cut','task'))");
        /* Level 4 is not storable: changing a platform without approval is not something a rule can be given. */
        DB::statement('ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_trust_check CHECK (trust_level BETWEEN 0 AND 3)');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_pct_check CHECK ((action = 'propose_budget_cut') = (action_pct IS NOT NULL) AND (action_pct IS NULL OR action_pct BETWEEN 1 AND 50))");

        /* How long each rule has held for each campaign, so "for three days" means three days. */
        Schema::create('automation_rule_streaks', function (Blueprint $table): void {
            $table->foreignUuid('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->date('first_seen_on');
            $table->date('last_seen_on');
            $table->timestampTz('acted_at')->nullable();
            $table->primary(['rule_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_streaks');
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropColumn(['conditions', 'for_days', 'action', 'action_pct', 'trust_level']);
        });
    }
};
