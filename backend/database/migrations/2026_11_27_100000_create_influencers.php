<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A person or account that promotes a client's offer, and the tracked links made for them.
 *
 * Belongs to one client, because a link does and an influencer's results are only meaningful against the offer they
 * promoted. `tracking_links.influencer_id` is nullable and cleared if the influencer is ever deleted rather than taking the
 * link with it: the link's clicks happened whoever it was made for. No fee, no cost: this product does not record what an
 * influencer was paid, so it never divides anything by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('handle', 120)->nullable();
            $table->string('platform', 32)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('active');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'client_id', 'name']);
            $table->index(['workspace_id', 'client_id', 'status']);
        });

        DB::statement("ALTER TABLE influencers ADD CONSTRAINT influencers_status_check CHECK (status IN ('active','archived'))");

        Schema::table('tracking_links', function (Blueprint $table): void {
            $table->foreignUuid('influencer_id')->nullable()->constrained('influencers')->nullOnDelete();
            $table->index(['workspace_id', 'influencer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'influencer_id']);
            $table->dropConstrainedForeignId('influencer_id');
        });

        Schema::dropIfExists('influencers');
    }
};
