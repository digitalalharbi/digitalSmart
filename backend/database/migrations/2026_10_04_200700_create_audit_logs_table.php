<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            // The actor is nullable so system and scheduled actions are still
            // recorded; nullOnDelete keeps the entry when a user is removed,
            // because an audit trail that disappears is not an audit trail.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();
            $table->string('summary')->nullable();
            // Before/after attribute diff, with sensitive keys redacted.
            $table->jsonb('changes')->default('{}');
            $table->jsonb('context')->default('{}');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
