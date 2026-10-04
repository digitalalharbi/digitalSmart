<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('default_locale', 5)->default('en');
            $table->string('default_currency', 3)->default('SAR');
            $table->string('timezone')->default('UTC');
            // Workspace-level preferences and feature flags. Nothing here is
            // filtered or aggregated, so JSONB is justified.
            $table->jsonb('settings')->default('{}');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
