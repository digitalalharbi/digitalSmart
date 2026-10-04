<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogue of every capability the platform can grant. Seeded from
        // code, never user-editable: a permission without an enforcement point
        // is a lie.
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('group');
            $table->string('description');
            $table->timestampsTz();

            $table->index('group');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // System roles are workspace-independent templates; a null
            // workspace_id marks them.
            $table->foreignUuid('workspace_id')->nullable()
                ->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['workspace_id', 'slug']);
            $table->index('is_system');
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
