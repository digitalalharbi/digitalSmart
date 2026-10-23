<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project tracking settings. One row per project; a project with no row uses the defaults.
 *
 * Starts with the data layer names. The consent policy, the retention window and the dispatch rules are added to this same
 * row by the dispatch change, so a project's tracking behaviour lives in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_settings', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            /* browser event => dataLayer event name; only the overrides, defaults are applied on read */
            $table->jsonb('datalayer')->default('{}');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_settings');
    }
};
