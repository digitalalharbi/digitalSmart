<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rule judges only campaigns with enough data — a minimum spend, age and number of results — and its history names
 * a cooldown and a prevented conflict as what they are, rather than folding them into "no change".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->decimal('min_spend', 14, 2)->nullable();
            $table->unsignedSmallInteger('min_age_days')->nullable();
            $table->decimal('min_conversions', 14, 2)->nullable();
        });

        DB::statement('ALTER TABLE automation_rule_executions DROP CONSTRAINT automation_rule_executions_result_check');
        DB::statement("ALTER TABLE automation_rule_executions ADD CONSTRAINT automation_rule_executions_result_check CHECK (result IN ('completed','no_change','waiting_approval','failed','unsupported','cooldown','conflict'))");
        DB::statement("UPDATE automation_rule_executions SET result = 'conflict' WHERE reason_code = 'CONFLICT'");
    }

    public function down(): void
    {
        DB::statement("UPDATE automation_rule_executions SET result = 'no_change' WHERE result IN ('cooldown','conflict')");
        DB::statement('ALTER TABLE automation_rule_executions DROP CONSTRAINT automation_rule_executions_result_check');
        DB::statement("ALTER TABLE automation_rule_executions ADD CONSTRAINT automation_rule_executions_result_check CHECK (result IN ('completed','no_change','waiting_approval','failed','unsupported'))");
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropColumn(['min_spend', 'min_age_days', 'min_conversions']);
        });
    }
};
