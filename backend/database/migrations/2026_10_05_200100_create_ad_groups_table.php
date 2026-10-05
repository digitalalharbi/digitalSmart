<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta calls these ad sets, Google and TikTok ad groups, X line items,
        // LinkedIn campaigns. One table, one vocabulary, raw names preserved.
        Schema::create('ad_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')->constrained('external_accounts')->cascadeOnDelete();
            $table->string('provider');

            $table->string('external_id');
            $table->string('name');
            $table->string('status')->default('unknown');
            $table->string('raw_status')->nullable();
            $table->string('raw_effective_status')->nullable();

            // What the platform is told to optimise for, and what it bills on.
            $table->string('optimization_goal')->nullable();
            $table->string('billing_event')->nullable();
            $table->bigInteger('bid_amount_minor')->nullable();
            $table->string('budget_mode')->default('unknown');
            $table->bigInteger('budget_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('provider_updated_at')->nullable();

            // Targeting is huge, provider-shaped and never filtered in SQL.
            $table->jsonb('targeting')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['external_account_id', 'external_id']);
            $table->index(['campaign_id', 'status']);
            $table->index(['workspace_id', 'provider']);
        });

        DB::statement("ALTER TABLE ad_groups ADD CONSTRAINT ad_groups_status_check CHECK (status IN ('active','paused','not_delivering','in_review','rejected','completed','archived','removed','unknown'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_groups');
    }
};
