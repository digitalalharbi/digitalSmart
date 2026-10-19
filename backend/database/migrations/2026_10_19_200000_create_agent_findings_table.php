<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the specialist agents have noticed and still see.
 *
 * A finding is open while the agent keeps seeing it and closes itself on the first run that no longer does — so the
 * Today screen never carries a stale card, and the history of when a problem started and ended is kept. Text is
 * never stored as an instruction: the code and its values are what the interface renders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('agent', 32);
            $table->string('code', 64);
            $table->string('subject_kind', 32);
            $table->string('subject_id', 64);
            $table->string('subject_label');
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('severity', 24);
            $table->text('message');
            $table->jsonb('params')->default('{}');
            $table->jsonb('evidence')->default('{}');
            $table->uuid('task_id')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'resolved_at']);
            $table->index(['workspace_id', 'agent']);
        });

        DB::statement("ALTER TABLE agent_findings ADD CONSTRAINT agent_findings_severity_check CHECK (severity IN ('watch', 'needs_attention', 'critical'))");
        DB::statement("ALTER TABLE agent_findings ADD CONSTRAINT agent_findings_agent_check CHECK (agent IN ('creative', 'leads', 'reports', 'operations'))");
        /* One open finding per thing per agent: a second run refreshes it rather than stacking a duplicate card. */
        DB::statement('CREATE UNIQUE INDEX agent_findings_open_unique ON agent_findings (workspace_id, agent, code, subject_id) WHERE resolved_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_findings');
    }
};
