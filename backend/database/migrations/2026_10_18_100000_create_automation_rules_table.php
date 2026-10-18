<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules that watch campaigns and say so — trigger, condition, outcome.
 *
 * A rule never changes a campaign. Its only outcome is an item in the attention queue, which is where a person
 * decides what to do (and where pausing is a request someone approves). That is a deliberate limit: nothing here
 * can silently move client money.
 *
 * Scope is a client, a project, or neither (the whole agency), never both: a project already belongs to a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('metric');
            $table->string('operator');
            $table->decimal('threshold', 14, 4)->nullable();
            $table->unsignedSmallInteger('window_days')->default(7);
            $table->string('severity')->default('needs_attention');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'is_active']);
        });

        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_metric_check CHECK (metric IN ('cost_per_result','roas','budget_used','no_results'))");
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_operator_check CHECK (operator IN ('above','below'))");
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_severity_check CHECK (severity IN ('watch','needs_attention','critical'))");
        DB::statement('ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_scope_check CHECK (client_id IS NULL OR project_id IS NULL)');
        DB::statement('ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_window_check CHECK (window_days BETWEEN 1 AND 90)');
        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_threshold_check CHECK ((metric = 'no_results') = (threshold IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
