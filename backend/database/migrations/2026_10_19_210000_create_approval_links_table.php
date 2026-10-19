<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time links to decide an approval from WhatsApp or email.
 *
 * The link's secret is never stored — only its keyed hash — so a database read cannot be turned into a decision. A
 * link names one person and one approval, carries the proposal's fingerprint at the time it was sent, expires, and
 * can be used once. Using it is not a way round the approval rules: the decision is taken as that person, through
 * the same use case a screen uses, with their permissions read at that moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('approval_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 16);
            $table->char('token_hash', 64)->unique();
            $table->string('fingerprint', 128);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->string('outcome', 32)->nullable();
            $table->string('delivery', 32);
            $table->timestampTz('created_at');

            $table->index(['approval_id', 'user_id']);
        });

        DB::statement("ALTER TABLE approval_links ADD CONSTRAINT approval_links_channel_check CHECK (channel IN ('whatsapp', 'email'))");
        DB::statement('ALTER TABLE approval_links ADD CONSTRAINT approval_links_expiry_check CHECK (expires_at > created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_links');
    }
};
