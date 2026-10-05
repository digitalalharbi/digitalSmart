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
        Schema::create('provider_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('provider');
            $table->string('name');
            $table->string('status')->default('not_configured');
            // Encrypted blob. Never queried, never logged, never returned by an
            // API resource — see App\Domain\Integration\Credentials.
            $table->text('credentials')->nullable();
            $table->string('external_owner_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->text('status_message')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // One connection per provider per workspace: two live connections to
            // the same platform would make "which credentials synced this?"
            // unanswerable.
            $table->unique(['workspace_id', 'provider']);
            $table->index(['workspace_id', 'status']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE provider_connections ADD CONSTRAINT provider_connections_status_check CHECK (status IN ('not_configured', 'configured', 'connected', 'expired', 'revoked', 'error'))");
        DB::statement("ALTER TABLE provider_connections ADD CONSTRAINT provider_connections_provider_check CHECK (provider IN ('meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin', 'openai'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connections');
    }
};
