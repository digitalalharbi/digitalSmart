<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a person keeps close, and what they were just looking at.
     *
     * At a hundred projects, navigation is the cost. Pins are what somebody chose to keep; recents are what
     * they touched, kept to the latest few and pruned on write so the table never grows with the agency.
     *
     * Both reference an entity by type and id with no foreign key, on purpose: a pin may point at a client, a
     * project or a campaign, and a polymorphic key cannot be one. The price is paid on read — a shortcut to
     * something deleted, or something the person has since lost access to, simply resolves to nothing.
     */
    public function up(): void
    {
        foreach (['user_pins', 'user_recents'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
                $table->string('entity_type');
                $table->uuid('entity_id');
                $table->timestampTz($name === 'user_pins' ? 'created_at' : 'viewed_at');
                $table->unique(['user_id', 'workspace_id', 'entity_type', 'entity_id']);
                $table->index(['user_id', 'workspace_id', $name === 'user_pins' ? 'created_at' : 'viewed_at']);
            });

            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_type_check CHECK (entity_type IN ('client','project','campaign'))");
        }

        /*
         * Internal tags: the agency's own vocabulary, not the platform's campaign names. "Ramadan" and "High
         * spend" are how an agency thinks about its work, and a campaign called "RMD_KSA_v3" does not carry
         * either. One tag can sit on many entities of three kinds.
         */
        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestampsTz();
            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('taggings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->timestampTz('created_at');
            $table->unique(['tag_id', 'entity_type', 'entity_id']);
            $table->index(['workspace_id', 'entity_type', 'entity_id']);
        });

        DB::statement("ALTER TABLE taggings ADD CONSTRAINT taggings_type_check CHECK (entity_type IN ('client','project','campaign'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('taggings');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('user_recents');
        Schema::dropIfExists('user_pins');
    }
};
