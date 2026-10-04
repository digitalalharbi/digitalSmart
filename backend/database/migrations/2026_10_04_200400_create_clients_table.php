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
        Schema::create('clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('legal_name')->nullable();
            $table->string('status')->default('active');
            $table->string('industry')->nullable();
            $table->string('website')->nullable();
            $table->string('primary_contact_name')->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->string('primary_contact_phone')->nullable();
            $table->string('default_currency', 3)->nullable();
            $table->text('notes')->nullable();
            // Demo/sandbox records must never be mistaken for real client data.
            $table->boolean('is_demo')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status']);
            $table->index('is_demo');
        });

        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('prospect', 'active', 'paused', 'archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
