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
        Schema::create('creatives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')->constrained('external_accounts')->cascadeOnDelete();
            $table->string('provider');

            $table->string('external_id');
            $table->string('name');
            $table->string('format')->default('unknown');
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            $table->string('call_to_action')->nullable();
            $table->text('destination_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('preview_url')->nullable();

            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['external_account_id', 'external_id']);
            $table->index(['workspace_id', 'format']);
        });

        DB::statement("ALTER TABLE creatives ADD CONSTRAINT creatives_format_check CHECK (format IN ('image','video','carousel','collection','text','responsive_search','responsive_display','unknown'))");

        // One creative can carry several assets: a carousel's cards, a video
        // plus its thumbnail, a responsive ad's image variants.
        Schema::create('creative_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('creative_id')->constrained('creatives')->cascadeOnDelete();
            $table->string('kind')->default('image');
            $table->text('url')->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['creative_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_assets');
        Schema::dropIfExists('creatives');
    }
};
