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
        Schema::create('workspace_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Restricted, not cascaded: deleting a role must not silently strip
            // people of their access.
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('status')->default('active');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            // One membership per user per workspace.
            $table->unique(['workspace_id', 'user_id']);
            $table->index(['workspace_id', 'status']);
        });

        DB::statement("ALTER TABLE workspace_members ADD CONSTRAINT workspace_members_status_check CHECK (status IN ('invited', 'active', 'suspended'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
