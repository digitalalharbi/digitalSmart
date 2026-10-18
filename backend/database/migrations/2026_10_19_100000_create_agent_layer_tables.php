<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the agent layer needs that the product did not already have.
 *
 *  - `operating_rules`: a client's or a project's own targets and limits — what "good" means for them, and the
 *    ceilings no recommendation may cross. Agents read these before saying anything.
 *  - `agent_runs`: that an agent looked, when, and what it came to.
 *  - `agent_tool_calls`: every tool call through the gateway, kept, whoever made it — a person, an agent or an
 *    outside MCP client. Append-only.
 *
 * An agent's proposal is an approval request, not a second kind of record: the approvals module already has the
 * fingerprint, the decision, the carry-out and the read-back. The columns added to it say who proposed, on what
 * evidence, and when the proposal stops being worth deciding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            /* Null means "not set at this level": the client's value (or the agency's) applies. */
            $table->string('primary_kpi')->nullable();
            /* Money in the project's own currency, minor units. */
            $table->unsignedBigInteger('target_cpa_minor')->nullable();
            $table->unsignedBigInteger('target_cpl_minor')->nullable();
            $table->decimal('target_roas', 8, 2)->nullable();
            /* Below this spend a campaign is not judged: two days of data is a mood, not a verdict. */
            $table->unsignedBigInteger('min_spend_minor')->nullable();
            $table->unsignedBigInteger('max_daily_budget_minor')->nullable();
            $table->unsignedSmallInteger('max_budget_change_pct')->nullable();
            $table->string('revenue_authority')->nullable();
            $table->jsonb('forbidden_terms')->default('[]');
            $table->jsonb('important_dates')->default('[]');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'client_id', 'project_id']);
        });

        DB::statement('ALTER TABLE operating_rules ADD CONSTRAINT operating_rules_scope_check CHECK (client_id IS NULL OR project_id IS NULL)');
        DB::statement("ALTER TABLE operating_rules ADD CONSTRAINT operating_rules_kpi_check CHECK (primary_kpi IS NULL OR primary_kpi IN ('roas','cpa','cpl','leads','purchases','reach','clicks'))");
        DB::statement("ALTER TABLE operating_rules ADD CONSTRAINT operating_rules_authority_check CHECK (revenue_authority IS NULL OR revenue_authority IN ('platform_reported','ga4','store','crm','attributed','manual'))");
        DB::statement('ALTER TABLE operating_rules ADD CONSTRAINT operating_rules_change_check CHECK (max_budget_change_pct IS NULL OR max_budget_change_pct BETWEEN 1 AND 100)');

        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('agent');
            $table->string('status')->default('analysing');
            $table->unsignedInteger('looked_at')->default(0);
            $table->unsignedInteger('proposed')->default(0);
            $table->jsonb('summary')->default('{}');
            $table->text('failure')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index(['workspace_id', 'agent', 'started_at']);
        });

        DB::statement("ALTER TABLE agent_runs ADD CONSTRAINT agent_runs_status_check CHECK (status IN ('analysing','preparing','waiting_approval','completed','failed'))");

        Schema::create('agent_tool_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tool');
            $table->string('channel')->default('internal');
            $table->jsonb('input')->default('{}');
            $table->string('outcome');
            $table->string('error_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('created_at');

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'tool']);
        });

        DB::statement("ALTER TABLE agent_tool_calls ADD CONSTRAINT agent_tool_calls_outcome_check CHECK (outcome IN ('ok','refused','failed'))");

        Schema::table('approval_requests', function (Blueprint $table): void {
            /* Who proposed: a person, or one of the agents. */
            $table->string('origin')->default('person');
            $table->string('agent')->nullable();
            /* What the proposal rests on: figures and the target compared against, never prose. */
            $table->jsonb('evidence')->nullable();
            $table->string('risk')->nullable();
            /* After this a proposal is not worth deciding any more — the figures it rests on have moved. */
            $table->timestampTz('expires_at')->nullable();
        });

        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_origin_check CHECK (origin IN ('person','agent'))");
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_risk_check CHECK (risk IS NULL OR risk IN ('low','medium','high'))");
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->dropColumn(['origin', 'agent', 'evidence', 'risk', 'expires_at']);
        });
        Schema::dropIfExists('agent_tool_calls');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('operating_rules');
    }
};
