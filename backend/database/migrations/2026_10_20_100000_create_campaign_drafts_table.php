<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign planned across platforms, one row per platform, before it exists anywhere.
 *
 * Always paused: a draft records what would be made, in the platform's terms, with what the platform could not take.
 * Each has a launch request a person with budget authority approves; nothing here creates a campaign on a platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id');
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('name');
            $table->string('goal', 32);
            $table->string('provider_objective', 64)->nullable();
            $table->bigInteger('daily_budget_minor');
            $table->char('currency', 3);
            $table->string('status', 16)->default('paused');
            $table->jsonb('translation');
            $table->foreignUuid('approval_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['workspace_id', 'batch_id']);
        });

        DB::statement("ALTER TABLE campaign_drafts ADD CONSTRAINT campaign_drafts_status_check CHECK (status IN ('paused'))");
        DB::statement('ALTER TABLE campaign_drafts ADD CONSTRAINT campaign_drafts_budget_check CHECK (daily_budget_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_drafts');
    }
};
