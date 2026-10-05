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
        Schema::create('ads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignUuid('ad_group_id')->constrained('ad_groups')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')->constrained('external_accounts')->cascadeOnDelete();
            // Creatives can be shared across ads, and an ad may be synced before
            // its creative is, so this is nullable and does not cascade-delete.
            $table->foreignUuid('creative_id')->nullable()->constrained('creatives')->nullOnDelete();
            $table->string('provider');

            $table->string('external_id');
            $table->string('name');
            $table->string('status')->default('unknown');
            $table->string('raw_status')->nullable();
            $table->string('raw_effective_status')->nullable();
            // Why a platform rejected an ad is operationally important.
            $table->text('review_feedback')->nullable();

            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('provider_updated_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['external_account_id', 'external_id']);
            $table->index(['ad_group_id', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index('creative_id');
        });

        DB::statement("ALTER TABLE ads ADD CONSTRAINT ads_status_check CHECK (status IN ('active','paused','not_delivering','in_review','rejected','completed','archived','removed','unknown'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
